/**
 * live.js : Rafraîchissement temps réel des combats (scores + chrono)
 *
 * Principes :
 *  - Une seule boucle de décompte (rAF-friendly via setInterval 1s), quel que soit
 *    le nombre de cartes affichées sur la page.
 *  - Resynchronisation périodique avec le serveur (fetch), avec back-off si erreur.
 *  - Pause automatique quand l'onglet est masqué (économie CPU/réseau).
 *  - Respecte prefers-reduced-motion (pas d'appui sur des transitions custom en JS).
 *  - Annonces accessibles via aria-live pour les lecteurs d'écran (statut du combat).
 */

/** 12 plutôt que 12.0 */
function fmtScore(v) { const n = Number(v); return Number.isInteger(n) ? String(n) : String(Math.round(n * 100) / 100); }

/**
 * Met à jour la barre « qui gagne » (rouge Aka / bleu Ao) et les points de
 * victoire. d = { j1, j2, leader, pv, avantage }. scope = élément parent.
 */
function majBarreAvantage(d, scope) {
    (scope || document).querySelectorAll('.js-lead').forEach(el => {
        const total = Number(d.j1) + Number(d.j2);
        const part = total > 0 ? Number(d.j1) / total * 100 : 50;
        el.dataset.leader = d.leader;
        const aka = el.querySelector('.js-lead-aka');
        if (aka) aka.style.width = part.toFixed(1) + '%';
        const txt = el.querySelector('.js-lead-text');
        if (txt && d.avantage) txt.textContent = d.avantage;
        const bar = el.querySelector('.lead-bar');
        if (bar && d.avantage) bar.setAttribute('aria-label', d.avantage);
        el.querySelectorAll('.js-lead-pv i').forEach((i, k) => i.classList.toggle('on', d.pv > k));
    });
}
window.majBarreAvantage = majBarreAvantage;
window.fmtScore = fmtScore;

const LIVE_BASE = (window.SABRE_CONFIG && window.SABRE_CONFIG.baseUrl) || '/';

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
            if (!timer || timer.dataset.paused === '1') continue; // combat en pause : chrono figé
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
            const res = await fetch(`${LIVE_BASE}api/get_combat_status.php?id=${encodeURIComponent(id)}`, {
                cache: 'no-store',
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

    /**
     * Connexion instable : un seul indicateur global (window.ConnStatus) au lieu
     * d'un bandeau répété dans chaque carte, + annonce pour les lecteurs d'écran.
     */
    setConnectivityBanner(card, show) {
        const status = card.querySelector('.js-live-status');
        if (show) {
            window.ConnStatus && window.ConnStatus.lost();
            if (status) status.textContent = 'Connexion instable : les scores affichés peuvent ne plus être à jour.';
        } else if (window.ConnStatus && window.ConnStatus.isLost) {
            window.ConnStatus.ok();
            if (status) status.textContent = '';
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

        if (data.statut !== 'a_venir') {
            [[score1, data.score_joueur1], [score2, data.score_joueur2]].forEach(([el, v]) => {
                if (el && el.textContent.trim() !== fmtScore(v)) {
                    el.textContent = fmtScore(v);
                    window.bumpScore && window.bumpScore(el);
                }
            });
            majBarreAvantage({ j1: data.score_joueur1, j2: data.score_joueur2, leader: data.leader, pv: data.pv, avantage: data.avantage }, card);
        }
        if (timer && data.statut === 'en_cours') {
            if (data.en_pause) timer.dataset.paused = '1'; else delete timer.dataset.paused;
            this.applyTimer(timer, data.temps_restant);
        }

        // Le combat vient de changer d'état (démarrage automatique à l'heure
        // prévue, ou fin) : on recharge pour afficher la bonne vue.
        const statutAffiche = card.dataset.statut;
        if (statutAffiche && statutAffiche !== data.statut && data.statut !== 'termine') {
            window.setTimeout(() => window.location.reload(), 300);
            return;
        }

        if (data.statut === 'termine' && statutAffiche !== 'termine') {
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
function watchMancheChange(combatId, currentManche, expectedStatut = 'en_cours') {
    const pollMs = (window.SABRE_CONFIG && window.SABRE_CONFIG.pollMs) || 4000;
    let consecutiveErrors = 0;
    let noticeShown = false;

    // Si l'arbitre est en train de saisir ses points, on ne recharge pas sous
    // ses doigts : on affiche un bandeau avec un bouton pour recharger.
    const reloadOrNotify = (message) => {
        const dirty = document.querySelector('form[data-dirty="1"]');
        if (!dirty) { window.location.reload(); return; }
        if (noticeShown) return;
        noticeShown = true;
        const box = document.createElement('div');
        box.className = 'flash flash-warning';
        box.setAttribute('role', 'alert');
        box.innerHTML = '<span class="flash-body"></span><button type="button" class="btn btn-sm btn-warning flash-action">Recharger</button>';
        box.firstChild.textContent = message;
        box.querySelector('button').addEventListener('click', () => window.location.reload());
        const main = document.getElementById('main-content');
        main.insertBefore(box, main.firstChild.nextSibling);
        box.scrollIntoView({ block: 'center', behavior: 'smooth' });
    };

    const poll = async () => {
        try {
            const res = await fetch(`${LIVE_BASE}api/get_combat_status.php?id=${encodeURIComponent(combatId)}`, {
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (!data.error) {
                if (data.statut !== expectedStatut) {
                    reloadOrNotify(data.statut === 'termine' ? 'Le combat est terminé.' : 'Le combat a changé d\'état.');
                } else if (expectedStatut === 'en_cours' && data.manche_actuelle !== currentManche) {
                    reloadOrNotify('La manche ' + currentManche + ' est close : les autres arbitres ont voté. Rechargez pour noter la manche ' + data.manche_actuelle + '.');
                }
            }
            consecutiveErrors = 0;
        } catch (err) {
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
    const cards = Array.from(document.querySelectorAll('[data-combat-id]'))
        .filter(c => c.dataset.statut !== 'termine' && c.dataset.live !== 'off');
    if (cards.length) {
        const tracker = new LiveCombatTracker(cards);
        tracker.start();
    }
});

// Exposé pour les pages qui en ont besoin (arbitrage/saisie.php)
window.watchMancheChange = watchMancheChange;


/* ------------------------------------------------------------------
   Comptes à rebours vers une heure du serveur :
   <span data-countdown-to="1790000000000">…</span>
   Affiche « dans 4 min », « dans 35 s », puis « maintenant ».
   ------------------------------------------------------------------ */
function formatEta(ms) {
    const s = Math.round(ms / 1000);
    if (s <= 0) return 'maintenant';
    if (s < 60) return `dans ${s} s`;
    const m = Math.round(s / 60);
    if (m < 60) return `dans ${m} min`;
    const h = Math.floor(m / 60), r = m % 60;
    return r ? `dans ${h} h ${String(r).padStart(2, '0')}` : `dans ${h} h`;
}
window.formatEta = formatEta;

(() => {
    const now = () => (window.serverNow ? window.serverNow() : Date.now());
    const tick = () => {
        document.querySelectorAll('[data-countdown-to]').forEach(el => {
            const target = parseInt(el.dataset.countdownTo, 10);
            if (!target) return;
            const diff = target - now();
            el.textContent = diff <= 0 ? (el.dataset.lateText || 'maintenant') : formatEta(diff);
            el.classList.toggle('soon', diff > 0 && diff <= 5 * 60000);
            el.classList.toggle('late', diff <= 0);
        });
    };
    tick();
    setInterval(tick, 1000);
})();
