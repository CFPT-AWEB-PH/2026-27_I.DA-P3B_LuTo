#!/usr/bin/env python3
# =============================================================================
# sabre-fond-ecran : fond d'écran du Raspberry avec les infos de connexion
# Installé dans /usr/local/sbin/sabre-fond-ecran par raspberry/install_ecran.sh
#
#   - nom du Wi-Fi + mot de passe (ou « ouvert »), ceux d'Admin › Wi-Fi
#   - identifiant + mot de passe du portail : l'accès coché « Afficher sur l'écran
#     du Raspberry » dans Admin › Wi-Fi
#   - identifiant + mot de passe du compte choisi pour ce Raspberry, et son image de
#     fond : Admin › Raspberry
#   - QR code pour rejoindre le Wi-Fi, QR code pour ouvrir le site
#
# Lancé (root) toutes les 15 s par sabre-fond-ecran.timer : l'image n'est redessinée
# que si une de ces informations a changé (--forcer : toujours).
# Raspberry principal : infos lues en local dans le conteneur de l'app.
# Autres Raspberry (/etc/sabre/client.env, écrit par install_ecran_client.sh) : lues
# sur le Wi-Fi avec leur jeton, en passant le portail si besoin.
# Aucun accès coché dans l'app : compte de /etc/sabre/ecran-compte (identifiant:mot de
# passe), sinon un compte de secours « invite » est créé avec sabre-wifi.
# =============================================================================
import hashlib, json, os, re, shlex, subprocess, sys, urllib.parse, urllib.request

import qrcode
from PIL import Image, ImageDraw, ImageFont, ImageOps

CONF = "/etc/sabre/ap.env"
COMPTE = "/etc/sabre/ecran-compte"
CLIENT = "/etc/sabre/client.env"         # SERVEUR, JETON, WIFI_ID, WIFI_MDP : autres Raspberry seulement
ECRAN = "/etc/sabre/ecran.env"           # ECRAN_USER : utilisateur du bureau (écrit par install_ecran.sh)
SORTIE = "/var/lib/sabre-ecran/fond.png"
SIGNATURE = "/var/lib/sabre-ecran/signature"
CACHE_APP = "/var/lib/sabre-ecran/infos-app"    # dernières infos lues dans l'app (app arrêtée : on les garde)
IMAGE_PERSO = "/var/lib/sabre-ecran/image-fond" # image de fond téléchargée (Admin › Raspberry)
WEB_CONTAINER = "sabre_web"
SCRIPT_INFOS = "/var/www/html/raspberry/infos_ecran.php"
POLICE ="/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
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


def demander_serveur(client, suffixe=""):
    """Réponse de l'app à un Raspberry connecté au Wi-Fi."""
    req = urllib.request.Request(f"http://{client['SERVEUR']}/api/raspberry_infos.php{suffixe}",
                                 headers={"X-Sabre-Jeton": client["JETON"]})
    with urllib.request.urlopen(req, timeout=15) as r:
        return r.read()


def passer_portail(client):
    donnees = urllib.parse.urlencode({"identifiant": client["WIFI_ID"], "mdp": client["WIFI_MDP"]}).encode()
    urllib.request.urlopen(f"http://{client['SERVEUR'].split('/')[0]}/connexion", donnees, timeout=15).close()


def lire_infos_serveur(client):
    # Pas encore identifié sur le portail : c'est sa page de connexion qui répond, pas du JSON
    try:
        return json.loads(demander_serveur(client))
    except ValueError:
        passer_portail(client)
        return json.loads(demander_serveur(client))


def lire_infos_local():
    # Identifiants de la base et clé des mots de passe : lus DANS le conteneur, rien en clair ici
    r = subprocess.run(["docker", "exec", WEB_CONTAINER, "php", SCRIPT_INFOS],
                       capture_output=True, text=True, timeout=20, check=True)
    return json.loads(r.stdout)


def lire_infos(client):
    """Ce que l'app veut sur cet écran. App injoignable : les dernières infos connues ({} sinon)."""
    try:
        infos = lire_infos_serveur(client) if client else lire_infos_local()
        ecrire_prive(CACHE_APP, json.dumps(infos))
        return infos
    except (OSError, ValueError, subprocess.SubprocessError) as e:
        print(f"Infos de l'app indisponibles : {e}", file=sys.stderr)
    try:
        return json.load(open(CACHE_APP))
    except (OSError, ValueError):
        return {}


def image_fond(client, version):
    """Chemin de l'image de fond de ce Raspberry (téléchargée si elle a changé), None s'il n'y en a pas."""
    if not version:
        return None
    if os.path.exists(f"{IMAGE_PERSO}.{version}"):
        return f"{IMAGE_PERSO}.{version}"
    try:
        if client:
            contenu = demander_serveur(client, "?fond=1")
        else:
            contenu = subprocess.run(["docker", "exec", WEB_CONTAINER, "php", SCRIPT_INFOS, "--fond"],
                                     capture_output=True, timeout=30, check=True).stdout
    except (OSError, subprocess.SubprocessError) as e:
        print(f"Image de fond indisponible : {e}", file=sys.stderr)
        return None
    dossier = os.path.dirname(IMAGE_PERSO)
    for nom in os.listdir(dossier):
        if nom.startswith(os.path.basename(IMAGE_PERSO) + "."):
            os.remove(os.path.join(dossier, nom))
    with open(f"{IMAGE_PERSO}.{version}", "wb") as f:
        f.write(contenu)
    return f"{IMAGE_PERSO}.{version}"


def lire_compte(infos):
    app = infos.get("portail") or {}
    if app.get("identifiant") and app.get("mdp"):
        return app["identifiant"], app["mdp"]
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


def image_de_base(W, H, chemin):
    """Fond uni, ou image choisie assombrie pour que le texte par-dessus reste lisible."""
    fond = Image.new("RGB", (W, H), FOND)
    if not chemin:
        return fond
    try:
        return Image.blend(ImageOps.fit(Image.open(chemin).convert("RGB"), (W, H)), fond, 0.55)
    except OSError as e:
        print(f"Image de fond illisible : {e}", file=sys.stderr)
        return fond


def texte_ajuste(d, xy, texte, chemin_police, taille, largeur, fill):
    """Écrit le texte en réduisant la police s'il dépasse la largeur disponible."""
    while taille > 10:
        police = ImageFont.truetype(chemin_police, int(taille))
        if d.textlength(texte, font=police) <= largeur:
            break
        taille -= 1
    d.text(xy, texte, font=ImageFont.truetype(chemin_police, int(max(taille, 10))), fill=fill)


def main():
    conf = lire_conf()
    client = lire_conf_fichier(CLIENT)
    infos = lire_infos(client)
    ssid = conf.get("SSID") or infos.get("ssid") or "Sabre-Arbitrage"
    mdp_wifi = conf.get("WIFI_MDP") or infos.get("mdp_wifi") or ""
    domaine = conf.get("DOMAINE") or infos.get("domaine") or "sabre.lan"
    ident, mdp = lire_compte(infos)
    compte = infos.get("compte") or {}
    compte_ident, compte_mdp = compte.get("identifiant"), compte.get("mdp")
    version_image = infos.get("fond_version")
    url = f"http://{domaine}"
    W, H = taille_ecran()

    # Rien n'a changé depuis la dernière image : on ne redessine pas
    signature = hashlib.sha256(repr((ssid, mdp_wifi, domaine, ident, mdp, compte_ident, compte_mdp, version_image,
                                     W, H, os.path.getmtime(__file__))).encode()).hexdigest()
    try:
        deja = open(SIGNATURE).read().strip() == signature and os.path.exists(SORTIE)
    except OSError:
        deja = False
    if deja and "--forcer" not in sys.argv:
        return 0

    u = H / 1080                               # unité : tout est proportionnel à la hauteur
    img = image_de_base(W, H, image_fond(client, version_image))
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
    if compte_ident:
        etapes.append(("4", "Se connecter au site", [("Identifiant", compte_ident),
                                                     ("Mot de passe", compte_mdp or "voir l'organisateur")]))
    # Quatre cartes : identifiant et mot de passe côte à côte, pour que tout tienne à l'écran
    compact = len(etapes) > 3
    larg_g = int(W * 0.56) - marge
    x_texte = marge + int(95 * u)
    larg_texte = marge + larg_g - x_texte - int(20 * u)
    y = int((190 if compact else 220) * u)
    for num, titre_etape, lignes in etapes:
        rangees = 1 if compact else len(lignes)
        h_carte = int((90 + 95 * rangees) * u)
        d.rounded_rectangle((marge, y, marge + larg_g, y + h_carte), radius=int(18 * u), fill=CARTE)
        r = int(26 * u); cx, cy = marge + int(50 * u), y + int(48 * u)
        d.ellipse((cx - r, cy - r, cx + r, cy + r), fill=ACCENT)
        d.text((cx, cy), num, font=f(POLICE_GRAS, 30), fill=FOND, anchor="mm")
        d.text((x_texte, cy), titre_etape, font=f(POLICE_GRAS, 34), fill=TEXTE, anchor="lm")
        ly = y + int(95 * u)
        larg_col = larg_texte // len(lignes) - int(10 * u) if compact else larg_texte
        for i, (lab, val) in enumerate(lignes):
            lx = x_texte + (i * (larg_texte // len(lignes)) if compact else 0)
            d.text((lx, ly), lab, font=label, fill=DISCRET)
            texte_ajuste(d, (lx, ly + int(32 * u)), val, POLICE_MONO, max(10, int(46 * u)), larg_col, TEXTE)
            if not compact:
                ly += int(95 * u)
        y += h_carte + int((18 if compact else 28) * u)

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

    if not compact:
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
