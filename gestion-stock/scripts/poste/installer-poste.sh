#!/bin/bash
# Prépare le Raspberry Pi comme poste partagé et verrouillé de l'atelier. À lancer sur le Pi (relançable sans risque) :
#   sudo bash ~/gestion_stock/current/scripts/poste/installer-poste.sh
#
# 1. Poste verrouillé : un compte « poste » sans aucun droit s'ouvre seul et n'affiche que l'application,
#    en plein écran, sur le fond d'écran de l'établissement. Pas de barre des tâches, de menu, de terminal
#    ni de raccourci. Fenêtre fermée ou 10 minutes d'inactivité : elle se rouvre, déconnectée.
#    L'administrateur (celui qui lance ce script) garde la main par SSH : sudo gestion-stock-poste maintenance.
# 2. Navigateurs : aucun mot de passe enregistré ni rempli ; Chromium limité à l'application.
# 3. Mise à jour du système chaque nuit à 3 h 30, redémarrage la nuit si nécessaire.
# 4. Sécurité du système : pare-feu (site toujours ouvert sur 80 et 443, SSH depuis le réseau local seulement),
#    SSH par clé uniquement, services d'accès à distance et inutiles arrêtés, noyau durci, compte « poste » bridé.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "Lancez ce script avec sudo : sudo bash $0" >&2
    exit 1
fi
ADMIN=${POSTE_ADMIN:-${SUDO_USER:-cuto}}
ADMIN_HOME=$(getent passwd "$ADMIN" | cut -d: -f6)
[[ -d "$ADMIN_HOME" && "$ADMIN" != root ]] || { echo "Administrateur « $ADMIN » introuvable : lancez le script avec sudo depuis son compte." >&2; exit 1; }
KIOSK=poste
HERE=$(cd "$(dirname "$0")" && pwd)
SHARE=/usr/local/share/gestion-stock
ETC=/etc/gestion-stock
step() { echo; echo "==> $*"; }

step "Paquets nécessaires"
export DEBIAN_FRONTEND=noninteractive
apt-get -q update > /dev/null
apt-get -q -y install ufw swaybg swayidle curl > /dev/null
echo "    ufw, swaybg, swayidle"

# ---------- 1. Poste verrouillé ----------
step "Poste verrouillé (compte « $KIOSK »)"
install -d -m 755 "$SHARE" "$ETC" "$ETC/labwc"
install -m 644 "$HERE/fond-ecran.png" "$SHARE/fond-ecran.png"
echo "$ADMIN" > "$ETC/admin"

if ! id "$KIOSK" > /dev/null 2>&1; then
    adduser --disabled-password --gecos "Poste partagé" "$KIOSK" > /dev/null
    echo "    compte « $KIOSK » créé (sans mot de passe : il ne peut ouvrir que la session automatique)"
fi
# Accès à l'écran, au son et aux périphériques d'entrée ; ni sudo, ni docker, ni lpadmin.
for g in video render input audio; do getent group "$g" > /dev/null && usermod -a -G "$g" "$KIOSK"; done
for g in sudo docker adm lpadmin netdev plugdev gpio i2c spi dialout; do gpasswd -d "$KIOSK" "$g" > /dev/null 2>&1 || true; done
passwd -l "$KIOSK" > /dev/null
chmod 700 "$(getent passwd "$KIOSK" | cut -d: -f6)"

# Configuration de session en lecture seule pour le compte poste
for f in rc.xml menu.xml autostart; do install -m 644 "$HERE/labwc/$f" "$ETC/labwc/$f"; done
layout=$(sed -n 's/^XKB_DEFAULT_LAYOUT=//p' "$ADMIN_HOME/.config/labwc/environment" 2>/dev/null || true)
{
    grep -v '^XKB_DEFAULT_LAYOUT=' /etc/xdg/labwc/environment 2>/dev/null || true
    echo "XKB_DEFAULT_LAYOUT=${layout:-ch}"
} > "$ETC/labwc/environment"
chmod 644 "$ETC/labwc/environment"
install -m 755 "$HERE/kiosque.sh" /usr/local/bin/gestion-stock-kiosque
install -m 755 "$HERE/session.sh" /usr/local/bin/gestion-stock-session
install -d -m 755 /usr/share/wayland-sessions
cat > /usr/share/wayland-sessions/gestion-stock-poste.desktop <<'EOF'
[Desktop Entry]
Name=Poste Gestion de stock (verrouillé)
Comment=Application seule, en plein écran
Exec=/usr/local/bin/gestion-stock-session
Type=Application
EOF
install -m 755 "$HERE/gestion-stock-poste.sh" /usr/local/sbin/gestion-stock-poste

# Le compte poste ne peut rien administrer : clés USB, réseau, paquets, heure, alimentation...
install -d -m 755 /etc/polkit-1/rules.d
cat > /etc/polkit-1/rules.d/50-gestion-stock-poste.rules <<EOF
// Poste partagé : le compte « $KIOSK » n'a droit à aucune action d'administration.
polkit.addRule(function (action, subject) {
    if (subject.user !== "$KIOSK") return polkit.Result.NOT_HANDLED;
    var denied = ["org.freedesktop.udisks2.", "org.freedesktop.NetworkManager.", "org.freedesktop.packagekit.",
                  "org.freedesktop.login1.", "org.freedesktop.systemd1.", "org.freedesktop.timedate1.",
                  "org.freedesktop.hostname1.", "org.freedesktop.locale1.", "org.bluez.", "org.opensuse.cupspkhelper."];
    for (var i = 0; i < denied.length; i++) if (action.id.indexOf(denied[i]) === 0) return polkit.Result.NO;
    return polkit.Result.NOT_HANDLED;
});
EOF
# Ancienne version de ce script : l'application ne s'ouvre plus d'elle-même dans le bureau d'administration
rm -f "$ADMIN_HOME/.config/autostart/gestion-stock.desktop" /usr/local/bin/gestion-stock-lancer

# Fond d'écran aussi sur le bureau d'administration (mode maintenance)
conf_dir="$ADMIN_HOME/.config/pcmanfm/default"
sudo -u "$ADMIN" mkdir -p "$conf_dir"
for out in HDMI-A-1 HDMI-A-2; do
    f="$conf_dir/desktop-items-$out.conf"
    [[ -f "$f" ]] || sudo -u "$ADMIN" sh -c "printf '[*]\n' > '$f'"
done
for f in "$conf_dir"/desktop-items-*.conf; do
    for kv in "wallpaper=$SHARE/fond-ecran.png" "wallpaper_mode=crop" "wallpaper_common=1" "desktop_bg=#0b0c0f" "desktop_shadow=#0b0c0f" "desktop_fg=#f3f4f7"; do
        key=${kv%%=*}
        if grep -q "^$key=" "$f"; then sed -i "s|^$key=.*|$kv|" "$f"; else sed -i "/^\[\*\]/a $kv" "$f"; fi
    done
done
echo "    session verrouillée, fond d'écran, règles polkit"

# ---------- 2. Navigateurs ----------
step "Navigateurs : aucun mot de passe enregistré, Chromium limité à l'application"
install -d -m 755 /etc/chromium/policies/managed /etc/chromium/policies/recommended
install -m 644 "$HERE/chromium-poste-partage.json" /etc/chromium/policies/managed/gestion-stock-poste-partage.json
install -d -m 755 /etc/firefox/policies
install -m 644 "$HERE/firefox-policies.json" /etc/firefox/policies/policies.json
for f in /home/*/.mozilla/firefox/*/logins.json /home/*/.mozilla/firefox/*/logins-backup.json; do
    [[ -f "$f" ]] && rm -f "$f" && echo "    supprimé : $f"
done
echo "    règles en place (vérifiables dans chrome://policy en mode maintenance)"

# ---------- 3. Mise à jour chaque nuit ----------
step "Mise à jour automatique"
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
echo "    chaque nuit à 3 h 30"

# ---------- 4. Sécurité du système ----------
step "SSH : clé uniquement, administrateur seulement"
if [[ -s "$ADMIN_HOME/.ssh/authorized_keys" ]]; then
    cat > /etc/ssh/sshd_config.d/01-gestion-stock.conf <<EOF
# Poste Gestion de stock : connexion par clé, compte administrateur seulement (lu avant 50-cloud-init.conf).
PasswordAuthentication no
KbdInteractiveAuthentication no
PermitRootLogin no
AllowUsers $ADMIN
MaxAuthTries 3
LoginGraceTime 30
X11Forwarding no
AllowAgentForwarding no
EOF
    if sshd -t; then
        systemctl reload ssh
        echo "    mots de passe refusés en SSH, seul « $ADMIN » peut se connecter (avec sa clé)"
    else
        rm -f /etc/ssh/sshd_config.d/01-gestion-stock.conf
        echo "    ATTENTION : configuration SSH refusée par sshd -t, rien n'a été changé" >&2
    fi
else
    echo "    ATTENTION : aucune clé dans $ADMIN_HOME/.ssh/authorized_keys ; SSH laissé tel quel pour ne pas vous bloquer dehors" >&2
fi

step "Pare-feu"
# Réseaux locaux directement reliés (hors réseaux Docker) : seuls autorisés en SSH
lans=$(ip -4 route show proto kernel scope link | awk '$3 !~ /^(docker|br-|veth)/ {print $1}')
ufw default deny incoming > /dev/null
ufw default allow outgoing > /dev/null
ufw allow 80/tcp comment 'site Gestion de stock' > /dev/null
ufw allow 443/tcp comment 'site Gestion de stock (HTTPS)' > /dev/null
for net in $lans; do ufw allow proto tcp from "$net" to any port 22 comment 'SSH depuis le réseau local' > /dev/null; done
ufw --force enable > /dev/null
echo "    entrant refusé sauf : 80 et 443 (site, inchangé), SSH depuis ${lans:-?}"

step "Services inutiles ou d'accès à distance arrêtés"
for unit in wayvnc.service wayvnc-control.service vncserver-x11-serviced.service bluetooth.service \
            avahi-daemon.service avahi-daemon.socket rpcbind.service rpcbind.socket nfs-blkmap.service; do
    if systemctl cat "$unit" > /dev/null 2>&1; then
        systemctl disable --now "$unit" > /dev/null 2>&1 && echo "    arrêté : $unit" || true
    fi
done
systemctl --global disable rpi-connect.service rpi-connect-wayvnc.service > /dev/null 2>&1 || true

step "Noyau durci"
cat > /etc/sysctl.d/90-gestion-stock.conf <<'EOF'
# Poste Gestion de stock : réglages prudents (le routage nécessaire à Docker n'est pas touché).
kernel.dmesg_restrict = 1
kernel.kptr_restrict = 2
kernel.yama.ptrace_scope = 1
net.ipv4.tcp_syncookies = 1
net.ipv4.icmp_echo_ignore_broadcasts = 1
net.ipv4.conf.all.accept_redirects = 0
net.ipv4.conf.default.accept_redirects = 0
net.ipv4.conf.all.send_redirects = 0
net.ipv4.conf.all.accept_source_route = 0
net.ipv6.conf.all.accept_redirects = 0
net.ipv6.conf.default.accept_redirects = 0
net.ipv4.conf.all.log_martians = 1
EOF
sysctl --system > /dev/null 2>&1 || true
systemctl enable --quiet docker
echo "    fait"

# Le serveur (conteneurs) redémarre seul ; on vérifie qu'il répond toujours derrière le pare-feu
if curl -fsS -o /dev/null --max-time 5 http://localhost/api/health; then
    echo; echo "Site : répond normalement."
else
    echo; echo "ATTENTION : le site ne répond pas sur http://localhost/api/health" >&2
fi

step "Activation du poste verrouillé"
/usr/local/sbin/gestion-stock-poste verrouiller > /dev/null
echo "    l'écran redémarre sur l'application"

cat <<EOF

Terminé.
  Administrer à l'écran   : sudo gestion-stock-poste maintenance   (puis : sudo gestion-stock-poste verrouiller)
  Prochaine mise à jour   : systemctl list-timers gestion-stock-maj.timer
  Journal de mise à jour  : journalctl -u gestion-stock-maj -n 50
  Pare-feu                : sudo ufw status verbose
Conseil : donnez à « $ADMIN » un mot de passe solide (commande : passwd) : il protège sudo et l'écran de connexion.
EOF
