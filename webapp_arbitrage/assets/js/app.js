/**
 * app.js — Comportements globaux transverses à toute l'application.
 *
 * Protection anti double-soumission : désactive le bouton de tout formulaire
 * dès qu'il est validé et envoyé, pour éviter qu'un double-clic ou un réseau
 * lent ne déclenche deux fois la même action (créer deux fois un combat,
 * enregistrer deux fois un vote, etc.). Délégué au niveau document pour
 * couvrir tous les formulaires de l'appli sans les modifier un par un.
 *
 * Écouté en phase de bubbling : si un formulaire a son propre gestionnaire
 * "submit" qui appelle preventDefault() (ex. validation des arbitres en
 * double sur admin/combats.php), ce gestionnaire s'exécute avant celui-ci
 * (phase "at target" avant "bubbling"), donc event.defaultPrevented reflète
 * déjà correctement si la soumission a été bloquée : on ne désactive alors
 * jamais un bouton dont le formulaire n'a pas réellement été envoyé.
 */
document.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;

    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    const submitBtn = form.querySelector('button[type="submit"]:not([disabled]), input[type="submit"]:not([disabled])');
    if (!submitBtn) return;

    submitBtn.disabled = true;
    submitBtn.setAttribute('aria-busy', 'true');
    submitBtn.classList.add('is-submitting');

    if (submitBtn.tagName === 'BUTTON') {
        submitBtn.dataset.originalHtml = submitBtn.innerHTML;
        submitBtn.innerHTML = '<svg class="icon spin" aria-hidden="true" focusable="false"><use href="#icon-refresh"></use></svg> Envoi en cours…';
    } else {
        submitBtn.dataset.originalValue = submitBtn.value;
        submitBtn.value = 'Envoi en cours…';
    }

    // Filet de sécurité : si la navigation n'a pas eu lieu au bout de 15s
    // (requête bloquée, page mise en cache arrière avant/arrière du
    // navigateur, etc.), on réactive le bouton pour ne jamais laisser
    // l'utilisateur bloqué sans recours.
    window.setTimeout(() => {
        if (!submitBtn.isConnected) return;
        submitBtn.disabled = false;
        submitBtn.removeAttribute('aria-busy');
        submitBtn.classList.remove('is-submitting');
        if (submitBtn.dataset.originalHtml !== undefined) submitBtn.innerHTML = submitBtn.dataset.originalHtml;
        if (submitBtn.dataset.originalValue !== undefined) submitBtn.value = submitBtn.dataset.originalValue;
    }, 15000);
}, false);

/**
 * Menu de navigation mobile : le bouton .nav-toggle (visible seulement sous
 * le point de rupture mobile, voir style.css) bascule l'affichage de
 * .main-nav et son propre état aria-expanded. Fermeture automatique au
 * clic sur un lien ou à l'appui sur Échap, pour rester utilisable au clavier.
 */
(() => {
    const toggle = document.getElementById('nav-toggle');
    const nav = document.getElementById('main-nav');
    if (!toggle || !nav) return;

    const close = () => {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
    };

    toggle.addEventListener('click', () => {
        const open = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    nav.addEventListener('click', (event) => {
        if (event.target instanceof HTMLElement && event.target.closest('a')) close();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') close();
    });
})();

/**
 * Boutons +/- des champs de points en arbitrage (arbitrage/saisie.php) :
 * grandes cibles tactiles pour saisir vite et sans erreur pendant un combat,
 * en plus du champ number natif (clavier/tactile) qui reste pleinement
 * fonctionnel et respecte toujours min/max/step définis en PHP.
 */
document.addEventListener('click', (event) => {
    const btn = event.target.closest('.js-step-up, .js-step-down');
    if (!btn) return;
    const input = document.getElementById(btn.dataset.target);
    if (!(input instanceof HTMLInputElement)) return;
    const step = parseFloat(input.step) || 1;
    const min = input.min !== '' ? parseFloat(input.min) : -Infinity;
    const max = input.max !== '' ? parseFloat(input.max) : Infinity;
    const current = parseFloat(input.value) || 0;
    const delta = btn.classList.contains('js-step-up') ? step : -step;
    const next = Math.min(max, Math.max(min, Math.round((current + delta) * 100) / 100));
    input.value = String(next);
    input.dispatchEvent(new Event('input', { bubbles: true }));
});

// Réactive aussi le bouton si l'utilisateur revient sur la page via le
// bouton "précédent" du navigateur (bfcache) : sinon un formulaire déjà
// soumis une fois resterait bloqué en apparence si l'utilisateur revient
// dessus pour le corriger.
window.addEventListener('pageshow', (event) => {
    if (!event.persisted) return;
    document.querySelectorAll('button[aria-busy="true"], input[aria-busy="true"]').forEach((btn) => {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        btn.classList.remove('is-submitting');
        if (btn.dataset.originalHtml !== undefined) btn.innerHTML = btn.dataset.originalHtml;
        if (btn.dataset.originalValue !== undefined) btn.value = btn.dataset.originalValue;
    });
});
