/**
 * live.js — Rafraîchissement temps réel des combats (scores + chrono)
 *
 * Principes :
 *  - Une seule boucle de décompte (rAF-friendly via setInterval 1s), quel que soit
 *    le nombre de cartes affichées sur la page.
 *  - Resynchronisation périodique avec le serveur (fetch), avec back-off si erreur.
 *  - Pause automatique quand l'onglet est masqué (économie CPU/réseau).
 *  - Respecte prefers-reduced-motion (pas d'appui sur des transitions custom en JS).
 *  - Annonces accessibles via aria-live pour les lecteurs d'écran (statut du combat).
 */

class LiveCombatTracker {
    /** @param {NodeListOf<Element>} cards */
    constructor(cards) {
        this.cards = Array.from(cards);
        this.basePollMs = (window.SABRE_CONFIG && window.SABRE_CONFIG.pollMs) || 4000;
        this.pollMs = this.basePollMs;
        this.errorStreak = 0;
        this.pollTimer = null;
        this.tickTimer = null;
        this.destroyed = false;
        this.offlineBanner = null;

        this.onVisibilityChange = this.onVisibilityChange.bind(this);
        document.addEventListener('visibilitychange', this.onVisibilityChange);
    }

    static formatTime(totalSeconds) {
        const s = Math.max(0, totalSeconds | 0);
        const m = Math.floor(s / 60).toString().padStart(2, '0');
        const r = (s % 60).toString().padStart(2, '0');
        return `${m}:${r}`;
    }

    start() {
        if (!this.cards.length) return;
        this.refresh();
        this.scheduleNextPoll();
        this.tickTimer = window.setInterval(() => this.localTick(), 1000);
    }

    destroy() {
        this.destroyed = true;
        window.clearTimeout(this.pollTimer);
        window.clearInterval(this.tickTimer);
        document.removeEventListener('visibilitychange', this.onVisibilityChange);
    }

    onVisibilityChange() {
        if (document.hidden) {
            window.clearTimeout(this.pollTimer);
        } else {
            this.refresh(); // resync immédiate au retour sur l'onglet
            this.scheduleNextPoll();
        }
    }

    scheduleNextPoll() {
        if (this.destroyed || document.hidden) return;
        window.clearTimeout(this.pollTimer);
        this.pollTimer = window.setTimeout(() => this.refresh(), this.pollMs);
    }

    /** Décompte visuel local, entre deux resynchronisations serveur. */
    localTick() {
        for (const card of this.cards) {
            const timer = card.querySelector('.js-timer');
            if (!timer) continue;
            let remaining = parseInt(timer.dataset.remaining || '0', 10);
            if (remaining > 0) {
                remaining -= 1;
                this.applyTimer(timer, remaining);
            }
        }
    }

    async refresh() {
        if (this.destroyed) return;
        const requests = this.cards.map((card) => this.refreshOne(card));
        await Promise.allSettled(requests);
        this.scheduleNextPoll();
    }

    async refreshOne(card) {
        const id = card.getAttribute('data-combat-id');
        if (!id) return;
        try {
            const res = await fetch(`api/get_combat_status.php?id=${encodeURIComponent(id)}`, {
                headers: { Accept: 'application/json' },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            const data = await res.json();
            if (data.error) throw new Error(data.error);
            this.errorStreak = 0;
            this.pollMs = this.basePollMs;
            this.setConnectivityBanner(card, false);
            this.applyUpdate(card, data);
        } catch (err) {
            this.errorStreak += 1;
            // Back-off doux en cas d'erreurs répétées (réseau instable, etc.)
            this.pollMs = Math.min(20000, this.basePollMs * Math.pow(1.5, Math.min(this.errorStreak, 5)));
            // Après 2 échecs consécutifs (~2 cycles d'actualisation), on ne
            // laisse plus le score/chrono sembler figé sans explication :
            // un bandeau visible + annoncé aux lecteurs d'écran apparaît.
            if (this.errorStreak >= 2) {
                this.setConnectivityBanner(card, true);
            }
        }
    }

    /** Affiche/masque un bandeau "connexion instable" sur une carte de combat. */
    setConnectivityBanner(card, show) {
        const status = card.querySelector('.js-live-status');
        let banner = card.querySelector('.js-offline-banner');
        if (show) {
            if (!banner) {
                banner = document.createElement('p');
                banner.className = 'js-offline-banner offline-banner';
                banner.innerHTML = '<svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-offline"></use></svg> Actualisation en direct indisponible — nouvelle tentative en cours…';
                card.prepend(banner);
            }
            if (status) status.textContent = 'Connexion instable : les scores affichés peuvent ne plus être à jour.';
        } else if (banner) {
            banner.remove();
        }
    }

    applyTimer(timerEl, remaining) {
        timerEl.dataset.remaining = String(remaining);
        timerEl.textContent = LiveCombatTracker.formatTime(remaining);
        timerEl.classList.toggle('urgent', remaining > 0 && remaining <= 10);
    }

    applyUpdate(card, data) {
        const score1 = card.querySelector('.js-score1');
        const score2 = card.querySelector('.js-score2');
        const timer = card.querySelector('.js-timer');
        const status = card.querySelector('.js-live-status');

        if (score1 && score1.textContent !== data.score_joueur1.toFixed(1)) {
            score1.textContent = data.score_joueur1.toFixed(1);
        }
        if (score2 && score2.textContent !== data.score_joueur2.toFixed(1)) {
            score2.textContent = data.score_joueur2.toFixed(1);
        }
        if (timer) {
            this.applyTimer(timer, data.temps_restant);
        }

        if (data.statut === 'termine') {
            if (status) {
                status.textContent = data.vainqueur_pseudo
                    ? `Combat terminé, vainqueur : ${data.vainqueur_pseudo}`
                    : 'Combat terminé, match nul';
            }
            // On laisse une seconde pour que l'annonce aria-live soit lue,
            // puis on recharge pour afficher la vue "terminé" complète.
            window.setTimeout(() => window.location.reload(), 900);
        }
    }
}

/**
 * Recharge la page quand la manche en cours change ou que le combat se termine
 * (utilisé sur l'écran de saisie des arbitres, pour rester synchronisé avec
 * les autres arbitres sans action manuelle).
 */
function watchMancheChange(combatId, currentManche) {
    const pollMs = (window.SABRE_CONFIG && window.SABRE_CONFIG.pollMs) || 4000;
    let consecutiveErrors = 0;
    const poll = async () => {
        try {
            const res = await fetch(`../api/get_combat_status.php?id=${encodeURIComponent(combatId)}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (!data.error && (data.manche_actuelle !== currentManche || data.statut !== 'en_cours')) {
                window.location.reload();
                return;
            }
            consecutiveErrors = 0;
        } catch (err) {
            // Après quelques échecs, on prévient l'arbitre que la page ne se
            // resynchronise plus automatiquement plutôt que d'échouer en silence.
            consecutiveErrors += 1;
            if (consecutiveErrors === 2) {
                const status = document.querySelector('.js-live-status');
                if (status) status.textContent = 'Connexion instable : la page ne se resynchronise plus automatiquement, pensez à la recharger.';
            }
        }
        if (!document.hidden) window.setTimeout(poll, pollMs);
    };
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) poll();
    });
    poll();
}

document.addEventListener('DOMContentLoaded', () => {
    const cards = document.querySelectorAll('[data-combat-id]');
    if (cards.length) {
        const tracker = new LiveCombatTracker(cards);
        tracker.start();
    }
});

// Exposé pour les pages qui en ont besoin (arbitrage/saisie.php)
window.watchMancheChange = watchMancheChange;
