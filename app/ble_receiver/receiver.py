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
  - Se connecte aussi aux télécommandes d'arbitre (nom Bluetooth « TEL… »), qui
    s'enregistrent toutes seules dans `telecommandes` dès qu'un scan les voit.
    Admin › Sabres › Télécommandes leur associe un arbitre : bouton rouge = +1 Aka,
    bleu = +1 Ao, les deux = annule, au nom de cet arbitre (table points_live,
    comme les boutons de la page d'arbitrage). Le récepteur renvoie à chaque
    télécommande l'état du combat pour son écran (voir etat_pour()).
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
UART_RX_CHAR_UUID   = "6e400002-b5a3-f393-e0a9-e50e24dcca9e"   # écriture vers une télécommande
PREFIXE_TELECOMMANDE = "TEL"       # nom Bluetooth des télécommandes d'arbitre (ex. TEL_2)
ETAT_TEL_S          = 1.0          # envoi de l'état du combat aux télécommandes
WIN_AFFICHE_S       = 120          # le vainqueur reste affiché 2 min après la fin
DOUBLE_TOUCH_MS     = 200          # fenêtre double-touche en millisecondes
RETRY_DELAY_S       = 2            # attente avant reconnexion (secondes)
CONNECT_TIMEOUT_S   = 20.0         # une connexion qui marche aboutit en 2 à 15 s : au-delà, autant réessayer
VU_FRAIS_S          = 8            # vu depuis plus longtemps : rechercher l'appareil avant de s'y connecter
RECHERCHE_S         = 6.0          # durée de cette recherche
# Délai de supervision du lien (unités de 10 ms) : 4 s au lieu de 420 ms par défaut.
# Le Wi-Fi du Pi (point d'accès) partage la puce radio avec le Bluetooth : avec
# 420 ms, quelques paquets perdus suffisent à couper un sabre ou une télécommande.
SUPERVISION_TIMEOUT = 400
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


# ── Télécommandes d'arbitre ────────────────────────────────────────────────────
# Protocole (une ligne par message, terminée par \n, sur le Nordic UART) :
#   télécommande → Pi : PT:R   PT:B   UNDO
#   Pi → télécommande : NOARB  IDLE  FIGHT  STOP  TIME:<s>  SCORE:<rouge>:<bleu>
#                       WIN:R|B|D  OK  ERR:<raison>

def creer_schema_telecommandes():
    """Même schéma que ensureTelecommandesSchema() côté PHP."""
    db_exec(
        """CREATE TABLE IF NOT EXISTS telecommandes (
               id INT AUTO_INCREMENT PRIMARY KEY,
               mac_address VARCHAR(17) DEFAULT NULL,
               nom VARCHAR(40) NOT NULL DEFAULT '' COMMENT 'Nom Bluetooth (ex. TEL_2)',
               user_id INT DEFAULT NULL COMMENT 'Arbitre au nom duquel elle donne les points',
               actif TINYINT(1) NOT NULL DEFAULT 1,
               last_seen DATETIME DEFAULT NULL,
               last_press_at DATETIME(3) DEFAULT NULL,
               created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
               UNIQUE KEY uniq_mac (mac_address),
               FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
           ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"""
    )
    # Première version (USB) : identifiée par son nom, sans adresse MAC
    db_exec("ALTER TABLE telecommandes ADD COLUMN IF NOT EXISTS mac_address VARCHAR(17) DEFAULT NULL AFTER id")
    db_exec("ALTER TABLE telecommandes DROP INDEX IF EXISTS nom")
    db_exec("ALTER TABLE telecommandes ADD UNIQUE INDEX IF NOT EXISTS uniq_mac (mac_address)")


def enregistrer_telecommandes(devices):
    """Toute télécommande vue par le scan apparaît dans Admin › Sabres › Télécommandes."""
    for d in devices:
        nom = (d.name or "").strip()
        if nom.upper().startswith(PREFIXE_TELECOMMANDE):
            db_exec(
                """INSERT INTO telecommandes (mac_address, nom) VALUES (%s, %s)
                   ON DUPLICATE KEY UPDATE nom = VALUES(nom)""",
                (d.address.upper(), nom[:40]),
            )


def load_telecommandes():
    return db_exec(
        "SELECT id, mac_address, nom FROM telecommandes WHERE actif = 1 AND mac_address IS NOT NULL",
        fetch="all",
    )


def arbitre_de(mac):
    """Arbitre associé à la télécommande (None si aucun ou désactivée)."""
    t = db_exec("SELECT user_id, actif FROM telecommandes WHERE mac_address = %s", (mac,), fetch="one")
    return t["user_id"] if t and t["actif"] else None


def combat_de_l_arbitre(user_id):
    """Combat en cours où arbitre ce compte, sinon le dernier terminé il y a peu."""
    colonnes = """c.id, c.statut, c.en_pause_depuis, c.score_joueur1, c.score_joueur2,
                  GREATEST(0, c.duree_secondes - TIMESTAMPDIFF(SECOND, c.temps_debut,
                           COALESCE(c.en_pause_depuis, NOW()))) AS restant"""
    combat = db_exec(
        f"""SELECT {colonnes} FROM combats c
            JOIN combat_arbitres ca ON ca.combat_id = c.id AND ca.user_id = %s
            WHERE c.statut = 'en_cours'
            ORDER BY c.temps_debut DESC LIMIT 1""",
        (user_id,), fetch="one",
    )
    if combat:
        return combat
    return db_exec(
        f"""SELECT {colonnes} FROM combats c
            JOIN combat_arbitres ca ON ca.combat_id = c.id AND ca.user_id = %s
            WHERE c.statut = 'termine' AND c.temps_fin > NOW() - INTERVAL %s SECOND
            ORDER BY c.temps_fin DESC LIMIT 1""",
        (user_id, WIN_AFFICHE_S), fetch="one",
    )


def totaux_par_arbitre(combat_id):
    """{user_id: (total Aka, total Ao)} pour chaque arbitre affecté au combat."""
    rows = db_exec(
        """SELECT ca.user_id,
                  COALESCE(SUM(CASE WHEN p.joueur_num = 1 THEN p.valeur END), 0) AS j1,
                  COALESCE(SUM(CASE WHEN p.joueur_num = 2 THEN p.valeur END), 0) AS j2
           FROM combat_arbitres ca
           LEFT JOIN points_live p ON p.combat_id = ca.combat_id
                                  AND p.arbitre_user_id = ca.user_id AND p.annule = 0
           WHERE ca.combat_id = %s
           GROUP BY ca.user_id""",
        (combat_id,), fetch="all",
    )
    return {r["user_id"]: (int(r["j1"]), int(r["j2"])) for r in rows}


def maj_score_combat(combat_id):
    """Score officiel = total le plus bas parmi les arbitres (includes/points.php)."""
    totaux = totaux_par_arbitre(combat_id)
    if totaux:
        db_exec(
            "UPDATE combats SET score_joueur1 = %s, score_joueur2 = %s WHERE id = %s",
            (min(t[0] for t in totaux.values()), min(t[1] for t in totaux.values()), combat_id),
        )


def appui_telecommande(mac, msg):
    """PT:R / PT:B / UNDO, mêmes contrôles que ajouterPointLive() / annulerDernierPoint().
    Retourne la réponse à renvoyer (OK ou ERR:<raison>)."""
    db_exec("UPDATE telecommandes SET last_press_at = NOW(3) WHERE mac_address = %s", (mac,))
    user_id = arbitre_de(mac)
    if user_id is None:
        return "ERR:SANS ARBITRE"
    combat = combat_de_l_arbitre(user_id)
    if not combat or combat["statut"] != "en_cours":
        return "ERR:PAS DE COMBAT"
    if combat["en_pause_depuis"]:
        return "ERR:PAUSE"
    if msg == "UNDO":
        dernier = db_exec(
            """SELECT id FROM points_live WHERE combat_id = %s AND arbitre_user_id = %s AND annule = 0
               ORDER BY id DESC LIMIT 1""",
            (combat["id"], user_id), fetch="one",
        )
        if not dernier:
            return "ERR:RIEN A ANNULER"
        db_exec("UPDATE points_live SET annule = 1 WHERE id = %s", (dernier["id"],))
    else:
        db_exec(
            "INSERT INTO points_live (combat_id, arbitre_user_id, joueur_num, valeur) VALUES (%s, %s, %s, 1)",
            (combat["id"], user_id, 1 if msg == "PT:R" else 2),
        )
    maj_score_combat(combat["id"])
    return "OK"


def etat_pour(mac):
    """Lignes d'état à envoyer à une télécommande."""
    db_exec("UPDATE telecommandes SET last_seen = NOW() WHERE mac_address = %s", (mac,))
    user_id = arbitre_de(mac)
    if user_id is None:
        return ["NOARB"]
    combat = combat_de_l_arbitre(user_id)
    if not combat:
        return ["IDLE"]
    mes = totaux_par_arbitre(combat["id"]).get(user_id, (0, 0))
    if combat["statut"] == "termine":
        s1, s2 = float(combat["score_joueur1"]), float(combat["score_joueur2"])
        return ["WIN:" + ("R" if s1 > s2 else "B" if s2 > s1 else "D"), f"SCORE:{mes[0]}:{mes[1]}"]
    return ["STOP" if combat["en_pause_depuis"] else "FIGHT",
            f"SCORE:{mes[0]}:{mes[1]}", f"TIME:{int(combat['restant'] or 0)}"]


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
            enregistrer_telecommandes(devices)
            signaler_etat(f"Actif : {len(connectes)}/{len(attendus)} sabre(s) / télécommande(s) connecté(s)", len(devices))
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

    def on_notification(sender, data: bytearray):
        msg = data.decode("utf-8", errors="ignore").strip().upper()
        if msg.startswith("HIT"):
            log.info("HIT reçu de %s", nom)
            asyncio.ensure_future(process_hit(sabre))
        else:
            log.info("Message de %s (pas une touche) : %r", nom, bytes(data))

    async def pendant_connexion(client):
        while client.is_connected:
            update_sabre_last_seen(mac)
            await asyncio.sleep(2)

    await maintenir_connexion(mac, nom, on_notification, pendant_connexion)


async def connect_telecommande(tel: dict):
    """Tâche asyncio : maintient la connexion BLE avec une télécommande d'arbitre,
    donne ses points et lui envoie l'état du combat chaque seconde."""
    mac = tel["mac_address"].upper()
    nom = tel["nom"] or mac
    recus: asyncio.Queue = asyncio.Queue()
    tampon = bytearray()

    def on_notification(sender, data: bytearray):
        tampon.extend(data)          # un message peut arriver en plusieurs morceaux
        while b"\n" in tampon:
            i = tampon.index(b"\n")
            ligne = tampon[:i].decode("utf-8", errors="ignore").strip().upper()
            del tampon[:i + 1]
            if ligne:
                recus.put_nowait(ligne)
        del tampon[:-256]

    async def ecrire(client, ligne):
        await client.write_gatt_char(UART_RX_CHAR_UUID, (ligne + "\n").encode(), response=True)

    async def pendant_connexion(client):
        envoye = {}                  # dernière ligne envoyée par sorte (ETAT, SCORE, TIME)
        prochain_etat = 0.0
        while client.is_connected:
            try:
                msg = await asyncio.wait_for(recus.get(), timeout=ETAT_TEL_S)
            except asyncio.TimeoutError:
                msg = None
            if msg in ("PT:R", "PT:B", "UNDO"):
                reponse = appui_telecommande(mac, msg)
                log.info("%s : %s → %s", nom, msg, reponse)
                await ecrire(client, reponse)
                prochain_etat = 0.0  # score à jour tout de suite
            elif msg:
                log.info("Message de %s (inconnu) : %r", nom, msg)
            if time.time() >= prochain_etat:
                prochain_etat = time.time() + ETAT_TEL_S
                for ligne in etat_pour(mac):
                    sorte = ligne.split(":")[0] if ligne.startswith(("SCORE", "TIME")) else "ETAT"
                    if envoye.get(sorte) == ligne:
                        continue
                    if sorte == "ETAT":
                        envoye.clear()   # nouvel état : la télécommande remet son écran à zéro
                    await ecrire(client, ligne)
                    envoye[sorte] = ligne

    await maintenir_connexion(mac, nom, on_notification, pendant_connexion)


async def couper_lien_orphelin(mac, nom):
    """Un redémarrage du récepteur ne coupe pas les liens ouverts par BlueZ (sur l'hôte) :
    l'appareil se croit encore connecté et ne se montre plus aux scans. On le libère."""
    try:
        proc = await asyncio.create_subprocess_exec(
            "bluetoothctl", "disconnect", mac,
            stdout=asyncio.subprocess.PIPE, stderr=asyncio.subprocess.STDOUT,
        )
        sortie, _ = await asyncio.wait_for(proc.communicate(), timeout=10)
        if b"Successful disconnected" in sortie:
            log.info("Ancien lien Bluetooth avec %s coupé : il va se reconnecter", nom)
    except (OSError, asyncio.TimeoutError) as e:
        log.warning("Vérification du lien de %s impossible : %r", nom, e)


async def maintenir_connexion(mac, nom, on_notification, pendant_connexion):
    """Connecte l'appareil dès qu'un scan l'a vu, s'abonne à ses messages, appelle
    pendant_connexion(client) tant qu'il reste connecté, puis recommence."""
    deja_signale = False
    await couper_lien_orphelin(mac, nom)

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
                # Après une longue attente du verrou, BlueZ a pu oublier l'appareil
                # (« device not found ») : on le recherche juste avant de se connecter.
                vu_le = vus[mac][1] if mac in vus else 0
                if time.time() - vu_le > VU_FRAIS_S:
                    appareil = await BleakScanner.find_device_by_address(mac, timeout=RECHERCHE_S)
                    if appareil is None:
                        vus.pop(mac, None)
                        continue
                    vus[mac] = (appareil, time.time())
                log.info("Connexion à %s…", nom)
                client = BleakClient(appareil, timeout=CONNECT_TIMEOUT_S, disconnected_callback=on_disconnect)
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
                await pendant_connexion(client)
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
            telecommandes = load_telecommandes()
            # clé de tâche : ("sabre", id) ou ("tel", id)
            appareils = {("sabre", s["id"]): s for s in sabres} | {("tel", t["id"]): t for t in telecommandes}
            attendus.clear()
            attendus.update(a["mac_address"].upper() for a in appareils.values())

            for cle, a in appareils.items():
                if cle not in running_tasks:
                    sorte = "Sabre" if cle[0] == "sabre" else "Télécommande"
                    log.info("%s suivi(e) : %s (%s)", sorte, a["nom"] or a["mac_address"], a["mac_address"])
                    tache = connect_sabre(a) if cle[0] == "sabre" else connect_telecommande(a)
                    running_tasks[cle] = asyncio.create_task(tache)

            for cle in list(running_tasks):
                if cle not in appareils:
                    log.info("%s %d désactivé(e), arrêt de la tâche", cle[0], cle[1])
                    running_tasks[cle].cancel()
                    del running_tasks[cle]

        except Exception as e:
            log.error("Erreur rechargement sabres : %s", e)
        await asyncio.sleep(RELOAD_SABRES_S)


# ── Point d'entrée ─────────────────────────────────────────────────────────────

def regler_parametres_bluetooth():
    """Allonge le délai de supervision des connexions (réglage du noyau, via debugfs :
    le conteneur est privilégié). Sans effet bloquant si c'est impossible."""
    dossier = "/sys/kernel/debug/bluetooth/hci0"
    try:
        if not os.path.isdir(dossier):
            os.makedirs("/sys/kernel/debug", exist_ok=True)
            os.system("mount -t debugfs none /sys/kernel/debug 2>/dev/null")
        with open(f"{dossier}/supervision_timeout", "w") as f:
            f.write(str(SUPERVISION_TIMEOUT))
        log.info("Délai de supervision Bluetooth : %d ms", SUPERVISION_TIMEOUT * 10)
    except OSError as e:
        log.warning("Délai de supervision Bluetooth non modifié (%s) : connexions plus fragiles", e)


async def main():
    log.info("=== Daemon BLE Sabre Laser démarrage ===")
    regler_parametres_bluetooth()
    log.info("DB : %s@%s:%d/%s", DB_USER, DB_HOST, DB_PORT, DB_NAME)
    log.info("Attente de la base de données…")
    await asyncio.get_running_loop().run_in_executor(None, attendre_db)
    log.info("Base de données prête")
    creer_schema_telecommandes()
    signaler_etat("Démarrage du récepteur…")

    running_tasks: dict = {}
    await asyncio.gather(boucle_scan(), sabre_manager(running_tasks))


if __name__ == "__main__":
    asyncio.run(main())
