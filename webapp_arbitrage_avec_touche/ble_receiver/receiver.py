#!/usr/bin/env python3
"""
receiver.py — Daemon BLE pour sabres laser.

Rôle :
  - Se connecte simultanément à tous les sabres actifs enregistrés en base.
  - Détecte les touches (message "HIT" sur UART Nordic NUS).
  - Applique la règle double-touche (fenêtre 200 ms) :
      • Un seul sabre touche → type='touche'
      • Deux sabres touchent dans la fenêtre → type='double' pour les deux
  - Écrit le résultat dans la table `touchers` de MariaDB.
  - Met à jour last_hit_at sur chaque HIT (même sans combat) pour l'affichage admin.
  - Scanne les appareils BLE alentour et met à jour `ble_scan_cache`
    (visible dans Admin → Sabres).
  - Reconnecte automatiquement un sabre déconnecté.
  - Signale son état dans `ble_recepteur` (Admin → Sabres affiche « Récepteur actif »,
    « arrêté » ou l'erreur rencontrée, ex. adaptateur Bluetooth introuvable).

Un seul scan Bluetooth à la fois : BlueZ refuse deux scans simultanés
(« Operation already in progress ») et une connexion pendant un scan échoue
souvent sur la puce intégrée du Raspberry. Scans et connexions passent donc
par le même verrou ; les sabres se connectent à partir du dernier scan.

Variables d'environnement (toutes optionnelles) :
  DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS
"""

import asyncio
import os
import time
import logging

import pymysql
import pymysql.cursors
from bleak import BleakClient, BleakScanner

# ── Configuration ──────────────────────────────────────────────────────────────
UART_TX_CHAR_UUID   = "6e400003-b5a3-f393-e0a9-e50e24dcca9e"
DOUBLE_TOUCH_MS     = 200          # fenêtre double-touche en millisecondes
RETRY_DELAY_S       = 5            # attente avant reconnexion (secondes)
RELOAD_SABRES_S     = 15           # rechargement liste sabres depuis DB
SCAN_DURATION_S     = 5.0          # durée d'un scan BLE
SCAN_PAUSE_S        = 2            # pause entre deux scans si un sabre attend
SCAN_PAUSE_LONG_S   = 25           # pause entre deux scans si tous sont connectés
VU_RECEMMENT_S      = 30           # un sabre vu dans ce délai peut être connecté

DB_HOST = os.getenv("DB_HOST", "127.0.0.1")
DB_PORT = int(os.getenv("DB_PORT", "3306"))
DB_NAME = os.getenv("DB_NAME", "sabre_arbitrage")
DB_USER = os.getenv("DB_USER", "arbitrage_user")
DB_PASS = os.getenv("DB_PASS", "arbitrage_pass")

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger("sabre_ble")

# ── Connexion DB ───────────────────────────────────────────────────────────────

def _offset_serveur():
    """Décalage horaire du serveur (ex. '+02:00'), identique à celui utilisé
    par l'application web : NOW() donne ainsi la même heure partout."""
    z = time.strftime("%z", time.localtime())  # ex. +0200 (variable TZ du conteneur)
    return f"{z[:3]}:{z[3:]}" if len(z) == 5 else "+00:00"


def db_connect():
    return pymysql.connect(
        init_command=f"SET time_zone = '{_offset_serveur()}'",
        host=DB_HOST, port=DB_PORT,
        user=DB_USER, password=DB_PASS,
        database=DB_NAME,
        charset="utf8mb4",
        cursorclass=pymysql.cursors.DictCursor,
        autocommit=True,
        connect_timeout=10,
    )


def db_exec(sql, params=(), fetch=None):
    conn = db_connect()
    try:
        with conn.cursor() as cur:
            cur.execute(sql, params)
            if fetch == "one":
                return cur.fetchone()
            if fetch == "all":
                return cur.fetchall()
    finally:
        conn.close()


def attendre_db():
    """Attend la base indéfiniment (elle peut démarrer après le récepteur)."""
    essai = 0
    while True:
        try:
            db_exec("SELECT 1")
            return
        except Exception as e:
            essai += 1
            if essai == 1 or essai % 10 == 0:
                log.warning("Base de données injoignable (%s@%s:%d) : %s — nouvel essai…", DB_USER, DB_HOST, DB_PORT, e)
            time.sleep(3)


# ── Helpers DB ─────────────────────────────────────────────────────────────────

def load_sabres():
    """Retourne la liste des sabres actifs {id, mac_address, nom, joueur_id}."""
    return db_exec("SELECT id, mac_address, nom, joueur_id FROM sabres WHERE actif = 1", fetch="all")


def find_active_combat(joueur_id):
    """Retourne le combat 'en_cours' auquel participe ce joueur, ou None."""
    if joueur_id is None:
        return None
    return db_exec(
        """SELECT id, joueur1_id, joueur2_id
           FROM combats
           WHERE statut = 'en_cours'
             AND (joueur1_id = %s OR joueur2_id = %s)
           LIMIT 1""",
        (joueur_id, joueur_id), fetch="one",
    )


def insert_toucher(combat_id, sabre_id, joueur_id, timestamp_ms, type_):
    db_exec(
        """INSERT INTO touchers
           (combat_id, sabre_id, joueur_id, timestamp_ms, type)
           VALUES (%s, %s, %s, %s, %s)""",
        (combat_id, sabre_id, joueur_id, timestamp_ms, type_),
    )


def update_sabre_last_seen(mac):
    db_exec("UPDATE sabres SET last_seen = NOW() WHERE mac_address = %s", (mac,))


def update_sabre_last_hit(mac):
    """Enregistre le timestamp du dernier HIT sur le sabre (même sans combat actif)."""
    db_exec("UPDATE sabres SET last_hit_at = NOW() WHERE mac_address = %s", (mac,))


def update_scan_cache(devices):
    """Insère ou met à jour les appareils BLE découverts dans ble_scan_cache."""
    if not devices:
        return
    conn = db_connect()
    try:
        with conn.cursor() as cur:
            for d in devices:
                cur.execute(
                    """INSERT INTO ble_scan_cache (mac_address, nom_ble, last_scanned)
                       VALUES (%s, %s, NOW())
                       ON DUPLICATE KEY UPDATE
                           nom_ble      = VALUES(nom_ble),
                           last_scanned = NOW()""",
                    (d.address.upper(), (d.name or "")[:100]),
                )
    finally:
        conn.close()


def signaler_etat(message, nb_appareils=None):
    """État du récepteur, affiché dans Admin › Sabres (jamais bloquant)."""
    try:
        db_exec(
            """CREATE TABLE IF NOT EXISTS ble_recepteur (
                   id TINYINT NOT NULL PRIMARY KEY,
                   maj DATETIME NOT NULL,
                   message VARCHAR(255) NOT NULL DEFAULT '',
                   nb_appareils INT DEFAULT NULL
               ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""
        )
        db_exec(
            """INSERT INTO ble_recepteur (id, maj, message, nb_appareils) VALUES (1, NOW(), %s, %s)
               ON DUPLICATE KEY UPDATE maj = NOW(), message = VALUES(message),
                   nb_appareils = COALESCE(VALUES(nb_appareils), nb_appareils)""",
            (message[:255], nb_appareils),
        )
    except Exception as e:
        log.warning("État du récepteur non enregistré : %s", e)


# ── Gestion des double-touches ─────────────────────────────────────────────────
# pending_hits : { combat_id -> {'ts_ms': int, 'sabre_id': int, 'joueur_id': int} }
pending_hits: dict = {}
pending_lock = asyncio.Lock()


async def process_hit(sabre: dict):
    """
    Appelé lors d'un HIT reçu d'un sabre.
    Cherche le combat en cours, applique la logique double-touche.
    """
    sabre_id   = sabre["id"]
    joueur_id  = sabre["joueur_id"]
    nom        = sabre["nom"] or sabre["mac_address"]

    # Toujours mettre à jour last_hit_at (visible dans admin/sabres même sans combat)
    update_sabre_last_hit(sabre["mac_address"])

    combat = find_active_combat(joueur_id)
    if combat is None:
        log.info("HIT de %s ignoré : aucun combat en cours pour ce joueur", nom)
        return

    combat_id  = combat["id"]
    ts_ms      = int(time.time() * 1000)

    async with pending_lock:
        existing = pending_hits.get(combat_id)

        if existing and (ts_ms - existing["ts_ms"]) <= DOUBLE_TOUCH_MS:
            # ── Double touche ──────────────────────────────────────────
            log.info(
                "DOUBLE TOUCHE combat %d : %s + sabre %d",
                combat_id, nom, existing["sabre_id"],
            )
            # Annule le pending et enregistre les deux comme 'double'
            del pending_hits[combat_id]
            insert_toucher(combat_id, existing["sabre_id"], existing["joueur_id"],
                           existing["ts_ms"], "double")
            insert_toucher(combat_id, sabre_id, joueur_id, ts_ms, "double")
        else:
            # ── Nouvelle touche en attente ─────────────────────────────
            pending_hits[combat_id] = {
                "ts_ms":    ts_ms,
                "sabre_id": sabre_id,
                "joueur_id": joueur_id,
            }
            # Planifie la validation après la fenêtre
            asyncio.get_running_loop().call_later(
                DOUBLE_TOUCH_MS / 1000 + 0.01,
                lambda cid=combat_id, sid=sabre_id, jid=joueur_id, ts=ts_ms:
                    asyncio.ensure_future(validate_hit(cid, sid, jid, ts)),
            )


async def validate_hit(combat_id, sabre_id, joueur_id, ts_ms):
    """Valide une touche si elle n'a pas été annulée par une double-touche."""
    async with pending_lock:
        p = pending_hits.get(combat_id)
        if p and p["ts_ms"] == ts_ms and p["sabre_id"] == sabre_id:
            del pending_hits[combat_id]
            log.info("TOUCHÉ combat %d sabre %d joueur %s", combat_id, sabre_id, joueur_id)
            insert_toucher(combat_id, sabre_id, joueur_id, ts_ms, "touche")


# ── Radio Bluetooth partagée ───────────────────────────────────────────────────
radio_lock = asyncio.Lock()          # un seul scan OU une seule connexion à la fois
vus: dict = {}                       # MAC -> (BLEDevice, horodatage du dernier scan)
connectes: set = set()               # MAC des sabres connectés
attendus: set = set()                # MAC des sabres actifs (en base)


def appareil_recent(mac):
    v = vus.get(mac)
    return v[0] if v and time.time() - v[1] < VU_RECEMMENT_S else None


async def boucle_scan():
    """Scanne en continu tant qu'un sabre attend sa connexion, sinon de temps en temps."""
    while True:
        try:
            async with radio_lock:
                devices = await BleakScanner.discover(timeout=SCAN_DURATION_S)
            maintenant = time.time()
            for d in devices:
                vus[d.address.upper()] = (d, maintenant)
            trouves = sorted(m for m in attendus if m in vus and maintenant - vus[m][1] < 1)
            log.info("Scan : %d appareil(s) Bluetooth%s", len(devices),
                     f", sabres visibles : {', '.join(trouves)}" if trouves else "")
            update_scan_cache(devices)
            signaler_etat(f"Actif : {len(connectes)}/{len(attendus)} sabre(s) connecté(s)", len(devices))
        except Exception as e:
            msg = str(e) or e.__class__.__name__
            log.error("Erreur scan BLE : %s", msg)
            if isinstance(e, FileNotFoundError):
                msg = "bus D-Bus de l'hôte introuvable (/var/run/dbus) : ce récepteur doit tourner sur le Raspberry (Linux + BlueZ)"
            elif "No Bluetooth adapters" in msg or "org.bluez" in msg or "dbus" in msg.lower():
                msg = "Adaptateur Bluetooth inaccessible (" + msg[:120] + ") : service bluetooth du Raspberry démarré ?"
            signaler_etat("Erreur : " + msg)
            await asyncio.sleep(10)
        attente = SCAN_PAUSE_S if attendus - connectes else SCAN_PAUSE_LONG_S
        await asyncio.sleep(attente)


# ── Connexion BLE à un sabre ───────────────────────────────────────────────────

async def abonner_touches(client, nom, callback):
    """
    S'abonne à la sortie série Nordic UART (NUS) du sabre. Si le sabre ne l'a pas,
    s'abonne à TOUTES ses caractéristiques « notify / indicate » : le journal liste
    alors ce que le sabre propose (utile pour régler UART_TX_CHAR_UUID).
    """
    services = client.services
    for s in services:
        for c in s.characteristics:
            log.info("  %s : service %s · caractéristique %s (%s)", nom, s.uuid, c.uuid, ",".join(c.properties))
    if services.get_characteristic(UART_TX_CHAR_UUID):
        await client.start_notify(UART_TX_CHAR_UUID, callback)
        log.info("Abonné à la sortie série (Nordic UART) de %s", nom)
        return
    abonnees = 0
    for s in services:
        for c in s.characteristics:
            if "notify" in c.properties or "indicate" in c.properties:
                try:
                    await client.start_notify(c, callback)
                    abonnees += 1
                except Exception as e:
                    log.warning("Abonnement impossible à %s : %r", c.uuid, e)
    if not abonnees:
        raise RuntimeError(f"{nom} n'a aucune caractéristique « notify » : impossible de recevoir ses touches")
    log.warning("%s n'a pas la sortie Nordic UART attendue : abonné à %d autre(s) caractéristique(s)", nom, abonnees)


async def connect_sabre(sabre: dict):
    """Tâche asyncio : maintient la connexion BLE avec un sabre et traite ses HIT."""
    mac = sabre["mac_address"].upper()
    nom = sabre["nom"] or mac
    deja_signale = False

    def on_notification(sender, data: bytearray):
        msg = data.decode("utf-8", errors="ignore").strip().upper()
        if msg.startswith("HIT"):
            log.info("HIT reçu de %s", nom)
            asyncio.ensure_future(process_hit(sabre))
        else:
            log.info("Message de %s (pas une touche) : %r", nom, bytes(data))

    def on_disconnect(_client):
        connectes.discard(mac)

    while True:
        try:
            appareil = appareil_recent(mac)
            if appareil is None:
                if not deja_signale:
                    log.info("En attente de %s (%s) : allumé et à portée ?", nom, mac)
                    deja_signale = True
                await asyncio.sleep(2)
                continue
            deja_signale = False

            async with radio_lock:      # pas de scan pendant la connexion
                log.info("Connexion à %s…", nom)
                client = BleakClient(appareil, timeout=20.0, disconnected_callback=on_disconnect)
                await client.connect()
                log.info("Lien Bluetooth établi avec %s, abonnement aux touches…", nom)
                try:
                    await abonner_touches(client, nom, on_notification)
                except Exception:
                    await client.disconnect()
                    raise

            log.info("✓ Connecté à %s", nom)
            connectes.add(mac)
            try:
                while client.is_connected:
                    update_sabre_last_seen(mac)
                    await asyncio.sleep(2)
            finally:
                connectes.discard(mac)
                if client.is_connected:
                    await client.disconnect()
            log.warning("%s déconnecté", nom)

        except asyncio.CancelledError:
            raise
        except Exception as e:
            # repr : certaines erreurs Bluetooth ont un message vide
            log.error("Erreur connexion %s : %r", nom, e)
            vus.pop(mac, None)          # attendre un nouveau scan avant de réessayer

        await asyncio.sleep(RETRY_DELAY_S)


# ── Rechargement dynamique des sabres ─────────────────────────────────────────

async def sabre_manager(running_tasks: dict):
    """
    Recharge la liste des sabres depuis la DB toutes les N secondes.
    Lance une tâche asyncio pour chaque nouveau sabre, annule celles
    dont le sabre a été désactivé.
    """
    while True:
        try:
            sabres = load_sabres()
            ids_actifs = {s["id"] for s in sabres}
            attendus.clear()
            attendus.update(s["mac_address"].upper() for s in sabres)

            for s in sabres:
                if s["id"] not in running_tasks:
                    log.info("Sabre suivi : %s (%s)", s["nom"] or s["mac_address"], s["mac_address"])
                    running_tasks[s["id"]] = asyncio.create_task(connect_sabre(s))

            for sid in list(running_tasks):
                if sid not in ids_actifs:
                    log.info("Sabre %d désactivé, arrêt de la tâche", sid)
                    running_tasks[sid].cancel()
                    del running_tasks[sid]

        except Exception as e:
            log.error("Erreur rechargement sabres : %s", e)
        await asyncio.sleep(RELOAD_SABRES_S)


# ── Point d'entrée ─────────────────────────────────────────────────────────────

async def main():
    log.info("=== Daemon BLE Sabre Laser démarrage ===")
    log.info("DB : %s@%s:%d/%s", DB_USER, DB_HOST, DB_PORT, DB_NAME)
    log.info("Attente de la base de données…")
    await asyncio.get_running_loop().run_in_executor(None, attendre_db)
    log.info("Base de données prête")
    signaler_etat("Démarrage du récepteur…")

    running_tasks: dict = {}
    await asyncio.gather(boucle_scan(), sabre_manager(running_tasks))


if __name__ == "__main__":
    asyncio.run(main())
