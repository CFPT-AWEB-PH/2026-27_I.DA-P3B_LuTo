#!/usr/bin/env python3
# =============================================================================
# sabre-fond-ecran : fond d'écran du Raspberry avec les infos de connexion
# Installé dans /usr/local/sbin/sabre-fond-ecran par raspberry/install_ecran.sh
#
#   - nom du Wi-Fi + mot de passe (ou « ouvert »), ceux d'Admin › Wi-Fi
#   - identifiant + mot de passe du portail : l'accès coché « Afficher sur l'écran
#     du Raspberry » dans Admin › Wi-Fi
#   - QR code pour rejoindre le Wi-Fi, QR code pour ouvrir le site
#
# Lancé (root) toutes les 15 s par sabre-fond-ecran.timer : l'image n'est redessinée
# que si une de ces informations a changé (--forcer : toujours).
# Aucun accès coché dans l'app : compte de /etc/sabre/ecran-compte (identifiant:mot de
# passe), sinon un compte de secours « invite » est créé avec sabre-wifi.
# =============================================================================
import hashlib, os, re, shlex, subprocess, sys

import qrcode
from PIL import Image, ImageDraw, ImageFont

CONF = "/etc/sabre/ap.env"
COMPTE = "/etc/sabre/ecran-compte"
ECRAN = "/etc/sabre/ecran.env"           # ECRAN_USER : utilisateur du bureau (écrit par install_ecran.sh)
SORTIE = "/var/lib/sabre-ecran/fond.png"
SIGNATURE = "/var/lib/sabre-ecran/signature"
CACHE_APP = "/var/lib/sabre-ecran/compte-app"   # dernier accès lu dans l'app (base arrêtée : on le garde)
DB_CONTAINER = "sabre_db"
POLICE = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
POLICE_GRAS = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
POLICE_MONO = "/usr/share/fonts/truetype/dejavu/DejaVuSansMono-Bold.ttf"

FOND, CARTE, TEXTE, DISCRET, ACCENT = "#0d1117", "#161b22", "#f0f6fc", "#8b949e", "#3fb950"


def lire_conf():
    return lire_conf_fichier(CONF)


def lire_conf_fichier(chemin):
    conf = {}
    try:
        with open(chemin) as f:
            for ligne in f:
                m = re.match(r'^([A-Z_]+)=(.*)$', ligne.strip())
                if m:
                    v = shlex.split(m.group(2))
                    conf[m.group(1)] = v[0] if v else ""
    except OSError:
        pass
    return conf


def ecrire_prive(chemin, contenu):
    old = os.umask(0o077)
    try:
        with open(chemin, "w") as f:
            f.write(contenu)
    finally:
        os.umask(old)


def compte_app():
    """Accès coché « sur l'écran » dans Admin › Wi-Fi : (identifiant, mdp), ("", "") si aucun.
    Base injoignable : le dernier accès connu (None s'il n'y en a jamais eu)."""
    sql = ("SELECT identifiant, ecran_mdp FROM wifi_comptes "
           "WHERE actif = 1 AND ecran_mdp IS NOT NULL ORDER BY updated_at DESC LIMIT 1;")
    try:
        # Identifiants de la base lus DANS le conteneur, comme raspberry/sabre-wifi
        r = subprocess.run(["docker", "exec", "-i", DB_CONTAINER, "sh", "-c",
                            'MYSQL_PWD="$MARIADB_PASSWORD" exec mariadb -u"$MARIADB_USER" -D"$MARIADB_DATABASE" -N -B'],
                           input=sql, capture_output=True, text=True, timeout=20)
        if r.returncode == 0:
            ident, _, mdp = r.stdout.rstrip("\n").partition("\t")
            ecrire_prive(CACHE_APP, f"{ident}\t{mdp}\n")
            return ident, mdp
    except (OSError, subprocess.SubprocessError):
        pass
    try:
        ident, _, mdp = open(CACHE_APP).read().rstrip("\n").partition("\t")
        return ident, mdp
    except OSError:
        return None


def lire_compte():
    app = compte_app()
    if app and app[0] and app[1]:
        return app
    try:
        with open(COMPTE) as f:
            ident, _, mdp = f.read().strip().partition(":")
            if ident and mdp:
                return ident, mdp
    except OSError:
        pass
    # Premier lancement : compte de secours dédié à l'écran (il marche même sans la base)
    try:
        r = subprocess.run(["/usr/local/sbin/sabre-wifi", "secours-ajouter", "invite", "--generer"],
                           capture_output=True, text=True, stdin=subprocess.DEVNULL, timeout=30)
        m = re.search(r"Identifiant : (\S+)\s+Mot de passe : (\S+)", r.stdout)
        if m:
            ecrire_prive(COMPTE, f"{m.group(1)}:{m.group(2)}\n")
            return m.group(1), m.group(2)
    except (OSError, subprocess.SubprocessError):
        pass
    return None, None


def taille_ecran():
    # Première résolution annoncée par un écran branché (sinon Full HD)
    base = "/sys/class/drm"
    try:
        for d in sorted(os.listdir(base)):
            try:
                if open(f"{base}/{d}/status").read().strip() != "connected":
                    continue
                mode = open(f"{base}/{d}/modes").readline().strip()
                w, h = mode.split("x")
                return int(w), int(re.match(r"\d+", h).group())
            except (OSError, ValueError, AttributeError):
                continue
    except OSError:
        pass
    return 1920, 1080


def echapper_wifi(s):
    return re.sub(r'([\\;,:"])', r"\\\1", s)


def qr(contenu, cote):
    q = qrcode.QRCode(error_correction=qrcode.constants.ERROR_CORRECT_M, border=2)
    q.add_data(contenu)
    q.make(fit=True)
    return q.make_image(fill_color="black", back_color="white").convert("RGB").resize((cote, cote), Image.NEAREST)


def appliquer():
    # Bureau ouvert (pcmanfm sous labwc) : on lui demande de recharger l'image.
    # Sinon rien à faire : sa configuration pointe déjà sur SORTIE au prochain démarrage.
    user = lire_conf_fichier(ECRAN).get("ECRAN_USER", "")
    if not user:
        return
    try:
        import pwd
        uid = pwd.getpwnam(user).pw_uid
    except KeyError:
        return
    run = f"/run/user/{uid}"
    if not os.path.exists(f"{run}/wayland-0"):
        return
    subprocess.run(["runuser", "-u", user, "--", "env", f"XDG_RUNTIME_DIR={run}", "WAYLAND_DISPLAY=wayland-0",
                    "pcmanfm", f"--set-wallpaper={SORTIE}", "--wallpaper-mode=fit"],
                   stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)


def main():
    conf = lire_conf()
    ssid = conf.get("SSID", "Sabre-Arbitrage")
    mdp_wifi = conf.get("WIFI_MDP", "")
    domaine = conf.get("DOMAINE", "sabre.lan")
    ident, mdp = lire_compte()
    url = f"http://{domaine}"
    W, H = taille_ecran()

    # Rien n'a changé depuis la dernière image : on ne redessine pas
    signature = hashlib.sha256(repr((ssid, mdp_wifi, domaine, ident, mdp, W, H,
                                     os.path.getmtime(__file__))).encode()).hexdigest()
    try:
        deja = open(SIGNATURE).read().strip() == signature and os.path.exists(SORTIE)
    except OSError:
        deja = False
    if deja and "--forcer" not in sys.argv:
        return 0

    u = H / 1080                               # unité : tout est proportionnel à la hauteur
    img = Image.new("RGB", (W, H), FOND)
    d = ImageDraw.Draw(img)
    f = lambda chemin, t: ImageFont.truetype(chemin, max(10, int(t * u)))
    titre, sous, label, valeur, petit = f(POLICE_GRAS, 64), f(POLICE, 30), f(POLICE, 28), f(POLICE_MONO, 46), f(POLICE, 25)

    marge = int(80 * u)
    # Le titre reste sous la barre des tâches (~36 px) ; sous-titre seulement sur un grand écran
    d.text((marge, max(int(60 * u), 44)), "Sabre Arbitrage", font=titre, fill=TEXTE)
    if H >= 720:
        d.text((marge, int(140 * u)), "Connectez-vous au Wi-Fi de la salle pour suivre et arbitrer les combats",
               font=sous, fill=DISCRET)

    # ── Colonne de gauche : les trois étapes ─────────────────────────────────
    etapes = [
        ("1", "Rejoindre le Wi-Fi", [("Nom du Wi-Fi", ssid),
                                     ("Mot de passe", mdp_wifi if mdp_wifi else "aucun (réseau ouvert)")]),
        ("2", "S'identifier sur la page qui s'ouvre", [("Identifiant", ident or "voir l'organisateur"),
                                                       ("Mot de passe", mdp or "voir l'organisateur")]),
        ("3", "Ouvrir le site", [("Adresse", url)]),
    ]
    larg_g = int(W * 0.56) - marge
    y = int(220 * u)
    for num, titre_etape, lignes in etapes:
        h_carte = int((90 + 95 * len(lignes)) * u)
        d.rounded_rectangle((marge, y, marge + larg_g, y + h_carte), radius=int(18 * u), fill=CARTE)
        r = int(26 * u); cx, cy = marge + int(50 * u), y + int(48 * u)
        d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=ACCENT)
        d.text((cx, cy), num, font=f(POLICE_GRAS, 30), fill=FOND, anchor="mm")
        d.text((marge + int(95 * u), cy), titre_etape, font=f(POLICE_GRAS, 34), fill=TEXTE, anchor="lm")
        ly = y + int(95 * u)
        for lab, val in lignes:
            d.text((marge + int(95 * u), ly), lab, font=label, fill=DISCRET)
            d.text((marge + int(95 * u), ly + int(32 * u)), val, font=valeur, fill=TEXTE)
            ly += int(95 * u)
        y += h_carte + int(28 * u)

    # ── Colonne de droite : QR codes ─────────────────────────────────────────
    securite = "WPA" if mdp_wifi else "nopass"
    qr_wifi = f"WIFI:T:{securite};S:{echapper_wifi(ssid)};" + (f"P:{echapper_wifi(mdp_wifi)};" if mdp_wifi else "") + ";"
    gauche = int(W * 0.56) + int(30 * u)
    cote = min(int(340 * u), W - gauche - int(40 * u), int((H - 300 * u) / 2))
    x0 = (gauche + W) // 2 - cote // 2
    for i, (contenu, legende) in enumerate([(qr_wifi, "Scanner pour rejoindre le Wi-Fi"),
                                            (url, "Scanner pour ouvrir le site")]):
        qy = int(220 * u) + i * (cote + int(110 * u))
        pad = int(14 * u)
        d.rounded_rectangle((x0 - pad, qy - pad, x0 + cote + pad, qy + cote + pad), radius=int(14 * u), fill="white")
        img.paste(qr(contenu, cote), (x0, qy))
        d.text((x0 + cote // 2, qy + cote + int(40 * u)), legende, font=petit, fill=TEXTE, anchor="mm")

    d.text((marge, H - int(50 * u)), "Pas d'internet sur ce réseau : seul le site du tournoi est accessible.",
           font=petit, fill=DISCRET)

    os.makedirs(os.path.dirname(SORTIE), exist_ok=True)
    tmp = SORTIE + ".tmp.png"
    img.save(tmp)
    os.chmod(tmp, 0o644)
    os.replace(tmp, SORTIE)
    ecrire_prive(SIGNATURE, signature + "\n")
    appliquer()
    print(f"Fond d'écran : {SORTIE} ({W}x{H}) · Wi-Fi « {ssid} » · compte {ident or '?'}")


if __name__ == "__main__":
    sys.exit(main())
