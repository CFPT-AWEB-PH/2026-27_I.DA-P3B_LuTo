/**
 * arbre.js : l'arbre du tournoi tient dans l'écran, quelle que soit sa taille.
 *
 * L'arbre est dessiné en « taille réelle » par includes/arbre.php, puis réduit
 * (ou agrandi sur l'écran de salle) avec CSS zoom. Trois modes, mémorisés :
 *   tout    : tout l'arbre visible d'un coup (largeur ET hauteur)
 *   largeur : toute la largeur, on fait défiler vers le bas
 *   reel    : 100 %, défilement dans les deux sens
 * À l'impression, l'arbre est réduit pour tenir sur une page A4 paysage.
 */
(function () {
    'use strict';
    const wraps = Array.from(document.querySelectorAll('.bk-wrap[data-bk-fit]'));
    if (!wraps.length) return;

    const ZOOM_CSS = !!(window.CSS && CSS.supports && CSS.supports('zoom', '0.5'));
    const CLE = 'sabre.arbre.mode';
    // Zone imprimable d'une page A4 (marges 10 mm) en px CSS, titre déduit.
    // L'orientation retenue est celle qui affiche l'arbre le plus grand.
    const A4_PAYSAGE = { l: 1040, h: 640 }, A4_PORTRAIT = { l: 715, h: 960 };
    const MODES = ['tout', 'largeur', 'reel'];
    let impression = false;

    const lire = () => { try { return localStorage.getItem(CLE); } catch (e) { return null; } };
    const ecrire = v => { try { localStorage.setItem(CLE, v); } catch (e) { /* stockage indisponible */ } };

    /** Taille de l'arbre à 100 %. */
    function tailleReelle(inner) {
        inner.style.zoom = '';
        inner.style.transform = '';
        inner.style.margin = '';
        return { l: inner.offsetWidth, h: inner.offsetHeight };
    }

    function appliquer(wrap, inner, taille, z) {
        if (ZOOM_CSS) {
            inner.style.zoom = z === 1 ? '' : String(z);
        } else if (z !== 1) {
            // Navigateur sans zoom CSS : mise à l'échelle + marges négatives
            // pour que la place occupée suive la taille affichée.
            inner.style.transformOrigin = '0 0';
            inner.style.transform = `scale(${z})`;
            inner.style.marginRight = `${-taille.l * (1 - z)}px`;
            inner.style.marginBottom = `${-taille.h * (1 - z)}px`;
        }
        wrap.dataset.bkZoom = z.toFixed(3);
        wrap.classList.toggle('is-reduit', z < 0.999);
    }

    /** Place disponible pour l'arbre. */
    function place(wrap) {
        const cs = getComputedStyle(wrap);
        const padL = parseFloat(cs.paddingLeft) + parseFloat(cs.paddingRight);
        const padH = parseFloat(cs.paddingTop) + parseFloat(cs.paddingBottom);
        const l = wrap.clientWidth - padL;
        if (wrap.dataset.bkCadre === 'conteneur') {
            // Bloc qui ne s'étire pas (écran de salle sur téléphone) : sa hauteur
            // suit l'arbre, seule la largeur compte.
            const etire = parseFloat(cs.flexGrow) > 0;
            return { l, h: etire ? wrap.clientHeight - padH : Infinity };
        }

        // Page : la fenêtre, moins la barre du haut et la navigation basse (mobile)
        let h = window.innerHeight - padH - 16;
        const barre = document.querySelector('.topbar');
        if (barre && getComputedStyle(barre).position !== 'static') h -= barre.offsetHeight;
        const nav = document.querySelector('.bottom-nav');
        if (nav && getComputedStyle(nav).display !== 'none') h -= nav.offsetHeight;
        return { l, h: Math.max(240, h) };
    }

    function modeDe(wrap) {
        if (wrap.dataset.bkCadre === 'conteneur') return wrap.dataset.bkFit;
        const m = lire();
        return MODES.includes(m) ? m : wrap.dataset.bkFit;
    }

    function ajuster(wrap) {
        const inner = wrap.querySelector('.bk-inner');
        if (!inner) return;
        const taille = tailleReelle(inner);
        if (!taille.l || !taille.h) return;

        let z;
        if (impression) {
            const zPaysage = Math.min(1, A4_PAYSAGE.l / taille.l, A4_PAYSAGE.h / taille.h);
            const zPortrait = Math.min(1, A4_PORTRAIT.l / taille.l, A4_PORTRAIT.h / taille.h);
            document.documentElement.classList.toggle('bk-portrait', zPortrait > zPaysage);
            z = Math.max(zPaysage, zPortrait);
        } else {
            const mode = modeDe(wrap);
            const max = parseFloat(wrap.dataset.bkZoomMax) || 1;
            const p = place(wrap);
            z = mode === 'reel' ? 1
                : Math.min(max, p.l / taille.l, mode === 'tout' ? p.h / taille.h : Infinity);
            wrap.dataset.bkMode = mode;
        }
        appliquer(wrap, inner, taille, Math.max(0.15, z));
        majBarre(wrap);
    }

    /** Boutons « Tout voir / Largeur / Taille réelle » et pourcentage affiché. */
    function majBarre(wrap) {
        const barre = document.querySelector('.bk-toolbar');
        if (!barre || wrap.dataset.bkCadre === 'conteneur') return;
        const mode = wrap.dataset.bkMode;
        barre.querySelectorAll('[data-bk-mode]').forEach(b => {
            const actif = b.dataset.bkMode === mode;
            b.classList.toggle('is-active', actif);
            b.setAttribute('aria-pressed', actif ? 'true' : 'false');
        });
        const info = barre.querySelector('.bk-zoom-info');
        if (info) {
            const pc = Math.round(parseFloat(wrap.dataset.bkZoom) * 100);
            info.textContent = pc === 100 ? '' : `Affiché à ${pc} %`;
        }
    }

    function toutAjuster() {
        // Largeur de la barre de défilement : l'arbre pleine largeur ne doit pas la recouvrir
        const sbw = window.innerWidth - document.documentElement.clientWidth;
        document.documentElement.style.setProperty('--sbw', Math.max(0, sbw) + 'px');
        wraps.forEach(ajuster);
    }
    window.ajusterArbres = toutAjuster;

    document.querySelectorAll('[data-bk-mode]').forEach(b => b.addEventListener('click', () => {
        ecrire(b.dataset.bkMode);
        toutAjuster();
    }));

    // Impression : bouton, Ctrl+P, et Safari (qui n'envoie pas beforeprint)
    const avantImpression = () => { impression = true; toutAjuster(); };
    const apresImpression = () => { impression = false; toutAjuster(); };
    window.addEventListener('beforeprint', avantImpression);
    window.addEventListener('afterprint', apresImpression);
    const mqPrint = window.matchMedia && window.matchMedia('print');
    if (mqPrint && mqPrint.addEventListener) {
        mqPrint.addEventListener('change', e => (e.matches ? avantImpression() : apresImpression()));
    }
    document.querySelectorAll('[data-bk-print]').forEach(b => b.addEventListener('click', () => window.print()));

    // Taille de la fenêtre, rotation du téléphone, polices chargées
    let attente = null;
    const plusTard = () => { clearTimeout(attente); attente = setTimeout(() => { if (!impression) toutAjuster(); }, 80); };
    window.addEventListener('resize', plusTard);
    if (window.ResizeObserver) {
        const dernier = new WeakMap();
        const ro = new ResizeObserver(entrees => {
            for (const e of entrees) {
                const avant = dernier.get(e.target);
                const l = Math.round(e.contentRect.width), h = Math.round(e.contentRect.height);
                dernier.set(e.target, { l, h });
                // La hauteur d'une page change avec le zoom : seule la largeur compte
                const cadre = e.target.dataset.bkCadre === 'conteneur';
                if (avant && avant.l === l && (!cadre || avant.h === h)) continue;
                if (avant) plusTard();
            }
        });
        wraps.forEach(w => ro.observe(w));
    }
    if (document.fonts && document.fonts.ready) document.fonts.ready.then(toutAjuster);

    toutAjuster();
})();
