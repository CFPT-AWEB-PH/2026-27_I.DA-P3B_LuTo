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
# Aperçu sans rien toucher au bureau : sabre-fond-ecran --apercu image.png [800x480]
# Raspberry principal : infos lues en local dans le conteneur de l'app.
# Autres Raspberry (/etc/sabre/client.env, écrit par install_ecran_client.sh) : lues
# sur le Wi-Fi avec leur jeton, en passant le portail si besoin.
# Aucun accès coché dans l'app : compte de /etc/sabre/ecran-compte (identifiant:mot de
# passe), sinon un compte de secours « invite » est créé avec sabre-wifi.
# =============================================================================
import hashlib, json, os, re, shlex, subprocess, sys, urllib.parse, urllib.request

import qrcode
from PIL import Image, ImageDraw, ImageFilter, ImageFont, ImageOps

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


# ── Dessin (style « Verre » de l'application) ─────────────────────────────────
# Fond sombre avec halos colorés, cartes en verre dépoli, pastilles d'étape en
# dégradé indigo, valeurs dans des étiquettes, QR codes sur tuiles lumineuses.
POLICE_TEXTE = "/usr/share/fonts/truetype/nunito-sans/NunitoSans-VariableFont_YTLC,opsz,wdth,wght.ttf"
POLICE_VALEUR = "/usr/share/fonts/truetype/noto/NotoSansMono-Bold.ttf"
BASE, TEXTE_V, TEXTE_2, TEXTE_3 = (8, 10, 16), (236, 238, 242), (168, 171, 181), (138, 141, 151)
INDIGO, INDIGO_FONCE, VIOLET, CYAN = (128, 149, 255), (84, 100, 240), (146, 86, 255), (18, 150, 182)


def police(taille, graisse=400, mono=False):
    """Nunito Sans à la graisse voulue (police variable) ; DejaVu si elle manque."""
    taille = max(9, int(taille))
    if mono:
        for chemin in (POLICE_VALEUR, POLICE_MONO):
            try:
                return ImageFont.truetype(chemin, taille)
            except OSError:
                pass
    try:
        p = ImageFont.truetype(POLICE_TEXTE, taille)
        try:
            axes = p.get_variation_axes()
            valeurs = []
            for a in axes:
                nom = a.get("name", b"")
                nom = nom.decode() if isinstance(nom, bytes) else str(nom)
                if "eight" in nom or nom.lower().startswith("wght"):
                    valeurs.append(max(a["minimum"], min(a["maximum"], graisse)))
                elif "ptical" in nom or nom.lower().startswith("opsz"):
                    valeurs.append(max(a["minimum"], min(a["maximum"], taille)))
                else:
                    valeurs.append(a.get("default", a["minimum"]))
            p.set_variation_by_axes(valeurs)
        except (OSError, AttributeError, KeyError):
            pass
        return p
    except OSError:
        return ImageFont.truetype(POLICE_GRAS if graisse >= 600 else POLICE, taille)


def ajuster(d, texte, taille, largeur, **kw):
    """Police aussi grande que possible (≤ taille) pour que le texte tienne dans la largeur."""
    while taille > 10:
        p = police(taille, **kw)
        if d.textlength(texte, font=p) <= largeur:
            return p
        taille -= 1
    return police(10, **kw)


def calque(W, H):
    return Image.new("RGBA", (W, H), (0, 0, 0, 0))


def fond_verre(W, H, chemin_image):
    """Toile de fond : dégradé sombre + halos indigo / violet / cyan (ou image choisie assombrie)."""
    img = Image.new("RGB", (W, H), BASE)
    haut = Image.new("RGB", (W, H), (12, 14, 22))
    masque = Image.linear_gradient("L").resize((W, H))
    img = Image.composite(img, haut, masque)
    if chemin_image:
        try:
            perso = ImageOps.fit(Image.open(chemin_image).convert("RGB"), (W, H))
            img = Image.blend(perso, img, 0.62)
        except OSError as e:
            print(f"Image de fond illisible : {e}", file=sys.stderr)
    halos = calque(W, H)
    d = ImageDraw.Draw(halos)
    m = max(W, H)
    for (cx, cy, r, couleur, a) in ((0.06, -0.04, 0.48, (84, 100, 255), 120), (1.04, 0.18, 0.40, VIOLET, 92),
                                     (0.72, 1.06, 0.46, CYAN, 82)):
        x, y, rr = cx * W, cy * H, r * m
        d.ellipse((x - rr, y - rr * .8, x + rr, y + rr * .8), fill=couleur + (a,))
    halos = halos.filter(ImageFilter.GaussianBlur(m * 0.12))
    return Image.alpha_composite(img.convert("RGBA"), halos)


def carte_verre(img, box, rayon, u):
    """Panneau de verre : remplissage translucide, reflet en haut, liseré lumineux, ombre douce."""
    x0, y0, x1, y1 = box
    ombre = calque(*img.size)
    ImageDraw.Draw(ombre).rounded_rectangle((x0, y0 + 8 * u, x1, y1 + 10 * u), rayon, fill=(0, 0, 0, 110))
    img.alpha_composite(ombre.filter(ImageFilter.GaussianBlur(16 * u)))
    c = calque(*img.size)
    d = ImageDraw.Draw(c)
    d.rounded_rectangle(box, rayon, fill=(28, 31, 43, 150))
    # Reflet : dégradé blanc du haut vers le milieu de la carte
    reflet = Image.linear_gradient("L").resize((int(x1 - x0), int(y1 - y0))).point(lambda v: int((255 - v) * 0.075))
    masque = Image.new("L", reflet.size, 0)
    ImageDraw.Draw(masque).rounded_rectangle((0, 0, reflet.size[0] - 1, reflet.size[1] - 1), rayon, fill=255)
    blanc = Image.new("RGBA", reflet.size, (255, 255, 255, 0))
    blanc.putalpha(Image.composite(reflet, Image.new("L", reflet.size, 0), masque))
    c.alpha_composite(blanc, (int(x0), int(y0)))
    d.rounded_rectangle(box, rayon, outline=(255, 255, 255, 34), width=max(1, int(1.6 * u)))
    d.line((x0 + rayon, y0 + 1, x1 - rayon, y0 + 1), fill=(255, 255, 255, 46), width=max(1, int(1.4 * u)))
    img.alpha_composite(c)


def pastille(img, cx, cy, r, texte, u):
    """Numéro d'étape : disque en dégradé indigo avec halo."""
    halo = calque(*img.size)
    ImageDraw.Draw(halo).ellipse((cx - r * 1.5, cy - r * 1.5, cx + r * 1.5, cy + r * 1.5), fill=INDIGO + (90,))
    img.alpha_composite(halo.filter(ImageFilter.GaussianBlur(r * 0.7)))
    disque = Image.linear_gradient("L").resize((2 * r, 2 * r))
    couleur = Image.composite(Image.new("RGB", (2 * r, 2 * r), INDIGO_FONCE), Image.new("RGB", (2 * r, 2 * r), (160, 176, 255)), disque)
    masque = Image.new("L", (2 * r, 2 * r), 0)
    ImageDraw.Draw(masque).ellipse((0, 0, 2 * r - 1, 2 * r - 1), fill=255)
    img.paste(couleur, (cx - r, cy - r), masque)
    d = ImageDraw.Draw(img)
    d.text((cx, cy + 1), texte, font=police(r * 1.15, 800), fill=(255, 255, 255), anchor="mm")


def lame(img, x, y, l, h, u):
    """Signature : la lame lumineuse (dégradé indigo → violet, avec halo)."""
    halo = calque(*img.size)
    ImageDraw.Draw(halo).rounded_rectangle((x - 4 * u, y - 3 * u, x + l + 4 * u, y + h + 3 * u), h, fill=INDIGO + (150,))
    img.alpha_composite(halo.filter(ImageFilter.GaussianBlur(7 * u)))
    grad = Image.linear_gradient("L").rotate(90, expand=True).resize((int(l), int(h)))
    couleur = Image.composite(Image.new("RGB", grad.size, (183, 140, 255)), Image.new("RGB", grad.size, INDIGO), grad)
    masque = Image.new("L", grad.size, 0)
    ImageDraw.Draw(masque).rounded_rectangle((0, 0, grad.size[0] - 1, grad.size[1] - 1), int(h / 2), fill=255)
    img.paste(couleur, (int(x), int(y)), masque)
    ImageDraw.Draw(img).rounded_rectangle((x - 9 * u, y - 1.5 * u, x - 2 * u, y + h + 1.5 * u), int(2 * u), fill=TEXTE_3)


def etiquette(img, xy, texte, p, u, largeur_max):
    """Valeur importante (nom du Wi-Fi, mot de passe…) dans une étiquette claire."""
    d = ImageDraw.Draw(img)
    x, y = xy
    l = min(d.textlength(texte, font=p), largeur_max)
    asc, desc = p.getmetrics()
    hp, vp = 10 * u, 5 * u
    c = calque(*img.size)
    ImageDraw.Draw(c).rounded_rectangle((x - hp, y - vp, x + l + hp, y + asc + desc * .35 + vp), int(9 * u),
                                        fill=(255, 255, 255, 20), outline=(255, 255, 255, 26), width=1)
    img.alpha_composite(c)
    d.text((x, y), texte, font=p, fill=TEXTE_V)


def tuile_qr(img, x, y, cote, contenu, u):
    """QR code sur une tuile blanche arrondie, avec un halo indigo derrière."""
    pad = int(12 * u)
    halo = calque(*img.size)
    ImageDraw.Draw(halo).rounded_rectangle((x - pad - 6 * u, y - pad - 6 * u, x + cote + pad + 6 * u, y + cote + pad + 6 * u),
                                           int(26 * u), fill=INDIGO + (95,))
    img.alpha_composite(halo.filter(ImageFilter.GaussianBlur(18 * u)))
    ImageDraw.Draw(img).rounded_rectangle((x - pad, y - pad, x + cote + pad, y + cote + pad), int(18 * u), fill=(255, 255, 255))
    img.paste(qr(contenu, cote), (x, y))


def dessiner(W, H, etapes, qrs, chemin_image, note):
    """Image complète. etapes : [(num, titre, [(libellé, valeur)])] ; qrs : [(contenu, num, légende)]."""
    u = H / 1080                                  # tout est proportionnel à la hauteur
    img = fond_verre(W, H, chemin_image)
    d = ImageDraw.Draw(img)
    marge = int(80 * u)
    compact = len(etapes) > 3

    # En-tête : la lame + le nom, sous la barre des tâches (~36 px)
    y_titre = max(int(64 * u), 46)
    p_titre = police(66 * u, 800)
    lame(img, marge + 10 * u, y_titre + 30 * u, 54 * u, 9 * u, u)
    d.text((marge + 84 * u, y_titre), "Sabre Arbitrage", font=p_titre, fill=TEXTE_V)
    if H >= 720:
        d.text((marge + 84 * u, y_titre + 82 * u), "Rejoignez le Wi-Fi de la salle pour suivre et arbitrer les combats",
               font=police(30 * u, 500), fill=TEXTE_2)

    # Colonne de gauche : les étapes, en cartes de verre
    larg_g = int(W * 0.58) - marge
    x_txt = marge + int(104 * u)
    larg_txt = marge + larg_g - x_txt - int(28 * u)
    y = int((228 if compact else 252) * u) if H >= 720 else max(int((168 if compact else 196) * u), y_titre + int(104 * u))
    dispo = H - y - int((84 if note and not compact else 36) * u)   # place pour la note du bas
    ecart = int((16 if compact else 24) * u)
    rangees = [1 if compact else len(l) for _, _, l in etapes]
    unite_h = (dispo - ecart * (len(etapes) - 1)) / sum(92 * u + 100 * u * r for r in rangees) if etapes else 1
    k = min(1.0, unite_h)                          # resserre un peu si l'écran est très bas
    for (num, titre_etape, lignes), r in zip(etapes, rangees):
        h = int((92 + 100 * r) * u * k)
        carte_verre(img, (marge, y, marge + larg_g, y + h), int(22 * u), u)
        cy = y + int(46 * u * k)
        pastille(img, marge + int(52 * u), cy, max(9, int(25 * u)), num, u)
        d.text((x_txt, cy), titre_etape, font=police(34 * u, 750), fill=TEXTE_V, anchor="lm")
        ly = y + int(84 * u * k)
        n = len(lignes)
        for i, (lab, val) in enumerate(lignes):
            lx = x_txt + (i * (larg_txt // n) if compact else 0)
            larg = (larg_txt // n - int(26 * u)) if compact else larg_txt - int(20 * u)
            d.text((lx, ly), lab.upper(), font=police(21 * u, 700), fill=TEXTE_3)
            pv = ajuster(d, val, 40 * u * min(1, k + .1), larg, mono=True)
            etiquette(img, (lx, ly + int(32 * u * k)), val, pv, u, larg)
            if not compact:
                ly += int(100 * u * k)
        y += h + ecart

    # Colonne de droite : les QR codes, reliés aux étapes (1 et 3)
    gauche = int(W * 0.58) + int(40 * u)
    haut_qr = int(228 * u) if H >= 720 else max(int(150 * u), 52)
    place = H - haut_qr - int(30 * u)
    cote = int(min(330 * u, W - gauche - 60 * u, (place - 2 * 96 * u) / 2))
    cx = (gauche + W - int(20 * u)) // 2
    for i, (contenu, num, legende) in enumerate(qrs):
        qy = haut_qr + int(14 * u) + i * (cote + int(96 * u))
        tuile_qr(img, cx - cote // 2, qy, cote, contenu, u)
        ly = qy + cote + int(46 * u)
        p_leg = police(26 * u, 700)
        lt = d.textlength(legende, font=p_leg)
        r = max(8, int(15 * u))
        x_leg = cx - (lt + 2 * r + 12 * u) / 2
        pastille(img, int(x_leg + r), ly, r, num, u)
        d.text((x_leg + 2 * r + 12 * u, ly), legende, font=p_leg, fill=TEXTE_V, anchor="lm")

    if note and not compact:
        d.text((marge, H - int(52 * u)), note, font=police(24 * u, 500), fill=TEXTE_3)
    return img.convert("RGB")


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
    apercu = None
    if "--apercu" in sys.argv:
        args = sys.argv[sys.argv.index("--apercu") + 1:]
        apercu = args[0] if args else "/tmp/sabre-fond-apercu.png"
        if len(args) > 1 and re.match(r"^\d+x\d+$", args[1]):
            W, H = map(int, args[1].split("x"))

    # Rien n'a changé depuis la dernière image : on ne redessine pas
    signature = hashlib.sha256(repr((ssid, mdp_wifi, domaine, ident, mdp, compte_ident, compte_mdp, version_image,
                                     W, H, os.path.getmtime(__file__))).encode()).hexdigest()
    try:
        deja = open(SIGNATURE).read().strip() == signature and os.path.exists(SORTIE)
    except OSError:
        deja = False
    if deja and "--forcer" not in sys.argv and not apercu:
        return 0

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
    securite = "WPA" if mdp_wifi else "nopass"
    qr_wifi = f"WIFI:T:{securite};S:{echapper_wifi(ssid)};" + (f"P:{echapper_wifi(mdp_wifi)};" if mdp_wifi else "") + ";"
    qrs = [(qr_wifi, "1", "Rejoindre le Wi-Fi"), (url, "3", "Ouvrir le site")]
    img = dessiner(W, H, etapes, qrs, image_fond(client, version_image),
                   "Pas d'internet sur ce réseau : seul le site du tournoi est accessible.")

    if apercu:
        img.save(apercu)
        print(f"Aperçu : {apercu} ({W}x{H})")
        return 0

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
