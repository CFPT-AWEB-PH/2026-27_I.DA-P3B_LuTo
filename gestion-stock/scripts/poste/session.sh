#!/bin/sh
# Session Wayland du poste verrouillé : labwc avec la configuration de /etc/gestion-stock/labwc uniquement
# (pas de fusion avec ~/.config : le compte « poste » ne peut rien y ajouter).
if raspi-config nonint is_pi 2>/dev/null; then
    export WLR_DRM_FORCE_LIBLIFTOFF=1
    raspi-config nonint gpu_has_mmu || export WLR_RENDERER=pixman
fi
exec /usr/bin/labwc -C /etc/gestion-stock/labwc
