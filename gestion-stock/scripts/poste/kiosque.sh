#!/bin/sh
# Session verrouillée du poste : l'application en plein écran, rien d'autre (lancé par la session labwc du compte « poste »).
# Chaque ouverture de Chromium part d'un profil vide, effacé à la fermeture : aucun mot de passe, cookie ni historique
# ne survit. Fermer la fenêtre (Alt+F4) la rouvre aussitôt ; après 10 minutes sans clavier ni souris, elle se rouvre
# aussi : la personne qui a oublié de se déconnecter est déconnectée.
URL=http://localhost/
IDLE=${GESTION_STOCK_INACTIVITE:-600}
CACHE="$HOME/.cache"
mkdir -p "$CACHE"
rm -rf "$CACHE"/kiosque.*

swayidle -w timeout "$IDLE" "pkill -x -u $(id -u) chromium" &

while true; do
    # Le serveur démarre en même temps que l'écran : on l'attend (3 minutes au plus)
    i=0
    until curl -fsS -o /dev/null --max-time 2 "$URL" || [ $i -ge 90 ]; do i=$((i + 1)); sleep 2; done
    profile=$(mktemp -d "$CACHE/kiosque.XXXXXX")
    chromium --user-data-dir="$profile" --ozone-platform=wayland --kiosk --no-first-run --noerrdialogs --disable-session-crashed-bubble \
        --password-store=basic --disable-features=Translate,MediaRouter --check-for-update-interval=31536000 "$URL"
    rm -rf "$profile"
    sleep 1
done
