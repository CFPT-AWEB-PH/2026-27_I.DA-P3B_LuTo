#!/usr/bin/env bash
# =============================================================================
# install_raccourci.sh : bureau épuré du Raspberry + raccourci vers le site
#
#   - Raccourci « Sabre Arbitrage » (sur le bureau et dans la barre des tâches) :
#     ouvre le site dans le navigateur, comme un favori. Rien ne s'ouvre tout seul.
#   - Plus d'autres icônes sur le bureau (fichiers, corbeille, clés USB)
#     ni de raccourcis navigateur / fichiers / terminal dans la barre des tâches
#
# Usage (SANS sudo, avec l'utilisateur du bureau) :
#   bash raspberry/install_raccourci.sh                  site : http://localhost:8080/
#   bash raspberry/install_raccourci.sh --url http://localhost:8080/ecran.php
#   bash raspberry/install_raccourci.sh --desinstaller   remet le bureau comme avant
# Les fichiers du dossier Bureau ne sont ni déplacés ni supprimés : ils sont juste masqués.
# =============================================================================
set -euo pipefail

PROJET="$(cd "$(dirname "$(readlink -f "$0")")/.." && pwd)"
PORT=$(grep -E '^APP_PORT=' "$PROJET/.env" 2>/dev/null | cut -d= -f2 || true)
URL="http://localhost:${PORT:-8080}/"
DESINSTALLER=0
while (( $# )); do
    case $1 in
        --url) URL=$2; shift ;;
        --desinstaller) DESINSTALLER=1 ;;
        *) echo "option inconnue : $1" >&2; exit 1 ;;
    esac
    shift
done
[[ $EUID -ne 0 ]] || { echo "Erreur : lancez ce script SANS sudo (utilisateur du bureau)." >&2; exit 1; }

CONF=~/.config
APPLI=~/.local/share/applications/sabre-site.desktop
BUREAU_VIDE=~/.local/share/sabre-bureau-vide
PANEL=$CONF/wf-panel-pi/wf-panel-pi.ini
PCMANFM=$CONF/pcmanfm/default/pcmanfm.conf

# Réglage cle=valeur dans la 1re section d'un fichier de configuration de pcmanfm
reglage() {   # reglage FICHIER CLE VALEUR
    if grep -qE "^$2=" "$1"; then sed -i "s|^$2=.*|$2=$3|" "$1"; else sed -i "1a $2=$3" "$1"; fi
}

# Ancienne version (ouverture automatique en plein écran) : retirée
rm -f "$CONF/autostart/sabre-kiosque.desktop" ~/.local/bin/sabre-kiosque

# ── Retour arrière ───────────────────────────────────────────────────────────
if (( DESINSTALLER )); then
    rm -f "$APPLI" "$BUREAU_VIDE/sabre-site.desktop"
    for f in "$CONF"/pcmanfm/default/desktop-items-*.conf; do
        [[ -f $f ]] || continue
        sed -i '/^folder=/d' "$f"; reglage "$f" show_trash 1; reglage "$f" show_mounts 1
    done
    [[ -f $PANEL ]] && sed -i 's|^launchers=.*|launchers=x-www-browser pcmanfm x-terminal-emulator|' "$PANEL"
    echo "Bureau remis comme avant (effectif au prochain démarrage)."
    exit 0
fi

# ── Raccourci vers le site (fenêtre normale du navigateur) ───────────────────
mkdir -p "$(dirname "$APPLI")" "$BUREAU_VIDE"
cat > "$APPLI" <<EOF
[Desktop Entry]
Type=Application
Name=Sabre Arbitrage
Comment=Ouvrir le site du tournoi
Exec=x-www-browser $URL
Icon=$PROJET/assets/pwa/icon-192.png
Terminal=false
Categories=Network;WebBrowser;
EOF
cp -f "$APPLI" "$BUREAU_VIDE/sabre-site.desktop"
chmod 755 "$APPLI" "$BUREAU_VIDE/sabre-site.desktop"
# Double-clic sur le raccourci du bureau : ouvre directement (sans « Exécuter ? »)
[[ -f $PCMANFM ]] && reglage "$PCMANFM" quick_exec 1
echo "  ✔ raccourci « Sabre Arbitrage » → $URL"

# ── Bureau : seul le raccourci est affiché ───────────────────────────────────
LARGEUR=$(cat /sys/class/drm/*/modes 2>/dev/null | head -1 | cut -dx -f1)
for f in "$CONF"/pcmanfm/default/desktop-items-*.conf; do
    [[ -f $f ]] || continue
    reglage "$f" folder "$BUREAU_VIDE"
    for cle in show_trash show_mounts show_documents show_home; do reglage "$f" "$cle" 0; done
    # Icône dans la marge de droite du fond d'écran (à droite des QR codes)
    sed -i '/^\[sabre-site.desktop\]/,/^$/d' "$f"
    printf '\n[sabre-site.desktop]\nx=%d\ny=60\n\n' $(( ${LARGEUR:-800} - 84 )) >> "$f"
done
echo "  ✔ autres icônes du bureau masquées (le dossier Bureau est intact)"

# ── Barre des tâches : seulement le raccourci du site ────────────────────────
if [[ -f $PANEL ]] && grep -q '^launchers=' "$PANEL"; then sed -i 's|^launchers=.*|launchers=sabre-site|' "$PANEL"
elif [[ -f $PANEL ]]; then sed -i '/^\[panel\]/a launchers=sabre-site' "$PANEL"
else mkdir -p "$(dirname "$PANEL")"; printf '[panel]\nlaunchers=sabre-site\n' > "$PANEL"; fi
echo "  ✔ barre des tâches : seulement le raccourci du site"
echo "Redémarrez le Pi (ou fermez/rouvrez la session) pour appliquer."
