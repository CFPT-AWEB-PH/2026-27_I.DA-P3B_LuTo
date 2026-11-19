/**
 * champs.js : listes déroulantes et calendriers dans le style de l'application.
 *
 * Les fenêtres natives (liste d'un <select>, calendrier d'un champ date) sont
 * dessinées par le système : aucun CSS ne les atteint. On les remplace, à la
 * souris uniquement, par un panneau en verre :
 *  - <select> : liste avec coche, recherche dès 10 choix, clavier complet
 *    (↑ ↓ Début Fin Entrée Échap, saisie des premières lettres) ;
 *  - <input type="date"> et "datetime-local" : calendrier du mois, heures et
 *    minutes, « Aujourd'hui » et « Effacer », bornes min / max respectées ;
 *  - <input type="color"> : carré saturation / luminosité, barre des teintes,
 *    code hex et R V B, couleurs du thème, pipette (si le navigateur l'a).
 * Le vrai champ reste en place et garde la valeur : formulaires, validations
 * et scripts des pages fonctionnent comme avant (événements input + change).
 * Téléphones et tablettes (pointeur tactile) gardent les sélecteurs natifs.
 * Un champ avec data-natif garde aussi le sélecteur du navigateur.
 */
(() => {
    'use strict';
    if (!window.matchMedia('(pointer: fine)').matches) return;

    const POPOVER = typeof HTMLElement !== 'undefined' && Object.prototype.hasOwnProperty.call(HTMLElement.prototype, 'popover');
    const ICONE_COCHE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const ICONE_PREC = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 6l-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    const ICONE_SUIV = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    let compteur = 0;
    let ouvert = null;   // { panneau, ancre, fermer }

    /* ───────────── Panneau commun : top layer, position, fermeture ───────────── */

    // Dans une fenêtre modale, tout ce qui est hors de la fenêtre est inerte (non
    // cliquable) : le panneau est donc placé DANS la modale. Le « top layer » du
    // popover le garde au-dessus d'elle.
    function creerPanneau(classe, ancre) {
        const p = document.createElement('div');
        p.className = 'cp-panel ' + classe;
        p.id = 'cp-' + (++compteur);
        if (POPOVER) p.popover = 'manual';
        (ancre.closest('dialog[open]') || document.body).appendChild(p);
        return p;
    }

    function placer(p, ancre) {
        const r = ancre.getBoundingClientRect();
        const marge = 8;
        p.style.maxHeight = '';
        // Seule la liste prend la largeur du champ ; calendrier et couleur gardent la leur
        p.style.minWidth = p.classList.contains('cp-liste') ? Math.max(r.width, 180) + 'px' : '';
        const vw = document.documentElement.clientWidth;   // sans la barre de défilement
        const dessous = window.innerHeight - r.bottom - marge - 6;
        const dessus = r.top - marge - 6;
        const enHaut = p.offsetHeight > dessous && dessus > dessous;
        const dispo = Math.max(160, enHaut ? dessus : dessous);
        if (p.offsetHeight > dispo) p.style.maxHeight = dispo + 'px';
        const h = p.offsetHeight, w = p.offsetWidth;
        p.style.top = (enHaut ? r.top - 6 - h : r.bottom + 6) + 'px';
        // Aligné sur le bord gauche du champ, ou sur son bord droit s'il ne tient pas
        const gauche = r.left + w <= vw - marge ? r.left : r.right - w;
        p.style.left = Math.min(Math.max(marge, gauche), vw - w - marge) + 'px';
        p.dataset.cote = enHaut ? 'haut' : 'bas';
    }

    function montrer(p, ancre) {
        if (POPOVER) p.showPopover(); else p.classList.add('cp-sans-popover');
        placer(p, ancre);
        ancre.setAttribute('aria-expanded', 'true');
        ancre.classList.add('cp-actif');
    }

    function fermer(rendreFocus) {
        if (!ouvert) return;
        const { panneau, ancre, aLaFermeture } = ouvert;
        ouvert = null;
        if (aLaFermeture) aLaFermeture();
        ancre.removeAttribute('aria-expanded');
        ancre.classList.remove('cp-actif');
        try { if (POPOVER && panneau.matches(':popover-open')) panneau.hidePopover(); } catch (_) { /* déjà fermé */ }
        panneau.remove();
        if (rendreFocus) ancre.focus({ preventScroll: true });
    }

    document.addEventListener('pointerdown', e => {
        if (ouvert && !ouvert.panneau.contains(e.target) && e.target !== ouvert.ancre) fermer(false);
    }, true);
    window.addEventListener('resize', () => fermer(false));
    window.addEventListener('blur', () => fermer(false));
    document.addEventListener('scroll', e => {
        if (!ouvert || ouvert.panneau.contains(e.target)) return;
        const r = ouvert.ancre.getBoundingClientRect();
        if (r.bottom < 0 || r.top > window.innerHeight) fermer(false); else placer(ouvert.panneau, ouvert.ancre);
    }, true);

    function emettre(champ) {
        champ.dispatchEvent(new Event('input', { bubbles: true }));
        champ.dispatchEvent(new Event('change', { bubbles: true }));
    }

    /* ───────────────────────────── Listes déroulantes ───────────────────────────── */

    const estListe = el => el instanceof HTMLSelectElement && !el.multiple && el.size <= 1 && !el.disabled && !el.hasAttribute('data-natif');

    function ouvrirListe(sel) {
        fermer(false);
        const p = creerPanneau('cp-liste', sel);
        const options = [];
        let html = '';
        const avecRecherche = Array.from(sel.options).filter(o => !o.hidden).length > 10;
        if (avecRecherche) html += '<input type="search" class="cp-recherche" placeholder="Rechercher…" aria-label="Rechercher dans la liste" autocomplete="off">';
        html += '<div class="cp-options" role="listbox" tabindex="-1">';
        Array.from(sel.children).forEach(enfant => {
            const ajouter = (o, groupe) => {
                if (o.hidden) return;
                const i = options.length;
                options.push({ o, groupe, texte: o.textContent.trim() });
                html += `<div class="cp-opt${o.selected ? ' is-sel' : ''}${o.disabled ? ' is-off' : ''}" role="option" id="${p.id}-o${i}" data-i="${i}" aria-selected="${o.selected}"${o.disabled ? ' aria-disabled="true"' : ''}><span class="cp-opt-t"></span><span class="cp-coche">${ICONE_COCHE}</span></div>`;
            };
            if (enfant.tagName === 'OPTGROUP') {
                html += '<div class="cp-groupe" role="presentation"></div>';
                Array.from(enfant.children).forEach(o => ajouter(o, enfant.label));
            } else ajouter(enfant, null);
        });
        html += '</div>';
        p.innerHTML = html;
        // Textes posés en textContent : jamais de HTML injecté depuis une option
        p.querySelectorAll('.cp-opt').forEach(el => { el.querySelector('.cp-opt-t').textContent = options[el.dataset.i].texte; });
        let g = 0;
        Array.from(sel.children).filter(c => c.tagName === 'OPTGROUP').forEach(gr => { const el = p.querySelectorAll('.cp-groupe')[g++]; if (el) el.textContent = gr.label; });

        const liste = p.querySelector('.cp-options');
        const recherche = p.querySelector('.cp-recherche');
        const items = Array.from(p.querySelectorAll('.cp-opt'));
        let actif = Math.max(0, options.findIndex(x => x.o.selected));

        const visibles = () => items.filter(el => !el.hidden && !el.classList.contains('is-off'));
        function activer(i, defiler = true) {
            items.forEach(el => el.classList.remove('is-act'));
            const el = items[i];
            if (!el) return;
            actif = i;
            el.classList.add('is-act');
            liste.setAttribute('aria-activedescendant', el.id);
            if (defiler) el.scrollIntoView({ block: 'nearest' });
        }
        function deplacer(sens) {
            const v = visibles();
            if (!v.length) return;
            const pos = v.indexOf(items[actif]);
            const suivant = pos === -1 ? v[sens > 0 ? 0 : v.length - 1] : v[Math.min(v.length - 1, Math.max(0, pos + sens))];
            activer(+suivant.dataset.i);
        }
        function choisir(i) {
            const x = options[i];
            if (!x || x.o.disabled) return;
            const change = sel.value !== x.o.value || sel.selectedIndex !== x.o.index;
            sel.selectedIndex = x.o.index;
            fermer(true);
            if (change) emettre(sel);
        }

        liste.addEventListener('pointermove', e => { const el = e.target.closest('.cp-opt'); if (el && !el.classList.contains('is-off')) activer(+el.dataset.i, false); });
        liste.addEventListener('click', e => { const el = e.target.closest('.cp-opt'); if (el) choisir(+el.dataset.i); });

        let saisie = '', saisieTimer = null;
        p.addEventListener('keydown', e => {
            if (e.key === 'ArrowDown') { e.preventDefault(); deplacer(1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); deplacer(-1); }
            else if (e.key === 'Home' && e.target !== recherche) { e.preventDefault(); activer(+visibles()[0]?.dataset.i); }
            else if (e.key === 'End' && e.target !== recherche) { e.preventDefault(); const v = visibles(); activer(+v[v.length - 1]?.dataset.i); }
            else if (e.key === 'PageDown') { e.preventDefault(); deplacer(8); }
            else if (e.key === 'PageUp') { e.preventDefault(); deplacer(-8); }
            else if (e.key === 'Enter' || (e.key === ' ' && e.target !== recherche)) { e.preventDefault(); choisir(actif); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fermer(true); }
            else if (e.key === 'Tab') { fermer(false); sel.focus(); }
            else if (!recherche && e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
                // Saisie des premières lettres : va au choix qui commence ainsi
                saisie += e.key.toLowerCase();
                clearTimeout(saisieTimer);
                saisieTimer = setTimeout(() => { saisie = ''; }, 700);
                const trouve = visibles().find(el => options[el.dataset.i].texte.toLowerCase().startsWith(saisie));
                if (trouve) activer(+trouve.dataset.i);
            }
        });
        if (recherche) {
            recherche.addEventListener('input', () => {
                const q = recherche.value.trim().toLowerCase();
                items.forEach(el => { el.hidden = q !== '' && !options[el.dataset.i].texte.toLowerCase().includes(q); });
                p.querySelectorAll('.cp-groupe').forEach(gr => {
                    let n = gr.nextElementSibling, vu = false;
                    while (n && n.classList.contains('cp-opt')) { if (!n.hidden) vu = true; n = n.nextElementSibling; }
                    gr.hidden = !vu;
                });
                const v = visibles();
                if (v.length && (items[actif].hidden || items[actif].classList.contains('is-off'))) activer(+v[0].dataset.i);
                p.classList.toggle('cp-vide', !v.length);
            });
        }

        ouvert = { panneau: p, ancre: sel };
        sel.setAttribute('aria-controls', p.id);
        montrer(p, sel);
        activer(actif);
        (recherche || liste).focus({ preventScroll: true });
    }

    document.addEventListener('mousedown', e => {
        const sel = e.target.closest('select');
        if (!sel || e.button !== 0 || !estListe(sel)) return;
        e.preventDefault();
        if (ouvert && ouvert.ancre === sel) { fermer(true); return; }
        sel.focus({ preventScroll: true });
        ouvrirListe(sel);
    });
    document.addEventListener('keydown', e => {
        const sel = e.target;
        if (!estListe(sel)) return;
        if (e.key === ' ' || e.key === 'Enter' || e.key === 'F4' || (e.altKey && (e.key === 'ArrowDown' || e.key === 'ArrowUp'))) {
            e.preventDefault();
            ouvrirListe(sel);
        }
    });

    /* ───────────────────────────── Calendrier ───────────────────────────── */

    const estDate = el => el instanceof HTMLInputElement && (el.type === 'date' || el.type === 'datetime-local') && !el.disabled && !el.readOnly && !el.hasAttribute('data-natif');
    const deux = n => String(n).padStart(2, '0');
    const isoJour = d => `${d.getFullYear()}-${deux(d.getMonth() + 1)}-${deux(d.getDate())}`;
    const jourDe = s => { const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null; };
    const heureDe = s => { const m = /T(\d{2}):(\d{2})/.exec(s || ''); return m ? [+m[1], +m[2]] : null; };
    const fmtMois = new Intl.DateTimeFormat('fr-FR', { month: 'long', year: 'numeric' });
    const fmtJour = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    const JOURS = ['lu', 'ma', 'me', 'je', 've', 'sa', 'di'];
    // « Aujourd'hui » selon l'heure du serveur quand la page la connaît
    const maintenant = () => new Date(window.serverNow ? window.serverNow() : Date.now());

    function ouvrirCalendrier(champ) {
        fermer(false);
        const avecHeure = champ.type === 'datetime-local';
        const p = creerPanneau('cp-cal' + (avecHeure ? ' cp-cal-heure' : ''), champ);
        const min = jourDe(champ.min), max = jourDe(champ.max);
        let choisi = jourDe(champ.value);
        let heure = heureDe(champ.value);
        let vue = new Date((choisi || maintenant()).getFullYear(), (choisi || maintenant()).getMonth(), 1);
        let focusJour = choisi || maintenant();

        p.innerHTML = `
            <div class="cp-cal-corps">
                <div class="cp-cal-tete">
                    <button type="button" class="cp-b cp-nav" data-nav="-1" aria-label="Mois précédent">${ICONE_PREC}</button>
                    <strong class="cp-mois" aria-live="polite"></strong>
                    <button type="button" class="cp-b cp-nav" data-nav="1" aria-label="Mois suivant">${ICONE_SUIV}</button>
                </div>
                <div class="cp-semaine" aria-hidden="true">${JOURS.map(j => `<span>${j}</span>`).join('')}</div>
                <div class="cp-jours" role="grid"></div>
            </div>
            ${avecHeure ? `<div class="cp-heures">
                <div class="cp-col" data-col="h" aria-label="Heures" role="listbox"></div>
                <div class="cp-col" data-col="m" aria-label="Minutes" role="listbox"></div>
            </div>` : ''}
            <div class="cp-pied">
                <button type="button" class="cp-b cp-lien" data-act="effacer">Effacer</button>
                <button type="button" class="cp-b cp-lien" data-act="aujourdhui">Aujourd'hui</button>
                ${avecHeure ? '<button type="button" class="cp-b cp-ok" data-act="ok">OK</button>' : ''}
            </div>`;
        const grille = p.querySelector('.cp-jours');

        const horsBornes = d => (min && d < min) || (max && d > max);
        function heureParDefaut() {
            if (heure) return heure;
            const n = maintenant();
            const m = Math.ceil(n.getMinutes() / 5) * 5;
            return m === 60 ? [(n.getHours() + 1) % 24, 0] : [n.getHours(), m];
        }
        function ecrire() {
            if (!choisi) { champ.value = ''; emettre(champ); return; }
            const h = heureParDefaut();
            heure = h;
            champ.value = avecHeure ? `${isoJour(choisi)}T${deux(h[0])}:${deux(h[1])}` : isoJour(choisi);
            emettre(champ);
        }

        function dessiner() {
            p.querySelector('.cp-mois').textContent = fmtMois.format(vue);
            const debut = new Date(vue.getFullYear(), vue.getMonth(), 1 - ((vue.getDay() + 6) % 7));
            const auj = isoJour(maintenant());
            let html = '';
            for (let s = 0; s < 6; s++) {
                html += '<div class="cp-ligne" role="row">';
                for (let j = 0; j < 7; j++) {
                    const d = new Date(debut.getFullYear(), debut.getMonth(), debut.getDate() + s * 7 + j);
                    const iso = isoJour(d);
                    const cls = ['cp-b', 'cp-jour'];
                    if (d.getMonth() !== vue.getMonth()) cls.push('is-autre');
                    if (iso === auj) cls.push('is-auj');
                    if (choisi && iso === isoJour(choisi)) cls.push('is-sel');
                    const off = horsBornes(d);
                    html += `<button type="button" role="gridcell" class="${cls.join(' ')}" data-jour="${iso}" tabindex="${iso === isoJour(focusJour) ? 0 : -1}"${off ? ' disabled' : ''} aria-label="${fmtJour.format(d)}"${choisi && iso === isoJour(choisi) ? ' aria-selected="true"' : ''}>${d.getDate()}</button>`;
                }
                html += '</div>';
            }
            grille.innerHTML = html;
            if (avecHeure) dessinerHeures();
        }
        function dessinerHeures() {
            const [h, m] = heure || [-1, -1];
            const colH = p.querySelector('[data-col="h"]'), colM = p.querySelector('[data-col="m"]');
            let minutes = Array.from({ length: 12 }, (_, i) => i * 5);
            if (m >= 0 && !minutes.includes(m)) minutes = [...minutes, m].sort((a, b) => a - b);
            colH.innerHTML = Array.from({ length: 24 }, (_, i) => `<button type="button" class="cp-b cp-t${i === h ? ' is-sel' : ''}" role="option" aria-selected="${i === h}" data-h="${i}">${deux(i)}</button>`).join('');
            colM.innerHTML = minutes.map(i => `<button type="button" class="cp-b cp-t${i === m ? ' is-sel' : ''}" role="option" aria-selected="${i === m}" data-m="${i}">${deux(i)}</button>`).join('');
            centrerHeures();
        }
        // Heure et minute choisies au milieu de leur colonne (une fois le panneau affiché)
        function centrerHeures() {
            requestAnimationFrame(() => p.querySelectorAll('.cp-col').forEach(c => {
                const s = c.querySelector('.is-sel');
                if (s) { c.style.scrollBehavior = 'auto'; c.scrollTop = s.offsetTop - c.offsetTop - c.clientHeight / 2 + s.offsetHeight / 2; c.style.scrollBehavior = ''; }
            }));
        }
        function allerA(d, focus = true) {
            focusJour = d;
            if (d.getMonth() !== vue.getMonth() || d.getFullYear() !== vue.getFullYear()) vue = new Date(d.getFullYear(), d.getMonth(), 1);
            dessiner();
            if (focus) grille.querySelector(`[data-jour="${isoJour(d)}"]`)?.focus({ preventScroll: true });
        }

        p.addEventListener('click', e => {
            const b = e.target.closest('.cp-b');
            if (!b || b.disabled) return;
            if (b.dataset.nav) { vue = new Date(vue.getFullYear(), vue.getMonth() + +b.dataset.nav, 1); dessiner(); return; }
            if (b.dataset.jour) {
                choisi = jourDe(b.dataset.jour); focusJour = choisi; ecrire();
                if (avecHeure) dessiner(); else fermer(true);
                return;
            }
            if (b.dataset.h !== undefined || b.dataset.m !== undefined) {
                const h = heureParDefaut();
                heure = b.dataset.h !== undefined ? [+b.dataset.h, h[1]] : [h[0], +b.dataset.m];
                if (!choisi) choisi = maintenant();
                ecrire(); dessiner();
                return;
            }
            if (b.dataset.act === 'effacer') { choisi = null; heure = null; ecrire(); fermer(true); }
            else if (b.dataset.act === 'aujourdhui') {
                const a = maintenant();
                if (horsBornes(new Date(a.getFullYear(), a.getMonth(), a.getDate()))) { allerA(a); return; }
                choisi = new Date(a.getFullYear(), a.getMonth(), a.getDate());
                ecrire();
                if (avecHeure) allerA(choisi, false); else fermer(true);
            }
            else if (b.dataset.act === 'ok') fermer(true);
        });
        p.addEventListener('keydown', e => {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fermer(true); return; }
            if (e.key === 'Tab') { e.preventDefault(); fermer(true); return; }
            const b = e.target.closest('.cp-jour');
            if (!b) return;
            const d = jourDe(b.dataset.jour);
            const pas = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7 }[e.key];
            if (pas) { e.preventDefault(); allerA(new Date(d.getFullYear(), d.getMonth(), d.getDate() + pas)); }
            else if (e.key === 'PageUp' || e.key === 'PageDown') { e.preventDefault(); allerA(new Date(d.getFullYear(), d.getMonth() + (e.key === 'PageUp' ? -1 : 1), Math.min(d.getDate(), 28))); }
            else if (e.key === 'Home' || e.key === 'End') { e.preventDefault(); const dec = (d.getDay() + 6) % 7; allerA(new Date(d.getFullYear(), d.getMonth(), d.getDate() + (e.key === 'Home' ? -dec : 6 - dec))); }
        });

        ouvert = { panneau: p, ancre: champ };
        dessiner();
        montrer(p, champ);
        (grille.querySelector('[tabindex="0"]') || grille.querySelector('.cp-jour'))?.focus({ preventScroll: true });
    }

    // Clic sur l'icône du calendrier (les 40 px de droite du champ) : notre calendrier.
    // Un clic ailleurs dans le champ garde la saisie au clavier jour / mois / année.
    const surIcone = (champ, e) => e.clientX >= champ.getBoundingClientRect().right - 40;
    ['mousedown', 'click'].forEach(type => document.addEventListener(type, e => {
        const champ = e.target;
        if (!estDate(champ) || e.button !== 0 || !surIcone(champ, e)) return;
        e.preventDefault();
        if (type === 'mousedown') {
            if (ouvert && ouvert.ancre === champ) { fermer(true); return; }
            champ.focus({ preventScroll: true });
            ouvrirCalendrier(champ);
        }
    }, true));
    document.addEventListener('keydown', e => {
        const champ = e.target;
        if (!estDate(champ)) return;
        if (e.key === 'F4' || (e.altKey && (e.key === 'ArrowDown' || e.key === 'ArrowUp'))) {
            e.preventDefault();
            ouvrirCalendrier(champ);
        }
    });

    /* ───────────────────────────── Couleur ───────────────────────────── */

    const estCouleur = el => el instanceof HTMLInputElement && el.type === 'color' && !el.disabled && !el.hasAttribute('data-natif');
    const borne = (v, a, b) => Math.min(b, Math.max(a, v));
    const hexVersRgb = h => { const m = /^#?([0-9a-f]{6})$/i.exec(String(h).trim()); if (!m) return null; const n = parseInt(m[1], 16); return [n >> 16, (n >> 8) & 255, n & 255]; };
    const rgbVersHex = ([r, g, b]) => '#' + [r, g, b].map(x => borne(Math.round(x), 0, 255).toString(16).padStart(2, '0')).join('');
    function rgbVersHsv([r, g, b]) {
        r /= 255; g /= 255; b /= 255;
        const max = Math.max(r, g, b), min = Math.min(r, g, b), d = max - min;
        let h = 0;
        if (d) h = max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
        return [(h * 60 + 360) % 360, max ? d / max : 0, max];
    }
    function hsvVersRgb([h, s, v]) {
        const f = n => { const k = (n + h / 60) % 6; return v - v * s * Math.max(0, Math.min(k, 4 - k, 1)); };
        return [f(5) * 255, f(3) * 255, f(1) * 255];
    }
    // Couleurs proposées : celles du thème actif, puis une palette courante
    function couleursProposees() {
        const cs = getComputedStyle(document.documentElement);
        const theme = ['--p1', '--p2', '--primary', '--success', '--warning', '--danger', '--gold']
            .map(v => cs.getPropertyValue(v).trim()).filter(c => /^#[0-9a-f]{6}$/i.test(c));
        const fixes = ['#ff5a5f', '#4c9dff', '#8095ff', '#3ee0b0', '#f5b83d', '#e8650a', '#b78cff', '#14a3b8', '#ffffff', '#9aa1ad', '#2b2f38', '#000000'];
        return [...new Set([...theme, ...fixes].map(c => c.toLowerCase()))].slice(0, 16);
    }
    const ICONE_PIPETTE = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 5.5l4 4M17 3l4 4-2.5 2.5-4-4L17 3zM14 8l-8.5 8.5L5 20l3.5-.5L17 11" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';

    function ouvrirCouleur(champ) {
        fermer(false);
        const p = creerPanneau('cp-couleur', champ);
        const depart = champ.value;
        let hsv = rgbVersHsv(hexVersRgb(champ.value) || [128, 149, 255]);
        const pipette = 'EyeDropper' in window
            ? `<button type="button" class="cp-b cp-pipette" aria-label="Prendre une couleur à l’écran" title="Pipette">${ICONE_PIPETTE}</button>` : '';
        p.innerHTML = `
            <div class="cp-sv" tabindex="0" role="slider" aria-label="Saturation et luminosité"><span class="cp-sv-pt"></span></div>
            <div class="cp-ligne-teinte">
                ${pipette}
                <span class="cp-apercu" aria-hidden="true"></span>
                <div class="cp-teinte" tabindex="0" role="slider" aria-label="Teinte" aria-valuemin="0" aria-valuemax="360"><span class="cp-teinte-pt"></span></div>
            </div>
            <div class="cp-codes">
                <label class="cp-code cp-code-hex"><input type="text" class="cp-hex" maxlength="7" spellcheck="false" autocomplete="off" aria-label="Code hexadécimal"><span>Hex</span></label>
                <label class="cp-code"><input type="number" class="cp-rgb" data-c="0" min="0" max="255" aria-label="Rouge"><span>R</span></label>
                <label class="cp-code"><input type="number" class="cp-rgb" data-c="1" min="0" max="255" aria-label="Vert"><span>V</span></label>
                <label class="cp-code"><input type="number" class="cp-rgb" data-c="2" min="0" max="255" aria-label="Bleu"><span>B</span></label>
            </div>
            <div class="cp-nuancier" role="listbox" aria-label="Couleurs proposées">
                ${couleursProposees().map(c => `<button type="button" class="cp-b cp-pastille" role="option" data-c="${c}" aria-label="${c}" title="${c}"><i></i></button>`).join('')}
            </div>`;
        p.querySelectorAll('.cp-pastille').forEach(b => { b.querySelector('i').style.background = b.dataset.c; });
        const sv = p.querySelector('.cp-sv'), svPt = p.querySelector('.cp-sv-pt');
        const teinte = p.querySelector('.cp-teinte'), teintePt = p.querySelector('.cp-teinte-pt');
        const apercu = p.querySelector('.cp-apercu'), hex = p.querySelector('.cp-hex');
        const rgbs = Array.from(p.querySelectorAll('.cp-rgb'));

        function rendu(source) {
            const rgb = hsvVersRgb(hsv), h = rgbVersHex(rgb);
            sv.style.setProperty('--teinte', `hsl(${hsv[0]}, 100%, 50%)`);
            svPt.style.left = (hsv[1] * 100) + '%';
            svPt.style.top = ((1 - hsv[2]) * 100) + '%';
            svPt.style.background = h;
            teintePt.style.left = (hsv[0] / 360 * 100) + '%';
            teintePt.style.background = `hsl(${hsv[0]}, 100%, 50%)`;
            teinte.setAttribute('aria-valuenow', Math.round(hsv[0]));
            apercu.style.background = h;
            if (source !== 'hex') hex.value = h;
            if (source !== 'rgb') rgbs.forEach((i, k) => { i.value = Math.round(rgb[k]); });
            p.querySelectorAll('.cp-pastille').forEach(b => b.classList.toggle('is-sel', b.dataset.c === h));
            if (champ.value !== h) { champ.value = h; champ.dispatchEvent(new Event('input', { bubbles: true })); }
        }
        // Glisser dans le carré ou sur la barre (souris, stylet)
        function glisser(zone, appliquer) {
            zone.addEventListener('pointerdown', e => {
                e.preventDefault();
                zone.focus({ preventScroll: true });
                zone.setPointerCapture(e.pointerId);
                const bouger = ev => {
                    const r = zone.getBoundingClientRect();
                    appliquer(borne((ev.clientX - r.left) / r.width, 0, 1), borne((ev.clientY - r.top) / r.height, 0, 1));
                    rendu();
                };
                bouger(e);
                zone.addEventListener('pointermove', bouger);
                zone.addEventListener('pointerup', () => zone.removeEventListener('pointermove', bouger), { once: true });
            });
        }
        glisser(sv, (x, y) => { hsv = [hsv[0], x, 1 - y]; });
        glisser(teinte, x => { hsv = [Math.min(359.9, x * 360), hsv[1], hsv[2]]; });
        sv.addEventListener('keydown', e => {
            const pas = e.shiftKey ? .1 : .02;
            const d = { ArrowLeft: [-pas, 0], ArrowRight: [pas, 0], ArrowUp: [0, pas], ArrowDown: [0, -pas] }[e.key];
            if (!d) return;
            e.preventDefault();
            hsv = [hsv[0], borne(hsv[1] + d[0], 0, 1), borne(hsv[2] + d[1], 0, 1)];
            rendu();
        });
        teinte.addEventListener('keydown', e => {
            const d = { ArrowLeft: -1, ArrowDown: -1, ArrowRight: 1, ArrowUp: 1 }[e.key];
            if (!d) return;
            e.preventDefault();
            hsv = [(hsv[0] + d * (e.shiftKey ? 15 : 3) + 360) % 360, hsv[1], hsv[2]];
            rendu();
        });
        hex.addEventListener('input', () => {
            const v = hex.value.trim();
            const rgb = hexVersRgb(v.startsWith('#') ? v : '#' + v);
            if (rgb) { hsv = rgbVersHsv(rgb); rendu('hex'); }
        });
        hex.addEventListener('blur', () => { hex.value = champ.value; });
        rgbs.forEach(i => i.addEventListener('input', () => {
            hsv = rgbVersHsv(rgbs.map(x => borne(parseInt(x.value, 10) || 0, 0, 255)));
            rendu('rgb');
        }));
        p.addEventListener('click', async e => {
            const b = e.target.closest('.cp-b');
            if (!b) return;
            if (b.dataset.c) { hsv = rgbVersHsv(hexVersRgb(b.dataset.c)); rendu(); }
            else if (b.classList.contains('cp-pipette')) {
                try {
                    const r = await new window.EyeDropper().open();
                    const rgb = hexVersRgb(r.sRGBHex);
                    if (rgb) { hsv = rgbVersHsv(rgb); rendu(); }
                } catch (_) { /* pipette annulée */ }
            }
        });
        p.addEventListener('keydown', e => {
            if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); fermer(true); }
            else if (e.key === 'Enter' && e.target !== sv && e.target !== teinte) { e.preventDefault(); fermer(true); }
        });

        // « change » à la fermeture si la couleur a changé (comme le sélecteur natif)
        ouvert = { panneau: p, ancre: champ, aLaFermeture: () => { if (champ.value !== depart) champ.dispatchEvent(new Event('change', { bubbles: true })); } };
        montrer(p, champ);
        rendu();
        sv.focus({ preventScroll: true });
    }

    // Le clic (souris, ou Entrée / Espace au clavier) ouvrirait la fenêtre du système
    document.addEventListener('click', e => {
        const champ = e.target;
        if (!estCouleur(champ)) return;
        e.preventDefault();
        if (ouvert && ouvert.ancre === champ) { fermer(true); return; }
        ouvrirCouleur(champ);
    }, true);
})();
