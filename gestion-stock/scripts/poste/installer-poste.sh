#!/bin/bash
# Prépare le Raspberry Pi comme poste partagé de l'atelier. À lancer sur le Pi, une fois (et à nouveau sans risque) :
#   sudo bash ~/gestion_stock/current/scripts/poste/installer-poste.sh
#
# 1. Fond d'écran de l'application.
# 2. Ouverture automatique de l'application à chaque démarrage (le serveur, lui, redémarre déjà seul : Docker).
# 3. Mise à jour automatique du système chaque nuit à 3 h 30, avec redémarrage si nécessaire.
# 4. Navigateurs sans mémoire des mots de passe : rien n'est proposé, rien n'est rempli, et à la fermeture
#    Chromium efface cookies, historique et mots de passe enregistrés. Au démarrage, plus personne n'est connecté.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Lancez ce script avec sudo : sudo bash $0" >&2
    exit 1
fi
USER_NAME=${POSTE_USER:-${SUDO_USER:-cuto}}
USER_HOME=$(getent passwd "$USER_NAME" | cut -d: -f6)
[[ -d "$USER_HOME" ]] || { echo "Utilisateur $USER_NAME introuvable." >&2; exit 1; }
HERE=$(cd "$(dirname "$0")" && pwd)
SHARE=/usr/local/share/gestion-stock
as_user() { sudo -u "$USER_NAME" "$@"; }

echo "==> Poste partagé pour l'utilisateur $USER_NAME"

# ---------- 1. Fond d'écran ----------
install -d -m 755 "$SHARE"
install -m 644 "$HERE/fond-ecran.png" "$SHARE/fond-ecran.png"
conf_dir="$USER_HOME/.config/pcmanfm/default"
as_user mkdir -p "$conf_dir"
# Un fichier par écran (desktop-items-<sortie>.conf) ; on couvre les écrans connus et les deux prises HDMI.
outputs=$(ls /sys/class/drm 2>/dev/null | sed -n 's/^card[0-9]*-\(HDMI-[A-Z]-[0-9]\)$/\1/p')
for out in HDMI-A-1 HDMI-A-2 $outputs; do
    f="$conf_dir/desktop-items-$out.conf"
    [[ -f "$f" ]] || as_user sh -c "printf '[*]\n' > '$f'"
done
for f in "$conf_dir"/desktop-items-*.conf; do
    for kv in "wallpaper=$SHARE/fond-ecran.png" "wallpaper_mode=crop" "wallpaper_common=1" "desktop_bg=#0b0c0f" "desktop_shadow=#0b0c0f" "desktop_fg=#f3f4f7"; do
        key=${kv%%=*}
        if grep -q "^$key=" "$f"; then sed -i "s|^$key=.*|$kv|" "$f"; else sed -i "/^\[\*\]/a $kv" "$f"; fi
    done
done
# Recharge le bureau s'il tourne (session graphique ouverte)
uid=$(id -u "$USER_NAME")
if [[ -S /run/user/$uid/wayland-0 ]]; then
    as_user env XDG_RUNTIME_DIR=/run/user/$uid WAYLAND_DISPLAY=wayland-0 timeout 10 pcmanfm --reconfigure > /dev/null 2>&1         && echo "    bureau rechargé" || true
fi
echo "    fond d'écran : $SHARE/fond-ecran.png"

# ---------- 2. Lancement automatique de l'application ----------
install -m 755 "$HERE/lancer-application.sh" /usr/local/bin/gestion-stock-lancer
as_user mkdir -p "$USER_HOME/.config/autostart"
as_user tee "$USER_HOME/.config/autostart/gestion-stock.desktop" > /dev/null <<'EOF'
[Desktop Entry]
Type=Application
Name=Gestion de stock
Comment=Ouvre l'application au démarrage du poste
Exec=/usr/local/bin/gestion-stock-lancer
X-GNOME-Autostart-enabled=true
NoDisplay=true
EOF
# Le serveur (conteneurs Docker) redémarre seul : Docker est activé au démarrage et les services sont en « unless-stopped ».
systemctl enable --quiet docker
echo "    l'application s'ouvrira à chaque démarrage"

# ---------- 3. Mise à jour chaque nuit ----------
install -m 755 "$HERE/mettre-a-jour.sh" /usr/local/sbin/gestion-stock-maj
cat > /etc/systemd/system/gestion-stock-maj.service <<'EOF'
[Unit]
Description=Mise à jour du système du poste Gestion de stock
Wants=network-online.target
After=network-online.target

[Service]
Type=oneshot
ExecStart=/usr/local/sbin/gestion-stock-maj
Nice=10
IOSchedulingClass=idle
TimeoutStartSec=2h
EOF
cat > /etc/systemd/system/gestion-stock-maj.timer <<'EOF'
[Unit]
Description=Mise à jour du système chaque nuit

[Timer]
OnCalendar=*-*-* 03:30
RandomizedDelaySec=20min
# Poste éteint la nuit : rattrapage au démarrage suivant (sans redémarrage en journée, voir le script)
Persistent=true

[Install]
WantedBy=timers.target
EOF
systemctl daemon-reload
systemctl enable --now --quiet gestion-stock-maj.timer
echo "    prochaine mise à jour : $(systemctl show gestion-stock-maj.timer -P NextElapseUSecRealtime)"

# ---------- 4. Aucun mot de passe enregistré dans les navigateurs ----------
install -d -m 755 /etc/chromium/policies/managed /etc/chromium/policies/recommended
install -m 644 "$HERE/chromium-poste-partage.json" /etc/chromium/policies/managed/gestion-stock-poste-partage.json
install -d -m 755 /etc/firefox/policies
install -m 644 "$HERE/firefox-policies.json" /etc/firefox/policies/policies.json
# Mots de passe déjà enregistrés : Chromium les efface à sa prochaine fermeture (règle ci-dessus) ;
# ceux de Firefox sont supprimés ici.
for f in "$USER_HOME"/.mozilla/firefox/*/logins.json "$USER_HOME"/.mozilla/firefox/*/logins-backup.json; do
    [[ -f "$f" ]] && rm -f "$f" && echo "    supprimé : $f"
done
echo "    Chromium et Firefox : enregistrement des mots de passe désactivé"

echo
echo "Terminé. Redémarrez pour tout vérifier : sudo reboot"
echo "Contrôles utiles :"
echo "  systemctl list-timers gestion-stock-maj.timer     # prochaine mise à jour"
echo "  journalctl -u gestion-stock-maj -n 50             # journal de la dernière mise à jour"
echo "  chromium : ouvrir chrome://policy                 # règles appliquées"
