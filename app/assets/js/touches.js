/**
 * touches.js : historique des touches électroniques d'un combat, en direct.
 *
 * Utilisation :
 *   <section class="card touch-panel" data-touches
 *            data-combat-id="12" data-statut="en_cours"
 *            data-j1="Kaelen" data-j2="Mira" data-duree="180">
 *   </section>
 *
 * Affiche : totaux par joueur, bandeau « dernière touche » (quelques
 * secondes), frise sur la durée du combat et liste complète (la plus
 * récente en haut) avec le temps de match et l'heure du serveur.
 */
(function () {
    'use strict';

    const BASE = (window.SABRE_CONFIG && window.SABRE_CONFIG.baseUrl) || '/';
    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const mmss = s => (s === null || s === undefined) ? '--:--'
        : String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0');

    function sideOf(t) { return t.type === 'double' ? 'sd' : (t.joueur_num === 1 ? 's1' : t.joueur_num === 2 ? 's2' : ''); }
    function label(t, j1, j2) {
        if (t.type === 'double') return 'Double touche';
        if (t.type === 'annule') return 'Touche annulée';
        const who = t.joueur_num === 1 ? j1 : t.joueur_num === 2 ? j2 : 'Joueur inconnu';
        return 'Touche ' + who;
    }

    function init(root) {
        const combatId = root.dataset.combatId;
        const j1 = root.dataset.j1 || 'Joueur 1';
        const j2 = root.dataset.j2 || 'Joueur 2';
        const duree = parseInt(root.dataset.duree || '0', 10);
        let statut = root.dataset.statut || 'en_cours';
        const pollMs = Math.min(2000, (window.SABRE_CONFIG && window.SABRE_CONFIG.pollMs) || 2000);

        root.innerHTML = `
            <div class="card-head">
                <h3>Touches</h3>
                <span class="hint js-t-state"></span>
            </div>
            <div class="touch-flash js-t-flash" role="status" aria-live="assertive"></div>
            <div class="touch-tally">
                <div class="t1"><span class="n js-c1">–</span><span class="l">${esc(j1)}</span></div>
                <div class="td"><span class="n js-cd">–</span><span class="l">Doubles</span></div>
                <div class="t2"><span class="n js-c2">–</span><span class="l">${esc(j2)}</span></div>
            </div>
            ${duree > 0 ? '<div class="touch-timeline js-t-line" aria-hidden="true"></div>' : ''}
            <div class="js-t-skel" aria-hidden="true"><span class="skeleton skeleton-line"></span><span class="skeleton skeleton-line" style="width:75%"></span><span class="skeleton skeleton-line" style="width:60%"></span></div>
            <ul class="touch-list js-t-list" aria-label="Historique des touches"></ul>
            <p class="touch-empty js-t-empty" hidden>Aucune touche pour l'instant. Elles s'affichent ici dès qu'un sabre touche.</p>`;

        const $ = sel => root.querySelector(sel);
        let dernierId = -1;
        let premierChargement = true;
        let flashTimer = null;
        let timer = null;

        function render(data) {
            $('.js-t-skel')?.remove();
            $('.js-c1').textContent = data.compte.j1;
            $('.js-c2').textContent = data.compte.j2;
            $('.js-cd').textContent = data.compte.doubles;

            const list = $('.js-t-list');
            const connus = new Set([...list.children].map(li => li.dataset.id));
            const items = data.touches.slice().reverse(); // plus récente en haut
            list.innerHTML = items.map(t => `
                <li class="${sideOf(t)}${!premierChargement && !connus.has(String(t.id)) ? ' is-new' : ''}" data-id="${t.id}">
                    <span class="t-time" title="Temps de match">${mmss(t.match_s)}</span>
                    <span class="t-mark" aria-hidden="true"></span>
                    <span class="t-who">${esc(label(t, j1, j2))}</span>
                    <span class="t-clock" title="Heure du serveur">${esc(t.heure)}</span>
                </li>`).join('');
            $('.js-t-empty').hidden = data.touches.length > 0;

            const line = $('.js-t-line');
            if (line) {
                line.innerHTML = data.touches.filter(t => t.match_s !== null).map(t =>
                    `<span class="tick ${sideOf(t)}" style="left:calc(${Math.min(100, t.match_s / duree * 100)}% - 1px)" title="${mmss(t.match_s)} ${esc(label(t, j1, j2))}"></span>`
                ).join('');
            }

            // Bandeau « dernière touche » : seulement pour une touche arrivée en direct
            if (!premierChargement && data.touches.length) {
                const last = data.touches[data.touches.length - 1];
                if (!connus.has(String(last.id))) {
                    const f = $('.js-t-flash');
                    f.className = 'touch-flash show ' + sideOf(last);
                    f.innerHTML = `<span>${esc(label(last, j1, j2))}</span><span class="t-time">${mmss(last.match_s)}</span>`;
                    clearTimeout(flashTimer);
                    flashTimer = setTimeout(() => { f.className = 'touch-flash js-t-flash'; }, 4000);
                    root.dispatchEvent(new CustomEvent('touche', { detail: last, bubbles: true }));
                }
            }
            premierChargement = false;
        }

        async function poll() {
            try {
                const r = await fetch(`${BASE}api/ble_touches.php?combat_id=${encodeURIComponent(combatId)}&historique=1`, { cache: 'no-store' });
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const data = await r.json();
                statut = data.statut;
                if (data.dernier_id !== dernierId) {
                    dernierId = data.dernier_id;
                    render(data);
                }
                const st = $('.js-t-state');
                st.textContent = statut === 'en_cours' ? 'En direct' : (data.total + ' touche' + (data.total > 1 ? 's' : '') + ' au total');
                st.classList.toggle('is-live', statut === 'en_cours');
                if (window.ConnStatus && window.ConnStatus.isLost) window.ConnStatus.ok();
            } catch (e) {
                const st = $('.js-t-state');
                st.textContent = 'Reconnexion…';
                st.classList.remove('is-live');
            }
            if (statut === 'en_cours' && !document.hidden) timer = setTimeout(poll, pollMs);
        }

        document.addEventListener('visibilitychange', () => {
            clearTimeout(timer);
            if (!document.hidden && statut === 'en_cours') poll();
        });
        poll();
    }

    document.querySelectorAll('[data-touches]').forEach(init);
})();
