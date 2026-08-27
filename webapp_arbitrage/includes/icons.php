<?php
/**
 * Sprite SVG inline — jeu d'icônes unique de l'application.
 * Style : traits (stroke) 1.5px, grille 24x24, aucune icône "remplie" fantaisiste,
 * aucun emoji nulle part dans l'app : toutes les icônes passent par ce sprite,
 * via la fonction icon($nom, $class) définie dans includes/functions.php.
 * Inclus une seule fois, juste après <body>, dans includes/header.php.
 */
?>
<svg xmlns="http://www.w3.org/2000/svg" class="visually-hidden" aria-hidden="true" focusable="false">
<defs>
<symbol id="icon-home" viewBox="0 0 24 24"><path d="M3 11.5 12 4l9 7.5" /><path d="M5.5 9.5V20h13V9.5" /><path d="M9.5 20v-6h5v6" /></symbol>
<symbol id="icon-bracket" viewBox="0 0 24 24"><circle cx="5" cy="6" r="2" /><circle cx="5" cy="18" r="2" /><circle cx="19" cy="12" r="2" /><path d="M7 6h4a2 2 0 0 1 2 2v0" /><path d="M7 18h4a2 2 0 0 0 2-2v0" /><path d="M13 8v8" /><path d="M13 12h4" /></symbol>
<symbol id="icon-trophy" viewBox="0 0 24 24"><path d="M7 4h10v5a5 5 0 0 1-10 0Z" /><path d="M7 5H4v2a3 3 0 0 0 3 3" /><path d="M17 5h3v2a3 3 0 0 1-3 3" /><path d="M12 14v3" /><path d="M9 20h6" /><path d="M9.5 17h5l.5 3H9Z" /></symbol>
<symbol id="icon-chart" viewBox="0 0 24 24"><path d="M4 20V10" /><path d="M10 20V6" /><path d="M16 20v-8" /><path d="M20 20V4" /><path d="M3 20h18" /></symbol>
<symbol id="icon-monitor" viewBox="0 0 24 24"><rect x="3" y="4.5" width="18" height="12" rx="1.5" /><path d="M9 20h6" /><path d="M12 16.5V20" /></symbol>
<symbol id="icon-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="8" height="8" rx="1.5" /><rect x="13" y="3" width="8" height="5" rx="1.5" /><rect x="13" y="10" width="8" height="11" rx="1.5" /><rect x="3" y="13" width="8" height="8" rx="1.5" /></symbol>
<symbol id="icon-target" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8" /><circle cx="12" cy="12" r="4" /><circle cx="12" cy="12" r="0.6" fill="currentColor" /></symbol>
<symbol id="icon-user" viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5" /><path d="M5 20c0-3.6 3.1-6.5 7-6.5s7 2.9 7 6.5" /></symbol>
<symbol id="icon-users" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3" /><path d="M3.5 19c0-3.3 2.5-5.8 5.5-5.8s5.5 2.5 5.5 5.8" /><circle cx="17" cy="9" r="2.4" /><path d="M15.2 13.6c2.5.2 4.3 2.4 4.3 5.4" /></symbol>
<symbol id="icon-login" viewBox="0 0 24 24"><path d="M14 4h4a1.5 1.5 0 0 1 1.5 1.5v13A1.5 1.5 0 0 1 18 20h-4" /><path d="M10 8l4 4-4 4" /><path d="M14 12H3" /></symbol>
<symbol id="icon-logout" viewBox="0 0 24 24"><path d="M10 20H6a1.5 1.5 0 0 1-1.5-1.5v-13A1.5 1.5 0 0 1 6 4h4" /><path d="M14 16l4-4-4-4" /><path d="M18 12H8" /></symbol>
<symbol id="icon-user-plus" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5" /><path d="M3 20c0-3.6 2.7-6.5 6-6.5s6 2.9 6 6.5" /><path d="M18 8v6" /><path d="M15 11h6" /></symbol>
<symbol id="icon-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" /><path d="M12 7v5l3.2 2" /></symbol>
<symbol id="icon-clock-alert" viewBox="0 0 24 24"><circle cx="11" cy="13" r="7.5" /><path d="M11 9v4l2.6 1.6" /><path d="M8 2.5h6" /><path d="M11 2.5V5" /></symbol>
<symbol id="icon-history" viewBox="0 0 24 24"><path d="M4 12a8 8 0 1 0 2.6-5.9" /><path d="M3 4v4h4" /><path d="M12 8v4.5l3 2" /></symbol>
<symbol id="icon-check" viewBox="0 0 24 24"><path d="M4.5 12.5l5 5 10-11" /></symbol>
<symbol id="icon-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" /><path d="M8 12.3l2.8 2.8L16.5 9" /></symbol>
<symbol id="icon-x" viewBox="0 0 24 24"><path d="M5.5 5.5l13 13" /><path d="M18.5 5.5l-13 13" /></symbol>
<symbol id="icon-x-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" /><path d="M9 9l6 6" /><path d="M15 9l-6 6" /></symbol>
<symbol id="icon-corner" viewBox="0 0 24 24"><path d="M12 4l8 15H4Z" /></symbol>
<symbol id="icon-chevron-left" viewBox="0 0 24 24"><path d="M14.5 5.5l-7 6.5 7 6.5" /></symbol>
<symbol id="icon-chevron-right" viewBox="0 0 24 24"><path d="M9.5 5.5l7 6.5-7 6.5" /></symbol>
<symbol id="icon-chevron-down" viewBox="0 0 24 24"><path d="M5.5 9.5l6.5 7 6.5-7" /></symbol>
<symbol id="icon-swap" viewBox="0 0 24 24"><path d="M4 8h14" /><path d="M14 4l4 4-4 4" /><path d="M20 16H6" /><path d="M10 20l-4-4 4-4" /></symbol>
<symbol id="icon-refresh" viewBox="0 0 24 24"><path d="M4 12a8 8 0 0 1 14-5.2" /><path d="M20 12a8 8 0 0 1-14 5.2" /><path d="M18 3v4h-4" /><path d="M6 21v-4h4" /></symbol>
<symbol id="icon-medal" viewBox="0 0 24 24"><circle cx="12" cy="15" r="5.5" /><path d="M12 11v0" /><path d="M9 4h6l-2.2 6.2h-1.6Z" /></symbol>
<symbol id="icon-broadcast" viewBox="0 0 24 24"><circle cx="12" cy="12" r="2" /><path d="M8.5 8.5a5 5 0 0 0 0 7" /><path d="M15.5 8.5a5 5 0 0 1 0 7" /><path d="M5.5 5.5a9.5 9.5 0 0 0 0 13" /><path d="M18.5 5.5a9.5 9.5 0 0 1 0 13" /></symbol>
<symbol id="icon-download" viewBox="0 0 24 24"><path d="M12 4v11" /><path d="M7.5 11l4.5 4.5 4.5-4.5" /><path d="M5 19.5h14" /></symbol>
<symbol id="icon-upload" viewBox="0 0 24 24"><path d="M12 20V9" /><path d="M7.5 13l4.5-4.5 4.5 4.5" /><path d="M5 4.5h14" /></symbol>
<symbol id="icon-alert-triangle" viewBox="0 0 24 24"><path d="M12 4.5l9 15.5H3Z" /><path d="M12 10v4" /><path d="M12 17v0" /></symbol>
<symbol id="icon-settings" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3" /><path d="M12 3.5v2.4M12 18.1v2.4M20.5 12h-2.4M5.9 12H3.5M18 6l-1.7 1.7M7.7 16.3 6 18M18 18l-1.7-1.7M7.7 7.7 6 6" /></symbol>
<symbol id="icon-bolt" viewBox="0 0 24 24"><path d="M13 3 5 13.5h5.5L11 21l8-11h-5.5Z" /></symbol>
<symbol id="icon-referee-badge" viewBox="0 0 24 24"><rect x="6" y="3.5" width="12" height="15" rx="2" /><circle cx="12" cy="9.5" r="2.5" /><path d="M9 15.5h6" /></symbol>
<symbol id="icon-user-check" viewBox="0 0 24 24"><circle cx="9" cy="8" r="3.5" /><path d="M3 20c0-3.6 2.7-6.5 6-6.5" /><path d="M14.5 15.5l2 2 4-4" /></symbol>
<symbol id="icon-plus" viewBox="0 0 24 24"><path d="M12 5v14" /><path d="M5 12h14" /></symbol>
<symbol id="icon-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8.5" /><path d="M9.3 9a2.7 2.7 0 1 1 3.9 2.4c-.9.5-1.2 1-1.2 2" /><path d="M12 17v0" /></symbol>
<symbol id="icon-shield" viewBox="0 0 24 24"><path d="M12 3.5 19 6v6c0 5-3.5 7.7-7 8.5-3.5-.8-7-3.5-7-8.5V6Z" /></symbol>
<symbol id="icon-shuffle" viewBox="0 0 24 24"><path d="M3.5 6.5h4l9 11h4" /><path d="M3.5 17.5h4l3-3.6" /><path d="M13 8.4l2.5-2.9h4" /><path d="M17 3.5 20.5 6 17 8.5" /><path d="M17 15.5 20.5 18 17 20.5" /></symbol>
<symbol id="icon-sliders" viewBox="0 0 24 24"><path d="M4 6h9M17 6h3M4 18h3M11 18h9" /><circle cx="14" cy="6" r="2" /><circle cx="8" cy="18" r="2" /></symbol>
<symbol id="icon-offline" viewBox="0 0 24 24"><path d="M3 3l18 18" /><path d="M5.1 9.1a13.5 13.5 0 0 1 4-2.4" /><path d="M12.5 5.6c3 .1 5.8 1.4 7.9 3.5" /><path d="M8.2 12.2a8 8 0 0 1 4.7-1.9" /><path d="M11.2 15.3a4.3 4.3 0 0 1 4.5.4" /><circle cx="12" cy="19" r="1" fill="currentColor" stroke="none" /></symbol>
<symbol id="icon-saber" viewBox="0 0 24 24"><path d="M3.5 20.5 14 10" /><path d="M15 5l4 4" /><path d="M14 10l5-5 .6 1.6L21 8l-2.4 1.4L17 11l-3-1Z" /></symbol>
<symbol id="icon-eye" viewBox="0 0 24 24"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z" /><circle cx="12" cy="12" r="3" /></symbol>
<symbol id="icon-eye-off" viewBox="0 0 24 24"><path d="M3 3l18 18" /><path d="M10.6 5.7A10.6 10.6 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a15.4 15.4 0 0 1-3.3 4.1" /><path d="M6.6 7.6C4 9.4 2.5 12 2.5 12S6 18.5 12 18.5a9.9 9.9 0 0 0 3.4-.6" /><path d="M9.9 10a3 3 0 0 0 4.1 4.1" /></symbol>
<symbol id="icon-lock" viewBox="0 0 24 24"><rect x="5" y="10.5" width="14" height="9.5" rx="2" /><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5" /></symbol>
<symbol id="icon-unlock" viewBox="0 0 24 24"><rect x="5" y="10.5" width="14" height="9.5" rx="2" /><path d="M8 10.5V7a4 4 0 0 1 7.4-2.1" /></symbol>
<symbol id="icon-edit" viewBox="0 0 24 24"><path d="M4 20l.9-4L16.5 4.4a1.7 1.7 0 0 1 2.4 0l.7.7a1.7 1.7 0 0 1 0 2.4L8 19.1Z" /><path d="M14.5 6.5l3 3" /></symbol>
<symbol id="icon-trash" viewBox="0 0 24 24"><path d="M5 7h14" /><path d="M9 7V5a1.5 1.5 0 0 1 1.5-1.5h3A1.5 1.5 0 0 1 15 5v2" /><path d="M7 7l1 13h8l1-13" /><path d="M10 11v6M14 11v6" /></symbol>
</defs>
</svg>
