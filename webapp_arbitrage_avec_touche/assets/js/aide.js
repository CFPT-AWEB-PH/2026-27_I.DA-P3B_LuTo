/* =====================================================================
   aide.js : page Aide et guide (aide.php)
   - recherche instantanée (sans accents, plusieurs mots), raccourci « / »
   - lien direct vers une fiche (#id) : ouverte et mise en évidence
   - sommaire qui suit la lecture, zoom des captures, check-list du parcours
   ===================================================================== */
(() => {
    const input = document.getElementById('guide-q');
    if (!input) return;
    const fiches = [...document.querySelectorAll('.guide-fiche')];
    const sections = [...document.querySelectorAll('.guide-section')];
    const count = document.getElementById('guide-count');
    const vide = document.getElementById('guide-vide');
    const raccourcis = document.querySelector('.guide-raccourcis');
    const norm = s => s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[’']/g, ' ');
    const textes = new Map(fiches.map(f => [f, norm(f.dataset.search + ' ' + f.querySelector('.guide-fiche-titre').textContent)]));
    const ouvertesParRecherche = new Set();

    // ── Recherche ─────────────────────────────────────────────────────
    function chercher() {
        const mots = norm(input.value.trim()).split(/\s+/).filter(m => m.length > 1 || /\d/.test(m));
        let n = 0;
        ouvertesParRecherche.forEach(f => { f.open = false; });
        ouvertesParRecherche.clear();
        const trouvees = [];
        fiches.forEach(f => {
            const ok = !mots.length || mots.every(m => textes.get(f).includes(m));
            f.hidden = !ok;
            if (ok) { n++; trouvees.push(f); }
        });
        sections.forEach(s => { s.hidden = !s.querySelector('.guide-fiche:not([hidden])'); });
        vide.hidden = n > 0;
        if (raccourcis) raccourcis.hidden = mots.length > 0;
        count.textContent = mots.length ? (n ? `${n} fiche${n > 1 ? 's' : ''} trouvée${n > 1 ? 's' : ''}` : '') : '';
        // Peu de résultats : on les ouvre directement
        if (mots.length && n > 0 && n <= 3) trouvees.forEach(f => { if (!f.open) { f.open = true; ouvertesParRecherche.add(f); } });
        // Mot cherché dans l'adresse : la recherche se partage et survit au rechargement
        const url = new URL(location.href);
        if (input.value.trim()) url.searchParams.set('q', input.value.trim()); else url.searchParams.delete('q');
        history.replaceState(null, '', url);
    }
    let minuterie;
    input.addEventListener('input', () => { clearTimeout(minuterie); minuterie = setTimeout(chercher, 120); });
    input.addEventListener('keydown', e => {
        if (e.key === 'Escape' && input.value) { e.preventDefault(); input.value = ''; chercher(); }
        if (e.key === 'Enter') {
            e.preventDefault();
            const premiere = fiches.find(f => !f.hidden);
            if (premiere) ouvrirFiche(premiere.id);
        }
    });
    document.addEventListener('keydown', e => {
        if (e.key === '/' && !e.target.closest('input, textarea, select, [contenteditable]')) { e.preventDefault(); input.focus(); input.select(); }
    });
    document.getElementById('guide-reset')?.addEventListener('click', () => { input.value = ''; chercher(); input.focus(); });

    // ── Ouvrir une fiche (lien #id, sommaire, « voir aussi ») ────────
    function ouvrirFiche(id, defiler = true) {
        const f = document.getElementById(id);
        if (!f || !f.classList.contains('guide-fiche')) return false;
        if (f.hidden) { input.value = ''; chercher(); }
        f.open = true;
        ouvertesParRecherche.delete(f);
        f.classList.remove('is-flash');
        void f.offsetWidth;
        f.classList.add('is-flash');
        if (defiler) f.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
        return true;
    }
    function suivreAncre() {
        const id = decodeURIComponent(location.hash.slice(1));
        if (!id) return;
        if (id.startsWith('section-')) {
            const s = document.getElementById(id);
            if (s && s.hidden) { input.value = ''; chercher(); s.scrollIntoView(); }
            return;
        }
        ouvrirFiche(id);
    }
    window.addEventListener('hashchange', suivreAncre);
    document.addEventListener('click', e => {
        const a = e.target.closest('a[href^="#"]');
        if (!a) return;
        const id = a.getAttribute('href').slice(1);
        if (id && document.getElementById(id)?.classList.contains('guide-fiche')) {
            e.preventDefault();
            history.pushState(null, '', '#' + id);
            ouvrirFiche(id);
        }
    });
    // L'URL suit la fiche ouverte (pour copier / partager le lien)
    fiches.forEach(f => f.addEventListener('toggle', () => {
        if (f.open && !ouvertesParRecherche.has(f) && location.hash !== '#' + f.id) history.replaceState(null, '', location.pathname + location.search + '#' + f.id);
    }));

    // ── Copier le lien d'une fiche ────────────────────────────────────
    document.querySelectorAll('[data-copier-lien]').forEach(btn => btn.addEventListener('click', async () => {
        const url = location.origin + location.pathname + '#' + btn.dataset.copierLien;
        try {
            await navigator.clipboard.writeText(url);
            window.Toast?.success('Lien de la fiche copié.');
        } catch {
            window.prompt('Copiez le lien de la fiche :', url);
        }
    }));

    // ── Sommaire : section en cours de lecture ────────────────────────
    const liensToc = new Map([...document.querySelectorAll('[data-toc-section]')].map(a => [a.dataset.tocSection, a]));
    if ('IntersectionObserver' in window && liensToc.size) {
        const visibles = new Set();
        const obs = new IntersectionObserver(entrees => {
            entrees.forEach(en => en.isIntersecting ? visibles.add(en.target) : visibles.delete(en.target));
            const premiere = sections.find(s => visibles.has(s));
            liensToc.forEach((a, cle) => {
                if (premiere && cle === premiere.dataset.section) a.setAttribute('aria-current', 'true');
                else a.removeAttribute('aria-current');
            });
        }, { rootMargin: '-80px 0px -55% 0px' });
        sections.forEach(s => obs.observe(s));
    }

    // ── Zoom des captures d'écran ─────────────────────────────────────
    const boite = document.getElementById('guide-lightbox');
    if (boite && typeof boite.showModal === 'function') {
        const img = boite.querySelector('img');
        const legende = boite.querySelector('.guide-lightbox-legende');
        document.querySelectorAll('[data-zoom]').forEach(btn => btn.addEventListener('click', () => {
            img.src = btn.dataset.zoom;
            img.alt = btn.dataset.zoomAlt || '';
            legende.textContent = btn.dataset.zoomAlt || '';
            boite.showModal();
        }));
        boite.querySelector('.guide-lightbox-close').addEventListener('click', () => boite.close());
        boite.addEventListener('click', e => { if (e.target === boite || e.target === img) boite.close(); });
    } else {
        // Navigateur sans <dialog> : l'image s'ouvre dans un nouvel onglet
        document.querySelectorAll('[data-zoom]').forEach(btn => btn.addEventListener('click', () => window.open(btn.dataset.zoom, '_blank', 'noopener')));
    }

    // ── Check-list du parcours (mémorisée sur cet appareil) ───────────
    document.querySelectorAll('[data-parcours]').forEach(liste => {
        const cle = 'sabre-guide-' + liste.dataset.parcours;
        const cases = [...liste.querySelectorAll('input[data-etape]')];
        const bilan = liste.parentElement.querySelector('.js-parcours-bilan');
        let faites = [];
        try { faites = JSON.parse(localStorage.getItem(cle) || '[]'); } catch { faites = []; }
        const maj = () => {
            const n = cases.filter(c => c.checked).length;
            cases.forEach(c => c.closest('li').classList.toggle('is-fait', c.checked));
            if (bilan) bilan.textContent = n === cases.length ? 'Tout est prêt : bon tournoi !' : `${n} étape${n > 1 ? 's' : ''} sur ${cases.length} faite${n > 1 ? 's' : ''}.`;
        };
        cases.forEach(c => {
            c.checked = faites.includes(c.dataset.etape);
            c.addEventListener('change', () => {
                try { localStorage.setItem(cle, JSON.stringify(cases.filter(x => x.checked).map(x => x.dataset.etape))); } catch { /* stockage indisponible */ }
                maj();
            });
        });
        maj();
    });

    // ── Impression : tout le guide (déjà limité au rôle par le serveur), fiches ouvertes ──
    let etatAvantImpression = null;
    window.addEventListener('beforeprint', () => {
        if (etatAvantImpression) return;
        etatAvantImpression = {
            q: input.value,
            ouvertes: fiches.filter(f => f.open),
        };
        fiches.forEach(f => { f.hidden = false; f.open = true; });
        sections.forEach(s => { s.hidden = false; });
        vide.hidden = true;
        // Les captures « lazy » doivent être chargées avant la mise en page
        document.querySelectorAll('.guide-capture img').forEach(img => { img.loading = 'eager'; });
    });
    window.addEventListener('afterprint', () => {
        if (!etatAvantImpression) return;
        const { q, ouvertes } = etatAvantImpression;
        etatAvantImpression = null;
        fiches.forEach(f => { f.open = ouvertes.includes(f); });
        input.value = q;
        chercher();
    });
    document.getElementById('guide-imprimer')?.addEventListener('click', async () => {
        window.dispatchEvent(new Event('beforeprint'));
        // Attendre les captures pour qu'aucune ne sorte vide sur le papier
        await Promise.all([...document.querySelectorAll('.guide-capture img')].map(img =>
            img.complete ? null : new Promise(r => { img.addEventListener('load', r, { once: true }); img.addEventListener('error', r, { once: true }); })));
        window.print();
    });

    if (input.value.trim()) chercher();
    suivreAncre();
})();
