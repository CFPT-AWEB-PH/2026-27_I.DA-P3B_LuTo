/**
 * pwa.js : Progressive Web App
 *  - Enregistre le Service Worker (sw.js, à la racine de l'application)
 *  - Bouton « Installer l'application » ([data-pwa-install]) :
 *      Android / Chrome / Edge : vraie invite d'installation du navigateur
 *      iPhone / iPad (Safari)  : explication « Partager › Sur l'écran d'accueil »
 *
 * Le Service Worker exige HTTPS (ou localhost) : en HTTP simple, l'app
 * fonctionne normalement, elle n'est juste pas installable.
 */
(() => {
    'use strict';

    // Racine de l'application, déduite de l'URL de ce script (…/assets/js/pwa.js)
    const script = document.currentScript;
    const base = script ? new URL('../../', script.src).pathname : '/';

    if ('serviceWorker' in navigator && window.isSecureContext) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch((err) => {
                console.warn('[PWA] Service Worker non enregistré :', err);
            });
        });
    }

    const estInstallee = () =>
        window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const estIOS = /iphone|ipad|ipod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);

    let invite = null;
    const boutons = () => document.querySelectorAll('[data-pwa-install]');
    const afficherBoutons = (visible) => boutons().forEach(b => { b.hidden = !visible; });

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();          // on garde l'invite pour notre propre bouton
        invite = e;
        afficherBoutons(true);
    });

    window.addEventListener('appinstalled', () => {
        invite = null;
        afficherBoutons(false);
        window.Toast?.success('Application installée sur cet appareil.');
    });

    document.addEventListener('DOMContentLoaded', () => {
        if (estInstallee()) { afficherBoutons(false); return; }
        // Pas d'invite automatique (Safari iOS, ou app en HTTP sur le Wi-Fi du tournoi) :
        // le bouton explique comment l'ajouter à l'écran d'accueil
        if (estIOS || !window.isSecureContext) afficherBoutons(true);
    });

    document.addEventListener('click', async (e) => {
        const btn = e.target.closest('[data-pwa-install]');
        if (!btn) return;
        e.preventDefault();
        if (invite) {
            invite.prompt();
            const { outcome } = await invite.userChoice;
            invite = null;
            if (outcome === 'accepted') afficherBoutons(false);
            return;
        }
        const message = estIOS ? 'Installer sur iPhone / iPad' : 'Ajouter à l\'écran d\'accueil';
        const texte = estIOS
            ? 'Dans Safari, touchez le bouton Partager (carré avec une flèche vers le haut), '
              + 'puis « Sur l\'écran d\'accueil ». L\'application s\'ouvrira ensuite en plein écran, comme une app.'
            : 'Dans Chrome, touchez le menu ⋮ (en haut à droite), puis « Ajouter à l\'écran d\'accueil ». '
              + 'Une icône Sabre Arbitrage apparaît sur votre téléphone.';
        if (window.Modal) await window.Modal.show({ message, text: texte, confirmLabel: 'Compris', type: 'info' });
        else alert(message + '\n\n' + texte);
    });
})();
