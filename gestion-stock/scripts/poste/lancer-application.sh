#!/bin/sh
# Ouvre Gestion de stock à l'ouverture de session du poste (lancé par ~/.config/autostart/gestion-stock.desktop).
URL=http://localhost/
PROFILE="$HOME/.config/chromium/Default"

# Si Chromium a été coupé net (coupure de courant), pas de bulle « Restaurer les pages ? »
if [ -f "$PROFILE/Preferences" ]; then
    sed -i 's/"exit_type":"Crashed"/"exit_type":"Normal"/; s/"exited_cleanly":false/"exited_cleanly":true/' "$PROFILE/Preferences"
fi
# Poste partagé : personne ne reste connecté d'une session à l'autre, même après une coupure
rm -f "$PROFILE/Cookies" "$PROFILE/Cookies-journal"

# Attend que le serveur réponde (les conteneurs démarrent en même temps que le bureau), 3 minutes au plus
i=0
until curl -fsS -o /dev/null --max-time 2 "$URL" || [ $i -ge 90 ]; do
    i=$((i + 1)); sleep 2
done

# Application installée (PWA) si elle l'est, sinon fenêtre d'application simple
desktop=$(grep -l "app-launch-url-for-shortcuts-menu-item=http://localhost/" "$HOME"/.local/share/applications/chrome-*-Default.desktop 2>/dev/null | head -n 1)
app_id=$(basename "${desktop:-}" | sed -n 's/^chrome-\(.*\)-Default\.desktop$/\1/p')
if [ -n "$app_id" ]; then
    exec chromium --profile-directory=Default --app-id="$app_id" --start-maximized --no-first-run
fi
exec chromium --app="$URL" --start-maximized --no-first-run
