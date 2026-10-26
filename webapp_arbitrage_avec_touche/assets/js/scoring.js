/**
 * scoring.js : pupitre de l'arbitre (boutons +1 / +2 / +3 pour Aka et Ao).
 *
 * - Chaque appui est envoyé tout de suite (file d'attente : l'ordre est garanti,
 *   aucun appui n'est perdu même si le réseau est lent).
 * - Mes totaux (le grand chiffre) changent immédiatement à chaque appui, avec
 *   un « +2 » qui s'envole du bouton : l'arbitre voit que son geste a compté.
 *   Le score officiel (le plus bas des 3 arbitres) et la barre « qui gagne »
 *   se mettent à jour à chaque réponse et toutes les secondes.
 * - Clavier : 1 2 3 = Aka, 7 8 9 = Ao, Retour arrière = annuler mon dernier point.
 */
(function () {
    'use strict';

    const pad = document.getElementById('pad');
    if (!pad) return;

    const BASE = (window.SABRE_CONFIG && window.SABRE_CONFIG.baseUrl) || '/';
    const combatId = pad.dataset.combatId;
    const csrf = pad.dataset.csrf;
    const actif = pad.dataset.actif === '1';
    const stateEl = document.getElementById('pad-state');
    const logEl = document.getElementById('my-log');
    const fmt = v => { const n = Number(v); return Number.isInteger(n) ? String(n) : String(Math.round(n * 100) / 100); };
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // « +2 » qui s'envole du bouton pressé (confirmation visuelle du geste)
    function envol(btn, valeur) {
        if (!btn || reduceMotion) return;
        const f = document.createElement('span');
        f.className = 'pad-float';
        f.setAttribute('aria-hidden', 'true');
        f.textContent = '+' + valeur;
        btn.appendChild(f);
        f.addEventListener('animationend', () => f.remove(), { once: true });
        setTimeout(() => f.isConnected && f.remove(), 900);
    }

    let file = Promise.resolve();
    let enAttente = 0;
    let mine = {
        1: parseFloat(document.querySelector('.js-mine1')?.textContent || '0') || 0,
        2: parseFloat(document.querySelector('.js-mine2')?.textContent || '0') || 0,
    };

    // Retour d'état du pupitre : « Envoi… » puis « ✓ Enregistré » (s'efface),
    // ou une erreur qui reste affichée jusqu'au prochain appui réussi.
    let stateTimer = null;
    function setState(txt, kind) {
        if (!stateEl) return;
        clearTimeout(stateTimer);
        const icon = kind === 'ok' ? 'check' : kind === 'err' ? 'alert-triangle' : '';
        stateEl.innerHTML = icon ? `<svg class="icon" aria-hidden="true"><use href="#icon-${icon}"></use></svg>` : '';
        stateEl.append(document.createTextNode(txt));
        stateEl.classList.toggle('err', kind === 'err');
        stateEl.classList.toggle('ok', kind === 'ok');
        if (kind === 'ok') stateTimer = setTimeout(() => { stateEl.textContent = ''; stateEl.classList.remove('ok'); }, 1600);
    }
    const setTexte = (el, v) => {
        if (!el) return;
        const t = fmt(v);
        if (el.textContent.trim() !== t) { el.textContent = t; window.bumpScore && window.bumpScore(el); }
    };

    let rechargement = false;
    function appliquer(d) {
        // Un autre arbitre (ou l'organisation) a mis en pause / repris : on recharge le pupitre
        if (d.statut === 'en_cours' && 'en_pause' in d && (d.en_pause ? '1' : '0') !== pad.dataset.pause && !rechargement) {
            rechargement = true;
            setState(d.en_pause ? 'Combat mis en pause' : 'Reprise du combat', 'busy');
            setTimeout(() => location.reload(), 400);
        }
        const s1 = document.querySelector('#pad .js-score1'), s2 = document.querySelector('#pad .js-score2');
        setTexte(s1, d.j1);
        setTexte(s2, d.j2);
        if (d.mes && enAttente === 0) {
            mine = { 1: d.mes.j1, 2: d.mes.j2 };
            const m1 = document.querySelector('.js-mine1'), m2 = document.querySelector('.js-mine2');
            if (m1) m1.textContent = fmt(d.mes.j1);
            if (m2) m2.textContent = fmt(d.mes.j2);
        }
        window.majBarreAvantage && window.majBarreAvantage(d);
        if (d.arbitres) majTableArbitres(d);
        if (d.historique && logEl) {
            logEl.innerHTML = d.historique.map(p =>
                `<li class="s${p.joueur}${p.annule ? ' x' : ''}" title="${p.heure}">${p.joueur === 1 ? 'Aka' : 'Ao'} +${p.valeur}</li>`
            ).join('');
        }
    }

    function majTableArbitres(d) {
        const tbody = document.querySelector('#ref-table tbody');
        if (!tbody || !d.arbitres.length) return;
        const roles = { central: 'Central', coin1: 'Coin 1', coin2: 'Coin 2' };
        const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
        tbody.innerHTML = d.arbitres.map(a => `
            <tr>
                <td><strong>${esc(a.nom)}</strong> <span class="hint">${roles[a.role] || esc(a.role)}</span></td>
                <td class="num ${a.j1 == d.j1 ? 'min side-1' : ''}">${fmt(a.j1)}</td>
                <td class="num ${a.j2 == d.j2 ? 'min side-2' : ''}">${fmt(a.j2)}</td>
            </tr>`).join('');
    }

    function envoyer(params) {
        enAttente++;
        setState('Envoi…', 'busy');
        file = file.then(async () => {
            try {
                const body = new URLSearchParams({ combat_id: combatId, csrf, ...params });
                const r = await fetch(`${BASE}api/points.php`, { method: 'POST', body, cache: 'no-store' });
                const d = await r.json();
                enAttente--;
                if (!d.ok) {
                    setState(d.message || 'Point refusé', 'err');
                    if (d.statut && d.statut !== 'en_cours') setTimeout(() => location.reload(), 1200);
                } else {
                    if (enAttente === 0) setState(d.message || 'Enregistré', 'ok');
                }
                appliquer(d);
            } catch (e) {
                enAttente--;
                setState('Pas de réseau : point non enregistré, appuyez à nouveau.', 'err');
                rafraichir();
            }
        });
    }

    function ajouter(joueur, valeur, btn) {
        if (!actif) return;
        mine[joueur] += valeur;
        const m = document.querySelector('.js-mine' + joueur);
        if (m) {
            m.textContent = fmt(mine[joueur]);
            window.bumpScore && window.bumpScore(m);
        }
        if (btn) { btn.classList.add('pressed'); setTimeout(() => btn.classList.remove('pressed'), 120); }
        envol(btn, valeur);
        if (navigator.vibrate) navigator.vibrate(12);
        envoyer({ action: 'ajouter', joueur, valeur });
    }

    pad.addEventListener('click', e => {
        const b = e.target.closest('.pad-btn');
        if (b) ajouter(parseInt(b.dataset.joueur, 10), parseInt(b.dataset.valeur, 10), b);
    });
    document.getElementById('pad-undo')?.addEventListener('click', () => envoyer({ action: 'annuler' }));

    document.addEventListener('keydown', e => {
        if (!actif || e.ctrlKey || e.metaKey || e.altKey) return;
        if (e.target.closest('input, textarea, select')) return;
        const map = { '1': [1, 1], '2': [1, 2], '3': [1, 3], '7': [2, 1], '8': [2, 2], '9': [2, 3] };
        if (map[e.key]) {
            e.preventDefault();
            const [j, v] = map[e.key];
            ajouter(j, v, pad.querySelector(`.pad-btn[data-joueur="${j}"][data-valeur="${v}"]`));
        } else if (e.key === 'Backspace') {
            e.preventDefault();
            envoyer({ action: 'annuler' });
        }
    });

    async function rafraichir() {
        if (document.hidden || enAttente > 0) return;
        try {
            const r = await fetch(`${BASE}api/points.php?combat_id=${encodeURIComponent(combatId)}`, { cache: 'no-store' });
            const d = await r.json();
            if (enAttente === 0) appliquer(d);
        } catch (_) { /* nouvelle tentative */ }
    }
    rafraichir();
    setInterval(rafraichir, 1000);
})();
