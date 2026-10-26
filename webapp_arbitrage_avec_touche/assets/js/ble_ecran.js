/**
 * ble_ecran.js : annonce plein écran des touches sur l'écran de salle.
 * Attend window.BLE_COMBATS_EN_COURS = [{id, j1, j2}] et window.BASE_URL.
 * Au chargement, les touches déjà enregistrées ne sont pas réannoncées.
 */
(function () {
    'use strict';

    const combats = window.BLE_COMBATS_EN_COURS || [];
    const overlay = document.getElementById('ble-overlay');
    const label = document.getElementById('ble-overlay-label');
    const who = document.getElementById('ble-overlay-who');
    const time = document.getElementById('ble-overlay-time');
    const base = window.BASE_URL || '/';
    const pollMs = Math.min(1500, (window.SABRE_CONFIG && window.SABRE_CONFIG.pollMs) || 1500);
    if (!overlay || !combats.length) return;

    const derniers = {};
    const mmss = s => (s === null || s === undefined) ? '' : String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');

    function annoncer(t, c) {
        const side = t.type === 'double' ? '' : (t.joueur_num === 1 ? 's1' : 's2');
        overlay.className = 'visible type-' + t.type + ' ' + side;
        if (t.type === 'double') {
            label.textContent = 'Double touche';
            who.textContent = c.j1 + ' et ' + c.j2;
        } else {
            label.textContent = 'Touche';
            who.textContent = t.joueur || '';
        }
        time.textContent = t.match_s !== null ? 'à ' + mmss(t.match_s) : '';
        document.dispatchEvent(new CustomEvent('touche-ecran', { detail: t }));
        clearTimeout(overlay._timer);
        overlay._timer = setTimeout(() => { overlay.className = ''; }, 2500);
    }

    async function poll() {
        for (const c of combats) {
            try {
                const r = await fetch(`${base}api/ble_touches.php?combat_id=${c.id}&historique=1`, { cache: 'no-store' });
                const data = await r.json();
                const hits = document.querySelector(`[data-hits="${c.id}"]`);
                if (hits && data.compte) {
                    hits.querySelector('.js-h1').textContent = data.compte.j1;
                    hits.querySelector('.js-h2').textContent = data.compte.j2;
                    hits.querySelector('.js-hd').textContent = data.compte.doubles;
                }
                if (derniers[c.id] === undefined) { derniers[c.id] = data.dernier_id; continue; }
                if (data.dernier_id > derniers[c.id] && data.touches.length) {
                    annoncer(data.touches[data.touches.length - 1], c);
                }
                derniers[c.id] = data.dernier_id;
            } catch (_) { /* nouvelle tentative au prochain tour */ }
        }
        setTimeout(poll, pollMs);
    }
    poll();
})();
