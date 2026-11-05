// Progressive Web App : enregistrement du service worker, proposition de mise à jour, installation.
// Les navigateurs n'exposent le service worker qu'en contexte sécurisé (HTTPS ou localhost) :
// en HTTP sur le réseau local, tout ceci reste inactif et l'application fonctionne normalement.

import { toast } from './ui.js';

let installEvent = null;
const installListeners = new Set();

export const pwaSupported = () => 'serviceWorker' in navigator;

export const isInstalled = () => window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;

export const canInstall = () => installEvent !== null;

/** Prévient quand la proposition d'installation devient disponible (ou ne l'est plus). */
export function onInstallChange(listener) {
  installListeners.add(listener);
}

export async function promptInstall() {
  const event = installEvent;
  if (!event) return;
  installEvent = null;
  installListeners.forEach((l) => l());
  event.prompt();
  await event.userChoice;
}

export function initPwa() {
  window.addEventListener('beforeinstallprompt', (event) => {
    event.preventDefault();
    installEvent = event;
    installListeners.forEach((l) => l());
  });
  window.addEventListener('appinstalled', () => {
    installEvent = null;
    installListeners.forEach((l) => l());
    toast('Application installée.');
  });
  if (!pwaSupported()) return;

  window.addEventListener('load', async () => {
    let registration;
    try {
      registration = await navigator.serviceWorker.register('/sw.js');
    } catch (error) {
      console.error('Service worker non enregistré', error);
      return;
    }
    const propose = (worker) => {
      toast('Une nouvelle version de l’application est disponible.', 'info', {
        action: { label: 'Mettre à jour', run: () => worker.postMessage('SKIP_WAITING') },
      });
    };
    // Une version déjà téléchargée attend depuis une visite précédente
    if (registration.waiting && navigator.serviceWorker.controller) propose(registration.waiting);
    registration.addEventListener('updatefound', () => {
      const worker = registration.installing;
      worker?.addEventListener('statechange', () => {
        // Sans contrôleur, c'est la toute première installation : rien à proposer
        if (worker.state === 'installed' && navigator.serviceWorker.controller) propose(worker);
      });
    });
    // Recharge seulement quand l'utilisateur a accepté, pas lors de la première prise de contrôle
    const hadController = Boolean(navigator.serviceWorker.controller);
    let reloading = false;
    navigator.serviceWorker.addEventListener('controllerchange', () => {
      if (!hadController || reloading) return;
      reloading = true;
      location.reload();
    });
    // Une application installée reste ouverte longtemps : vérifier en revenant dessus
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') registration.update().catch(() => {});
    });
  });
}
