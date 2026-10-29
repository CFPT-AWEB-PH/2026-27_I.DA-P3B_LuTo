#!/usr/bin/env bash
# =============================================================================
# install_ecran.sh : écran du Raspberry + démarrage automatique du site
#
#   - Met à jour le système (apt full-upgrade), sauf avec --sans-maj
#   - Fond d'écran : nom + mot de passe du Wi-Fi, identifiant du portail,
#     QR code « rejoindre le Wi-Fi » et QR code « ouvrir le site »
#     (régénéré au démarrage et dès que le nom/mot de passe du Wi-Fi change)
#   - Site lancé au démarrage du Pi (service sabre-app : docker compose up -d)
#
# Usage (après install_wifi.sh) :  sudo bash raspberry/install_ecran.sh [--sans-maj]
# Compte affiché : /etc/sabre/ecran-compte (identifiant:mot de passe). Par défaut un
# compte de secours « invite » est créé ; pour en afficher un autre, écrire ce fichier
# (et l'ajouter au portail : sudo sabre-wifi secours-ajouter <id>).
# =============================================================================
set -euo pipefail

PROJET="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
bleu() { printf '\033[1;36m%s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✔\033[0m %s\n' "$*"; }
die()  { printf '\033[31mErreur :\033[0m %s\n' "$*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || die "lancez ce script avec sudo : sudo bash $0 $*"
[[ -f /etc/sabre/ap.env ]] || die "lancez d'abord raspberry/install_wifi.sh (point d'accès Wi-Fi)."
MAJ=1; [[ ${1:-} == --sans-maj ]] && MAJ=0
ECRAN_USER=${SUDO_USER:-$(stat -c %U "$PROJET")}

# ── 1. Mise à jour du système et paquets ─────────────────────────────────────
bleu "1/4 Paquets"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
if (( MAJ )); then
    apt-get full-upgrade -y -qq -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold >/dev/null
    ok "système à jour"
fi
apt-get install -y -qq python3-qrcode python3-pil fonts-dejavu-core >/dev/null
ok "python3-qrcode, python3-pil, polices DejaVu"

# ── 2. Fond d'écran ──────────────────────────────────────────────────────────
bleu "2/4 Fond d'écran"
install -m 755 "$PROJET/raspberry/sabre-fond-ecran.py" /usr/local/sbin/sabre-fond-ecran
printf 'ECRAN_USER="%s"\n' "$ECRAN_USER" > /etc/sabre/ecran.env
install -d -m 755 /var/lib/sabre-ecran

cat > /etc/systemd/system/sabre-fond-ecran.service <<'EOF'
[Unit]
Description=Sabre : fond d'écran avec les infos de connexion (Wi-Fi + QR codes)
After=sabre-ap-ip.service

[Service]
Type=oneshot
ExecStart=/usr/bin/python3 /usr/local/sbin/sabre-fond-ecran

[Install]
WantedBy=multi-user.target
EOF
# Admin › Wi-Fi modifié (nom, accès affiché…) : nouveau fond d'ici 15 s.
# Le script ne redessine que si quelque chose a changé.
cat > /etc/systemd/system/sabre-fond-ecran.timer <<'EOF'
[Unit]
Description=Sabre : fond d'écran à jour avec Admin › Wi-Fi (toutes les 15 s)

[Timer]
OnBootSec=25s
OnUnitActiveSec=15s
AccuracySec=1s

[Install]
WantedBy=timers.target
EOF
# Ancienne version (surveillance de /etc/sabre/ap.env seulement) : remplacée par le minuteur
systemctl disable --now sabre-fond-ecran.path >/dev/null 2>&1 || true
rm -f /etc/systemd/system/sabre-fond-ecran.path

# ── 3. Site lancé au démarrage ───────────────────────────────────────────────
bleu "3/4 Démarrage automatique du site"
cat > /etc/systemd/system/sabre-app.service <<EOF
[Unit]
Description=Sabre : application (docker compose) lancée au démarrage
After=docker.service network-online.target
Requires=docker.service
Wants=network-online.target

[Service]
Type=oneshot
RemainAfterExit=yes
WorkingDirectory=$PROJET
ExecStart=/usr/bin/docker compose up -d
TimeoutStartSec=600

[Install]
WantedBy=multi-user.target
EOF
systemctl daemon-reload
systemctl enable docker >/dev/null 2>&1 || true
systemctl enable sabre-app.service sabre-fond-ecran.service sabre-fond-ecran.timer >/dev/null
ok "services sabre-app, sabre-fond-ecran activés au démarrage"

# ── 4. Lancement ─────────────────────────────────────────────────────────────
bleu "4/4 Lancement"
(cd "$PROJET" && docker compose up -d --build)
systemctl start sabre-app.service
/usr/bin/python3 /usr/local/sbin/sabre-fond-ecran --forcer
systemctl start sabre-fond-ecran.timer
ok "site lancé, fond d'écran appliqué"
echo
echo "  Compte affiché sur l'écran : $(cut -d: -f1 /etc/sabre/ecran-compte 2>/dev/null || echo '?')"
echo "  Régénérer le fond          : sudo systemctl start sabre-fond-ecran"
echo "  Redémarrez pour vérifier   : sudo reboot"
