#!/usr/bin/env python3
"""
sabre-portail : portail de connexion du Wi-Fi du tournoi (Raspberry Pi).

Un appareil qui rejoint le Wi-Fi n'a accès à RIEN tant qu'il ne s'est pas
identifié : toute page web qu'il ouvre (et les tests de connexion automatiques
des téléphones) arrive ici, ce qui fait apparaître la fenêtre « Se connecter au
réseau ». Après identifiant + mot de passe valides (comptes Admin › Wi-Fi de
l'app, ou comptes de secours du Pi), son adresse IP + MAC est ajoutée au
pare-feu (set nftables « autorises ») pour SESSION_HEURES heures.

  - Comptes : /var/lib/sabre-wifi/comptes-app (tenu à jour par « sabre-wifi sync »)
              /etc/sabre/wifi-secours
              format « identifiant:hachage NT » (le même que pour l'app)
  - Sessions : /var/lib/sabre-wifi/sessions.json (survivent à un redémarrage)
  - Un compte désactivé / supprimé dans l'app est déconnecté en ~15 s.
  - Apparence (nom, couleurs, logo) : celle de l'app (Admin › Apparence), relue
    toutes les 15 s ; nom du Wi-Fi relu dans /etc/sabre/ap.env (Admin › Wi-Fi).

Lancé par systemd (sabre-portail.service), en root (il modifie le pare-feu).
Aucune dépendance : bibliothèque standard de Python uniquement.
"""
import base64
import hmac
import html
import json
import os
import re
import socket
import struct
import subprocess
import sys
import threading
import time
import urllib.parse
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

# ── Configuration ────────────────────────────────────────────────────────────
def lire_conf(chemin="/etc/sabre/ap.env"):
    conf = {}
    try:
        with open(chemin, encoding="utf-8") as f:
            for ligne in f:
                m = re.match(r'^([A-Z_]+)="?(.*?)"?\s*$', ligne.strip())
                if m:
                    conf[m.group(1)] = m.group(2)
    except OSError:
        pass
    return conf

CONF = lire_conf()
IFACE = CONF.get("IFACE", "wlan0")
IP_AP = CONF.get("IP_AP", "10.42.0.1")
DOMAINE = CONF.get("DOMAINE", "sabre.lan")
PORT = int(CONF.get("PORTAIL_PORT", "8082"))
SESSION_S = int(float(CONF.get("SESSION_HEURES", "12")) * 3600)
PROJET = CONF.get("PROJET", "")
SSID = CONF.get("SSID", "Sabre-Arbitrage")

FICHIERS_COMPTES = ["/etc/sabre/wifi-secours", "/var/lib/sabre-wifi/comptes-app"]
FICHIER_SESSIONS = "/var/lib/sabre-wifi/sessions.json"
FICHIER_DECONNEXIONS = "/var/lib/sabre-wifi/a-deconnecter"   # écrit par « sabre-wifi deconnecter"
RE_LIGNE = re.compile(r"^[a-z0-9][a-z0-9._-]{2,31}:[0-9a-f]{32}$")
RE_MAC = re.compile(r"^[0-9a-f]{2}(:[0-9a-f]{2}){5}$")
NFT = ["nft"]
TABLE = ["inet", "sabre_ap", "autorises"]

verrou = threading.Lock()
sessions = {}          # ip -> {"mac", "user", "expire"}
echecs = {}            # ip -> [horodatages des échecs récents]


def log(*m):
    print(*m, flush=True)


# ── Hachage NT (MD4 de l'UTF-16LE), identique à l'app et à sabre-wifi ───────
def md4(msg: bytes) -> str:
    M = 0xFFFFFFFF
    rol = lambda x, n: ((x << n) | (x >> (32 - n))) & M
    F = lambda x, y, z: (x & y) | (~x & z)
    G = lambda x, y, z: (x & y) | (x & z) | (y & z)
    H = lambda x, y, z: x ^ y ^ z
    n = len(msg) * 8
    msg += b"\x80" + b"\x00" * ((55 - len(msg)) % 64) + struct.pack("<Q", n)
    h = [0x67452301, 0xEFCDAB89, 0x98BADCFE, 0x10325476]
    tours = [(F, 0, range(16), (3, 7, 11, 19)),
             (G, 0x5A827999, (0, 4, 8, 12, 1, 5, 9, 13, 2, 6, 10, 14, 3, 7, 11, 15), (3, 5, 9, 13)),
             (H, 0x6ED9EBA1, (0, 8, 4, 12, 2, 10, 6, 14, 1, 9, 5, 13, 3, 11, 7, 15), (3, 9, 11, 15))]
    for i in range(0, len(msg), 64):
        X = struct.unpack("<16I", msg[i:i + 64])
        a, b, c, d = h
        for f, k, ordre, decal in tours:
            for j, idx in enumerate(ordre):
                a = rol((a + f(b, c, d) + X[idx] + k) & M, decal[j % 4])
                a, b, c, d = d, a, b, c
        h = [(x + y) & M for x, y in zip(h, (a, b, c, d))]
    return struct.pack("<4I", *h).hex()


def comptes():
    """identifiant -> hachage NT (les comptes de secours passent en premier)."""
    res = {}
    for chemin in FICHIERS_COMPTES:
        try:
            with open(chemin, encoding="utf-8") as f:
                for ligne in f:
                    ligne = ligne.strip()
                    if RE_LIGNE.match(ligne):
                        ident, h = ligne.split(":")
                        res.setdefault(ident, h)
        except OSError:
            pass
    return res


def verifier(ident: str, mdp: str) -> bool:
    attendu = comptes().get(ident)
    calcule = md4(mdp.encode("utf-16-le"))
    # Comparaison à temps constant, même pour un identifiant inconnu
    return hmac.compare_digest(calcule, attendu or "0" * 32) and attendu is not None


# ── Pare-feu ─────────────────────────────────────────────────────────────────
def nft_autoriser(ip, mac, secondes):
    r = subprocess.run(NFT + ["add", "element", *TABLE, "{ %s . %s timeout %ds }" % (ip, mac, max(60, secondes))],
                       capture_output=True, text=True)
    if r.returncode != 0:
        log("nft add :", r.stderr.strip())
    return r.returncode == 0


def nft_retirer(ip, mac):
    subprocess.run(NFT + ["delete", "element", *TABLE, "{ %s . %s }" % (ip, mac)], capture_output=True)


def mac_de(ip):
    """Adresse MAC d'un client du Wi-Fi (table ARP du Pi)."""
    try:
        with open("/proc/net/arp", encoding="ascii") as f:
            for ligne in f.readlines()[1:]:
                p = ligne.split()
                if len(p) >= 6 and p[0] == ip and p[5] == IFACE and p[2] != "0x0":
                    mac = p[3].lower()
                    return mac if RE_MAC.match(mac) else None
    except OSError:
        pass
    return None


def sauver():
    tmp = FICHIER_SESSIONS + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(sessions, f)
    os.chmod(tmp, 0o600)
    os.replace(tmp, FICHIER_SESSIONS)


def charger():
    global sessions
    try:
        with open(FICHIER_SESSIONS, encoding="utf-8") as f:
            sessions = {ip: s for ip, s in json.load(f).items() if isinstance(s, dict)}
    except (OSError, ValueError):
        sessions = {}


def deconnexions_demandees():
    """Demandes de « sudo sabre-wifi deconnecter <ip|identifiant|tous> »."""
    try:
        with open(FICHIER_DECONNEXIONS, encoding="utf-8") as f:
            cibles = {l.strip().lower() for l in f if l.strip()}
        os.remove(FICHIER_DECONNEXIONS)
        return cibles
    except OSError:
        return set()


def entretien():
    """Toutes les 5 s : expire les sessions, déconnecte les comptes supprimés /
    désactivés ou demandés, et remet les autorisations dans le pare-feu (s'il a été rechargé)."""
    global SSID
    tour = 0
    while True:
        try:
            cibles = deconnexions_demandees()
            with verrou:
                for ip, s in list(sessions.items()):
                    if "tous" in cibles or ip in cibles or s["user"] in cibles or s["mac"] in cibles:
                        nft_retirer(ip, s["mac"])
                        del sessions[ip]
                        log("déconnecté à la demande :", s["user"], ip)
                if cibles:
                    sauver()
            tour += 1
            if tour % 3 != 1:     # le reste toutes les 15 s seulement (et dès le démarrage)
                time.sleep(5)
                continue
            SSID = lire_conf().get("SSID", SSID)      # nom du Wi-Fi changé dans Admin › Wi-Fi
            actualiser_apparence()
            valides = comptes()
            maintenant = time.time()
            with verrou:
                for ip, s in list(sessions.items()):
                    if s["expire"] <= maintenant or s["user"] not in valides:
                        nft_retirer(ip, s["mac"])
                        del sessions[ip]
                        log("déconnecté :", s["user"], ip, "(session expirée)" if s["expire"] <= maintenant else "(compte supprimé ou désactivé)")
                    else:
                        nft_autoriser(ip, s["mac"], int(s["expire"] - maintenant))
                sauver()
                for ip in [ip for ip, t in echecs.items() if not [x for x in t if x > maintenant - 300]]:
                    del echecs[ip]
        except Exception as e:  # le portail ne doit jamais s'arrêter
            log("entretien :", e)
        time.sleep(5)


# ── Apparence : la même que l'application (Admin › Apparence) ────────────────
# Relue toutes les 15 s sur l'app (portail_apparence.php), gardée dans un fichier
# pour que le portail garde le bon logo / les bonnes couleurs même si l'app est arrêtée.
FICHIER_APPARENCE = "/var/lib/sabre-wifi/apparence.json"
RE_COULEUR = re.compile(r"^#[0-9a-f]{6}$")
COULEURS_DEFAUT = {   # thème « Basique » de l'app
    "mode": "sombre", "fond": "#0b0d10", "surface": "#121519", "surface2": "#181c21", "bordure": "#252b33",
    "texte": "#eceef1", "texte2": "#a7afba", "texte3": "#848d99", "primaire": "#eceef1", "surPrimaire": "#0b0d10",
    "accent": "#3ee0b0", "barre": "#08090b", "danger": "#ff6b6b",
}
APPARENCE = {"nom": "Sabre Laser Arbitrage", "couleurs": dict(COULEURS_DEFAUT), "logo": None}


def lire_env_projet():
    """APP_PORT et BASE_URL du fichier .env du projet (pour joindre l'app en local)."""
    env = {}
    try:
        with open(os.path.join(PROJET, ".env"), encoding="utf-8") as f:
            for ligne in f:
                m = re.match(r'^(APP_PORT|BASE_URL)=["\']?([^"\'\s]*)', ligne.strip())
                if m:
                    env[m.group(1)] = m.group(2)
    except OSError:
        pass
    port = env.get("APP_PORT", "8080")
    base = "/" + env.get("BASE_URL", "/").strip("/")
    return (port if port.isdigit() else "8080"), (base.rstrip("/") + "/")


def valider_apparence(d):
    """Ne garde que des valeurs sûres (couleurs #rrggbb, logo PNG raisonnable)."""
    if not isinstance(d, dict):
        return None
    nom = d.get("nom")
    nom = nom.strip()[:60] if isinstance(nom, str) and nom.strip() else "Sabre Laser Arbitrage"
    couleurs = dict(COULEURS_DEFAUT)
    src = d.get("couleurs") if isinstance(d.get("couleurs"), dict) else {}
    for k in COULEURS_DEFAUT:
        v = src.get(k)
        if k == "mode":
            couleurs[k] = "clair" if v == "clair" else "sombre"
        elif isinstance(v, str) and RE_COULEUR.match(v.lower()):
            couleurs[k] = v.lower()
    logo = d.get("logo")
    if isinstance(logo, str) and 0 < len(logo) < 400_000 and re.fullmatch(r"[A-Za-z0-9+/=]+", logo):
        try:
            if not base64.b64decode(logo, validate=True).startswith(b"\x89PNG\r\n\x1a\n"):
                logo = None
        except ValueError:
            logo = None
    else:
        logo = None
    return {"nom": nom, "couleurs": couleurs, "logo": logo}


def charger_apparence():
    global APPARENCE
    try:
        with open(FICHIER_APPARENCE, encoding="utf-8") as f:
            APPARENCE = valider_apparence(json.load(f)) or APPARENCE
    except (OSError, ValueError):
        pass


def actualiser_apparence():
    """Relit l'apparence sur l'app ; en cas d'échec, garde la dernière connue."""
    global APPARENCE
    port, base = lire_env_projet()
    try:
        with urllib.request.urlopen(f"http://127.0.0.1:{port}{base}portail_apparence.php", timeout=4) as r:
            nouvelle = valider_apparence(json.loads(r.read(600_000).decode("utf-8")))
    except (OSError, ValueError):
        return
    if nouvelle and nouvelle != APPARENCE:
        APPARENCE = nouvelle
        tmp = FICHIER_APPARENCE + ".tmp"
        with open(tmp, "w", encoding="utf-8") as f:
            json.dump(nouvelle, f)
        os.replace(tmp, FICHIER_APPARENCE)
        log("apparence de l'app mise à jour :", nouvelle["nom"])


# ── Pages ────────────────────────────────────────────────────────────────────
STYLE = """
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
padding:0 0 32px}main{max-width:440px;margin:0 auto;padding:24px 16px 0;display:grid;gap:20px}
.barre{background:var(--barre);color:var(--sur-barre);padding:max(14px,env(safe-area-inset-top)) 16px 14px}
.brand{max-width:440px;margin:0 auto;display:flex;gap:14px;align-items:center}.brand img{width:48px;height:48px;border-radius:12px;object-fit:contain;background:#fff0}
h1{font-size:1.25rem;line-height:1.2;margin:0}.sub{margin:2px 0 0;opacity:.8;font-size:.95rem}
.card{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;display:grid;gap:14px}
label{display:grid;gap:6px;font-weight:600;font-size:.92rem}
input{font:inherit;color:var(--text);background:var(--surface-2);border:1px solid var(--border);border-radius:10px;min-height:48px;padding:0 14px;width:100%}
input:focus{outline:2px solid var(--accent);outline-offset:1px}
.btn{display:inline-flex;align-items:center;justify-content:center;min-height:48px;padding:0 18px;border-radius:10px;font:inherit;font-weight:700;
border:0;background:var(--primary);color:var(--on-primary);text-decoration:none;cursor:pointer;width:100%}
.err{color:var(--danger);font-weight:600;margin:0}.ok{color:var(--accent);font-weight:700;margin:0;font-size:1.1rem}
.note{color:var(--text-3);font-size:.85rem;margin:0}.text-2{color:var(--text-2)}
.etapes{margin:0;padding-left:1.3em;display:grid;gap:4px;color:var(--text-2)}
.adresse{font:700 2rem/1.1 ui-monospace,Menlo,Consolas,monospace;letter-spacing:.02em;text-align:center;
background:var(--surface-2);border:1px solid var(--border);border-radius:10px;padding:14px;user-select:all}p{margin:0}
"""


def texte_sur(hexa):
    """Texte lisible sur une couleur : blanc ou presque noir."""
    r, g, b = (int(hexa[i:i + 2], 16) / 255 for i in (1, 3, 5))
    lin = lambda v: v / 12.92 if v <= 0.03928 else ((v + 0.055) / 1.055) ** 2.4
    lum = 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b)
    return "#ffffff" if 1.05 / (lum + 0.05) >= (lum + 0.05) / 0.054 else "#0b0d10"


def variables_css(c):
    return (":root{color-scheme:%s;--bg:%s;--surface:%s;--surface-2:%s;--border:%s;--text:%s;--text-2:%s;--text-3:%s;"
            "--primary:%s;--on-primary:%s;--accent:%s;--danger:%s;--barre:%s;--sur-barre:%s}"
            % ("light" if c["mode"] == "clair" else "dark", c["fond"], c["surface"], c["surface2"], c["bordure"],
               c["texte"], c["texte2"], c["texte3"], c["primaire"], c["surPrimaire"], c["accent"], c["danger"],
               c["barre"], texte_sur(c["barre"])))


def page(titre, corps, entete=""):
    a = APPARENCE
    c = a["couleurs"]
    icone = "data:image/png;base64," + a["logo"] if a["logo"] else ICONE_URI
    return ("<!DOCTYPE html><html lang=\"fr\"><head><meta charset=\"UTF-8\">"
            "<meta name=\"viewport\" content=\"width=device-width,initial-scale=1,viewport-fit=cover\">"
            f"<meta name=\"theme-color\" content=\"{c['barre']}\">{entete}"
            f"<title>{html.escape(titre)} · {html.escape(a['nom'])}</title><style>{variables_css(c)}{STYLE}</style></head><body>"
            f"<header class=\"barre\"><div class=\"brand\"><img src=\"{icone}\" alt=\"\" width=\"48\" height=\"48\"><div>"
            f"<h1>{html.escape(a['nom'])}</h1><p class=\"sub\">Wi-Fi « {html.escape(SSID)} »</p></div></div></header>"
            f"<main>{corps}</main></body></html>")


def page_connexion(erreur="", ident=""):
    err = f'<p class="err" role="alert">{html.escape(erreur)}</p>' if erreur else ""
    return page("Connexion au Wi-Fi", f"""
<form class="card" method="post" action="/connexion" autocomplete="on">
  <p><strong>Connexion au Wi-Fi</strong><br><span class="text-2">Identifiez-vous pour accéder au site du tournoi.</span></p>
  {err}
  <label>Identifiant<input name="identifiant" value="{html.escape(ident)}" required autocapitalize="none" autocorrect="off" spellcheck="false" autocomplete="username" {'' if ident else 'autofocus'}></label>
  <label>Mot de passe<input name="mdp" type="password" required autocomplete="current-password" {'autofocus' if ident else ''}></label>
  <button class="btn" type="submit">Se connecter</button>
</form>
<p class="note">Pas de compte ? Demandez-en un à l'organisation du tournoi. Ce Wi-Fi ne donne pas accès à internet.</p>""")


def page_connecte(ident):
    url = f"http://{IP_AP}/"
    # Le site s'affiche tout seul après 3 s (iPhone, iPad, Mac, Windows, et Android si la
    # fenêtre de connexion est encore ouverte) ; le reste de la page sert si on la ferme avant.
    return page("Connecté", f"""
<div class="card">
  <p class="ok">✓ Vous êtes connecté ({html.escape(ident)})</p>
  <p class="text-2">Le site du tournoi s'ouvre…</p>
  <a class="btn" href="{url}">Ouvrir le site</a>
</div>
<div class="card">
  <p><strong>Pour l'ouvrir dans votre navigateur habituel</strong> (Chrome, Safari, Firefox, Samsung Internet, Edge…) :</p>
  <ol class="etapes">
    <li>Fermez cette fenêtre (✕, « Terminé » ou bouton retour).</li>
    <li>Ouvrez le navigateur et tapez dans la barre d'adresse :</li>
  </ol>
  <p class="adresse">{html.escape(IP_AP)}</p>
  <p class="note">Android affiche « Connexion limitée » ou « Pas d'accès à internet » ? Choisissez
     <strong>Rester connecté</strong> (ou coupez les données mobiles), sinon le navigateur passe par la 4G
     et ne trouve pas le site.</p>
  <a class="btn" href="{url}" target="_blank" rel="noopener">Ouvrir dans le navigateur</a>
</div>""", entete=f'<meta http-equiv="refresh" content="3;url={url}">')


# Logo intégré directement dans la page (data:) : aucun fichier à lire, aucune requête
# en plus (les mini-navigateurs « Se connecter au réseau » l'affichent toujours).
ICONE_URI = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAMAAAADACAYAAABS3GwHAAAWfmNhQlgAABZ+anVtYgAAAB5qdW1kYzJwYQARABCAAACqADibcQNjMnBhAAAAFlhqdW1iAAAAR2p1bWRjMm1hABEAEIAAAKoAOJtxA3VybjpjMnBhOmVmYTE4NGI5LTJmZGMtNDE4Yy1hN2VlLWYwYmY2OWU4NmViZAAAAAOTanVtYgAAAClqdW1kYzJhcwARABCAAACqADibcQNjMnBhLmFzc2VydGlvbnMAAAAAuGp1bWIAAABEanVtZGNib3IAEQAQgAAAqgA4m3ETYzJwYS5pbmdyZWRpZW50LnYzAAAAABhjMnNo0/0WWkh6iYfYVn7/le+JngAAAGxjYm9yo2lkYzpmb3JtYXRpaW1hZ2UvcG5namluc3RhbmNlSUR4LHhtcDppaWQ6NGUxOWQwYWItNjA1Zi00YTY1LWFhOGMtOWFlMzkwNDBlMWU3bHJlbGF0aW9uc2hpcGhwYXJlbnRPZgAAAeJqdW1iAAAAQWp1bWRjYm9yABEAEIAAAKoAOJtxE2MycGEuYWN0aW9ucy52MgAAAAAYYzJzaDBo813UP7V0YoyUcCwUE20AAAGZY2JvcqJnYWN0aW9uc4KiZmFjdGlvbmtjMnBhLm9wZW5lZGpwYXJhbWV0ZXJzoWtpbmdyZWRpZW50c4GiY3VybHgtc2VsZiNqdW1iZj1jMnBhLmFzc2VydGlvbnMvYzJwYS5pbmdyZWRpZW50LnYzZGhhc2hYIB6QNE7r6GzzNZYZAxU8CSLSwPCTGYyn1D0NMNsuyIDepGZhY3Rpb254HWNvbS5hbnRocm9waWMuY2xhdWRlLnByb3ZpZGVkanBhcmFtZXRlcnOheB9jb20uYW50aHJvcGljLm9yaWdpbi1jb25maWRlbmNlZ3Vua25vd25rZGVzY3JpcHRpb254ZkNsYXVkZSBwcm92aWRlZCB0aGlzIGZpbGUgYXQgdGhlIHJlcXVlc3Qgb2YgYSB1c2VyIGFuZCBtYXkgaGF2ZSBjcmVhdGVkIG9yIG1vZGlmaWVkIHRoZSBmaWxlIGNvbnRlbnRzLm1zb2Z0d2FyZUFnZW50oWRuYW1lZkNsYXVkZXJhbGxBY3Rpb25zSW5jbHVkZWT1AAAAyGp1bWIAAABAanVtZGNib3IAEQAQgAAAqgA4m3ETYzJwYS5oYXNoLmRhdGEAAAAAGGMyc2jIzuPyg0ZOMc0PybGLEC2eAAAAgGNib3KlY2FsZ2ZzaGEyNTZjcGFkTQAAAAAAAAAAAAAAAABkaGFzaFggcmPTPjF5zGGhIjZpDyVxwSVHgQFJmKYRXd3RuKbY4ZVkbmFtZW5qdW1iZiBtYW5pZmVzdGpleGNsdXNpb25zgaJlc3RhcnQYIWZsZW5ndGgZFooAAAI+anVtYgAAACdqdW1kYzJjbAARABCAAACqADibcQNjMnBhLmNsYWltLnYyAAAAAg9jYm9ypWNhbGdmc2hhMjU2aXNpZ25hdHVyZXhNc2VsZiNqdW1iZj0vYzJwYS91cm46YzJwYTplZmExODRiOS0yZmRjLTQxOGMtYTdlZS1mMGJmNjllODZlYmQvYzJwYS5zaWduYXR1cmVqaW5zdGFuY2VJRHgseG1wOmlpZDpjZDNlOGFmYy04OWJmLTRjYjktOWJhZS05NjU3NmZhN2Y5Y2RyY3JlYXRlZF9hc3NlcnRpb25zg6JjdXJseC1zZWxmI2p1bWJmPWMycGEuYXNzZXJ0aW9ucy9jMnBhLmluZ3JlZGllbnQudjNkaGFzaFggHpA0TuvobPM1lhkDFTwJItLA8JMZjKfUPQ0w2y7IgN6iY3VybHgqc2VsZiNqdW1iZj1jMnBhLmFzc2VydGlvbnMvYzJwYS5hY3Rpb25zLnYyZGhhc2hYIA+9qqaqzswLmSRfSxgYrSVFpq7giIy3kiPie7XLuYYmomN1cmx4KXNlbGYjanVtYmY9YzJwYS5hc3NlcnRpb25zL2MycGEuaGFzaC5kYXRhZGhhc2hYICxMPyiKS0vakUlQcMifJnOD/s2QN4RTrS/p5p0zY3rmdGNsYWltX2dlbmVyYXRvcl9pbmZvo2RuYW1lb0FudGhyb3BpYyBGaWxlc2d2ZXJzaW9uZTEuMC4wa3NwZWNWZXJzaW9uZTIuNC4wAAAQOGp1bWIAAAAoanVtZGMyY3MAEQAQgAAAqgA4m3EDYzJwYS5zaWduYXR1cmUAAAAQCGNib3LShFkCEqIBJhghWQIKMIICBjCCAY2gAwIBAgIUQOWgCu7COdC+uIP6BkIFPWdVEwAwCgYIKoZIzj0EAwMwSTEXMBUGA1UEChMOQW50aHJvcGljLCBQQkMxLjAsBgNVBAMTJUFudGhyb3BpYyBDb250ZW50IENyZWRlbnRpYWxzIFJvb3QgQ0EwHhcNMjYwODA3MTg0MzU2WhcNMjgwODA2MTk0MzU2WjBEMRcwFQYDVQQKEw5BbnRocm9waWMsIFBCQzEpMCcGA1UEAxMgQW50aHJvcGljIENsYXVkZSBDb250ZW50IFNpZ25pbmcwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAASYegpry1AYBRTVNL1CpTlbROnY3dey+UrsF9C3phYrATN3ZHf93Mo8RQN0KOUuOn19P4oWNFWe5n2/She9N7eTo1gwVjAOBgNVHQ8BAf8EBAMCB4AwFQYDVR0lBA4wDAYKKwYBBAGD6F4CATAMBgNVHRMBAf8EAjAAMB8GA1UdIwQYMBaAFM5R4gSBTmRbI/jjxM+aPpzB11zCMAoGCCqGSM49BAMDA2cAMGQCMDFzHRSeAXrSy1WOzkbhPZ6Km2wGTmZ/2gK18k8BQGXyqz88Rdrz6CTX9flAnYNVxgIwcF9c3fVhqmJKpi+UhasNUMko69cyX6STPfta3Q8EjyzDjzoyrol46FP6VFHhvUcJoWNwYWRZDZ4AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA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"


class Portail(BaseHTTPRequestHandler):
    server_version = "portail"
    sys_version = ""
    timeout = 10          # un client lent ne bloque pas le portail

    def log_message(self, *a):  # pas de journal de chaque requête
        pass

    def envoyer(self, code, corps=b"", type_="text/html; charset=utf-8", entetes=None):
        self.send_response(code)
        self.send_header("Content-Type", type_)
        self.send_header("Content-Length", str(len(corps)))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Frame-Options", "DENY")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("Referrer-Policy", "no-referrer")
        self.send_header("Content-Security-Policy",
                         "default-src 'none'; style-src 'unsafe-inline'; img-src data:; form-action 'self'; base-uri 'none'")
        for k, v in (entetes or {}).items():
            self.send_header(k, v)
        self.end_headers()
        if self.command != "HEAD":
            self.wfile.write(corps)

    def hote_portail(self):
        hote = (self.headers.get("Host") or "").split(":")[0].lower()
        return hote in (DOMAINE, IP_AP)

    def do_HEAD(self):
        self.do_GET()

    def do_GET(self):
        ip = self.client_address[0]
        # Tests de connexion des téléphones (et tout autre site) : renvoi vers le portail.
        # C'est ce qui fait apparaître automatiquement « Se connecter au réseau ».
        if not self.hote_portail():
            # 302 vers le portail : Android, iPhone/iPad, Mac, Windows et Firefox
            # concluent « réseau avec page de connexion » et l'ouvrent tout seuls.
            # Adresse IP (et non sabre.lan) : marche même si le téléphone impose son
            # propre DNS (« DNS privé » d'Android, iCloud Relais privé…).
            return self.envoyer(302, entetes={"Location": f"http://{IP_AP}/", "Connection": "close"})
        chemin = urllib.parse.urlsplit(self.path).path
        with verrou:
            s = sessions.get(ip)
        if s and s["expire"] > time.time():
            return self.envoyer(200, page_connecte(s["user"]).encode())
        self.envoyer(200, page_connexion().encode())

    def do_POST(self):
        ip = self.client_address[0]
        if urllib.parse.urlsplit(self.path).path != "/connexion" or not self.hote_portail():
            return self.envoyer(302, entetes={"Location": f"http://{IP_AP}/"})
        try:
            n = min(int(self.headers.get("Content-Length") or 0), 2048)
        except ValueError:
            n = 0
        donnees = urllib.parse.parse_qs(self.rfile.read(n).decode("utf-8", "replace"))
        ident = (donnees.get("identifiant", [""])[0]).strip().lower()[:32]
        mdp = donnees.get("mdp", [""])[0][:128]

        maintenant = time.time()
        with verrou:
            recents = [t for t in echecs.get(ip, []) if t > maintenant - 300]
            echecs[ip] = recents
        if len(recents) >= 5:
            return self.envoyer(429, page_connexion("Trop d'essais : réessayez dans 5 minutes.", ident).encode())

        if not verifier(ident, mdp):
            with verrou:
                echecs.setdefault(ip, []).append(maintenant)
            log("échec de connexion :", repr(ident), ip)
            time.sleep(1)
            return self.envoyer(200, page_connexion("Identifiant ou mot de passe incorrect.", ident).encode())

        mac = mac_de(ip)
        if not mac or not nft_autoriser(ip, mac, SESSION_S):
            return self.envoyer(200, page_connexion("Connexion impossible pour le moment, réessayez.", ident).encode())
        with verrou:
            ancien = sessions.get(ip)
            if ancien and ancien["mac"] != mac:
                nft_retirer(ip, ancien["mac"])
            sessions[ip] = {"mac": mac, "user": ident, "expire": maintenant + SESSION_S}
            echecs.pop(ip, None)
            sauver()
        log("connecté :", ident, ip, mac)
        self.envoyer(200, page_connecte(ident).encode())


class Serveur(ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def server_bind(self):
        # IP_FREEBIND : on peut écouter sur l'adresse du Wi-Fi même si l'interface
        # n'est pas encore prête au démarrage du Pi
        self.socket.setsockopt(socket.IPPROTO_IP, 15, 1)
        super().server_bind()


def main():
    os.makedirs(os.path.dirname(FICHIER_SESSIONS), mode=0o700, exist_ok=True)
    charger()
    charger_apparence()
    threading.Thread(target=entretien, daemon=True).start()
    srv = Serveur((IP_AP, PORT), Portail)
    srv.timeout = 10
    log(f"portail Wi-Fi sur {IP_AP}:{PORT} (sessions de {SESSION_S // 3600} h)")
    srv.serve_forever()


if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--md4":   # hachage NT du mot de passe lu sur stdin
        print(md4(sys.stdin.buffer.read().decode("utf-8").encode("utf-16-le")))
    else:
        main()
