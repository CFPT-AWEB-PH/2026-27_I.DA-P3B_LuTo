/**
 * app.js : comportements globaux de l'interface (v5)
 *
 *  - Toasts (window.Toast) et messages du serveur affichés en toasts
 *  - Menus déroulants <details class="menu"> : clic extérieur, Échap, flèches
 *  - Boutons en chargement pendant l'envoi d'un formulaire (anti double envoi)
 *  - Validation des formulaires au fil de la saisie (messages clairs)
 *  - Modale de confirmation (window.Modal) qui remplace window.confirm()
 *  - Statut de connexion global (window.ConnStatus)
 *  - Horloge du serveur, animation d'un score qui change (window.bumpScore)
 */
(() => {
    'use strict';

    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const ICON = name => `<svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-${name}"></use></svg>`;

    /* ============================================================
       TOASTS
       ============================================================ */
    const Toast = (() => {
        const ICONS = { success: 'check', error: 'alert-triangle', warning: 'alert-triangle', info: 'info' };
        let region = null;

        const container = () => {
            region = region && region.isConnected ? region : document.getElementById('toast-region');
            if (!region) {
                region = document.createElement('div');
                region.className = 'toast-container';
                region.id = 'toast-region';
                document.body.appendChild(region);
            }
            return region;
        };

        const dismiss = (el) => {
            if (!el || el.classList.contains('is-hiding')) return;
            el.classList.add('is-hiding');
            const done = () => el.remove();
            el.addEventListener('animationend', done, { once: true });
            setTimeout(() => el.isConnected && done(), 300);
        };

        const arm = (el, duration) => {
            el.querySelector('.toast-close')?.addEventListener('click', () => dismiss(el));
            if (duration > 0) {
                let timer = setTimeout(() => dismiss(el), duration);
                el.addEventListener('mouseenter', () => clearTimeout(timer));
                el.addEventListener('mouseleave', () => { timer = setTimeout(() => dismiss(el), 2000); });
                el.addEventListener('focusin', () => clearTimeout(timer));
            }
        };

        const show = (message, type = 'info', duration = 5000) => {
            const el = document.createElement('div');
            el.className = `toast toast-${type}`;
            el.setAttribute('role', type === 'error' ? 'alert' : 'status');
            el.innerHTML = `<span class="toast-icon" aria-hidden="true">${ICON(ICONS[type] || 'info')}</span>`
                + '<span class="toast-msg"></span>'
                + `<button class="toast-close" type="button" aria-label="Fermer">${ICON('x')}</button>`;
            el.querySelector('.toast-msg').textContent = message; // jamais de HTML injecté
            container().appendChild(el);
            arm(el, duration);
            return el;
        };

        // Messages rendus par le serveur (succès : disparaissent seuls ; erreurs : restent)
        document.querySelectorAll('#toast-region .toast').forEach(el => arm(el, el.dataset.autohide === '1' ? 5000 : 0));

        return {
            show, dismiss,
            success: (m, d) => show(m, 'success', d ?? 4000),
            error:   (m, d) => show(m, 'error', d ?? 0),
            warning: (m, d) => show(m, 'warning', d ?? 6000),
            info:    (m, d) => show(m, 'info', d ?? 5000),
        };
    })();
    window.Toast = Toast;

    /* ============================================================
       STATUT DE CONNEXION (un seul indicateur pour toute la page)
       ============================================================ */
    window.ConnStatus = (() => {
        let el = null, perdu = false, hideTimer = null;
        const render = (ok) => {
            clearTimeout(hideTimer);
            if (!el) {
                el = document.createElement('div');
                el.className = 'conn-status';
                el.setAttribute('role', 'status');
                document.body.appendChild(el);
            }
            el.classList.remove('is-hiding');
            el.classList.toggle('is-ok', ok);
            el.innerHTML = ok ? `${ICON('check')}<span>Connexion rétablie</span>` : `${ICON('offline')}<span>Connexion perdue · nouvelle tentative…</span>`;
        };
        return {
            lost() { if (perdu) return; perdu = true; render(false); },
            ok() {
                if (!perdu) return;
                perdu = false; render(true);
                hideTimer = setTimeout(() => {
                    el.classList.add('is-hiding');
                    setTimeout(() => { if (!perdu && el) { el.remove(); el = null; } }, 220);
                }, 1800);
            },
            get isLost() { return perdu; },
        };
    })();
    window.addEventListener('offline', () => window.ConnStatus.lost());
    window.addEventListener('online', () => window.ConnStatus.ok());

    /* ============================================================
       SCORE QUI CHANGE : petite impulsion visuelle
       ============================================================ */
    window.bumpScore = (el) => {
        if (!el || reduceMotion) return;
        el.classList.remove('score-updated');
        void el.offsetWidth; // relance l'animation
        el.classList.add('score-updated');
        el.addEventListener('animationend', () => el.classList.remove('score-updated'), { once: true });
    };

    /* ============================================================
       MENUS DÉROULANTS <details class="menu">
       ============================================================ */
    const menus = () => Array.from(document.querySelectorAll('details.menu[open]'));
    const closeMenu = (d, focus) => { d.open = false; if (focus) d.querySelector('summary')?.focus(); };

    document.addEventListener('toggle', (e) => {
        const d = e.target;
        if (!(d instanceof HTMLDetailsElement) || !d.classList.contains('menu') || !d.open) return;
        menus().forEach(o => { if (o !== d) o.open = false; });
        // Menus des lignes de tableau : positionnés en « fixed » pour ne pas être
        // coupés par le défilement horizontal du tableau ; ouverts vers le haut
        // si la place manque en bas de l'écran.
        const panel = d.querySelector('.menu-panel');
        if (!panel || d.classList.contains('account-menu')) return;
        const r = d.querySelector('summary').getBoundingClientRect();
        const bas = window.innerHeight - (document.querySelector('.bottom-nav')?.offsetHeight || 0);
        Object.assign(panel.style, { position: 'fixed', left: 'auto', right: Math.max(8, window.innerWidth - r.right) + 'px', top: (r.bottom + 6) + 'px', bottom: 'auto' });
        const h = panel.offsetHeight;
        if (r.bottom + 6 + h > bas - 8 && r.top - 6 - h > 8) {
            panel.style.top = (r.top - 6 - h) + 'px';
            panel.style.transformOrigin = 'bottom right';
        } else {
            panel.style.transformOrigin = 'top right';
        }
    }, true);

    document.addEventListener('click', (e) => {
        menus().forEach(d => {
            if (!d.contains(e.target)) closeMenu(d);
            else if (e.target.closest('.menu-panel a, .menu-panel button[type=button]')) closeMenu(d);
        });
    });

    // Un défilement ou un redimensionnement ferme les menus positionnés
    const fermerFlottants = (e) => menus().forEach(d => {
        if (d.classList.contains('account-menu')) return;
        if (e && e.type === 'scroll' && d.contains(e.target)) return;
        closeMenu(d);
    });
    window.addEventListener('scroll', fermerFlottants, { passive: true, capture: true });
    window.addEventListener('resize', fermerFlottants);

    document.addEventListener('keydown', (e) => {
        const open = menus();
        if (!open.length) return;
        const d = open[open.length - 1];
        if (e.key === 'Escape') { e.preventDefault(); closeMenu(d, true); return; }
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            const items = Array.from(d.querySelectorAll('.menu-panel a, .menu-panel button'));
            if (!items.length) return;
            e.preventDefault();
            const i = items.indexOf(document.activeElement);
            const next = e.key === 'ArrowDown' ? (i + 1) % items.length : (i <= 0 ? items.length - 1 : i - 1);
            items[next].focus();
        }
    });

    /* ============================================================
       ONGLETS : l'onglet actif est toujours visible (mobile)
       ============================================================ */
    document.querySelectorAll('.tabs [aria-current="page"]').forEach(a => {
        const nav = a.parentElement;
        nav.scrollLeft = a.offsetLeft - (nav.clientWidth - a.offsetWidth) / 2;
    });

    /* ============================================================
       ENVOI DE FORMULAIRE : bouton en chargement, pas de double envoi
       ============================================================ */
    const resetBusy = (btn) => {
        btn.disabled = false;
        btn.removeAttribute('aria-busy');
        btn.classList.remove('is-submitting');
    };
    document.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-no-busy')) return;
        const btn = (event.submitter && event.submitter.matches('button, input[type=submit]')) ? event.submitter
            : form.querySelector('button[type="submit"]:not([disabled]), button:not([type]):not([disabled])');
        if (!btn) return;
        // On laisse le navigateur lire la valeur du bouton avant de le désactiver
        requestAnimationFrame(() => {
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.classList.add('is-submitting');
        });
        setTimeout(() => btn.isConnected && resetBusy(btn), 15000);
    });
    window.addEventListener('pageshow', (event) => {
        if (event.persisted) document.querySelectorAll('[aria-busy="true"]').forEach(resetBusy);
    });

    /* ============================================================
       VALIDATION DES CHAMPS
       Règles lues dans le HTML : required, minlength, pattern,
       type=email/number (min/max), data-match="id du champ à égaler".
       ============================================================ */
    const messageErreur = (input) => {
        const v = input.value;
        if (input.required && !v.trim()) {
            return input.tagName === 'SELECT' ? 'Choisissez une option.' : 'Ce champ est obligatoire.';
        }
        if (!v) return '';
        if (input.minLength > 0 && v.length < input.minLength) return `Au moins ${input.minLength} caractères (encore ${input.minLength - v.length}).`;
        if (input.pattern && !new RegExp(`^(?:${input.pattern})$`).test(v)) return input.dataset.patternMsg || input.title || 'Format non valide.';
        if (input.type === 'number' && input.validity && (input.validity.rangeUnderflow || input.validity.rangeOverflow || input.validity.badInput)) {
            if (input.validity.badInput) return 'Entrez un nombre.';
            return input.min !== '' && Number(v) < Number(input.min) ? `Minimum ${input.min}.` : `Maximum ${input.max}.`;
        }
        if (input.dataset.match) {
            const other = document.getElementById(input.dataset.match);
            if (other && other.value !== v) return 'Les deux mots de passe ne correspondent pas.';
        }
        return '';
    };

    const fieldOf = (input) => input.closest('.field');
    const validate = (input) => {
        const field = fieldOf(input);
        if (!field) return true;
        let msg = field.querySelector('.field-error-msg');
        if (!msg) {
            msg = document.createElement('span');
            msg.className = 'field-error-msg';
            msg.id = (input.id || 'f' + Math.random().toString(36).slice(2)) + '-err';
            msg.setAttribute('aria-live', 'polite');
            field.appendChild(msg);
        }
        const err = messageErreur(input);
        field.classList.toggle('has-error', !!err);
        input.setAttribute('aria-invalid', err ? 'true' : 'false');
        if (err) {
            const ids = new Set((input.getAttribute('aria-describedby') || '').split(' ').filter(Boolean));
            ids.add(msg.id);
            input.setAttribute('aria-describedby', [...ids].join(' '));
        }
        msg.textContent = err;
        return !err;
    };

    const champs = (form) => Array.from(form.querySelectorAll('.field input:not([type=hidden]):not([type=checkbox]):not([type=radio]), .field select, .field textarea'))
        .filter(i => !i.disabled && (i.required || i.minLength > 0 || i.pattern || i.dataset.match || i.type === 'number'));

    document.querySelectorAll('form:not([data-no-validate])').forEach(form => {
        const list = champs(form);
        if (!list.length) return;
        list.forEach(input => {
            input.addEventListener('blur', () => { if (input.value || fieldOf(input)?.classList.contains('has-error')) validate(input); });
            input.addEventListener('input', () => { if (fieldOf(input)?.classList.contains('has-error')) validate(input); });
            if (input.dataset.match) {
                document.getElementById(input.dataset.match)?.addEventListener('input', () => { if (input.value) validate(input); });
            }
        });
        form.addEventListener('submit', (e) => {
            const invalides = list.filter(i => !validate(i));
            if (invalides.length) {
                e.preventDefault();
                e.stopImmediatePropagation();
                invalides[0].focus();
            }
        }, true);
    });

    /* ============================================================
       AFFICHER / MASQUER LE MOT DE PASSE
       ============================================================ */
    document.querySelectorAll('.password-toggle-btn').forEach(btn => {
        const input = document.getElementById(btn.dataset.target);
        if (!input) return;
        btn.setAttribute('aria-pressed', 'false');
        btn.addEventListener('click', () => {
            const visible = input.type === 'text';
            input.type = visible ? 'password' : 'text';
            btn.setAttribute('aria-pressed', visible ? 'false' : 'true');
            btn.setAttribute('aria-label', visible ? 'Afficher le mot de passe' : 'Masquer le mot de passe');
            btn.querySelector('use')?.setAttribute('href', visible ? '#icon-eye' : '#icon-eye-off');
            input.focus();
        });
    });

    /* ============================================================
       MODALE DE CONFIRMATION : window.Modal.show({...}) -> Promise<bool>
       ============================================================ */
    const fermerDialog = (dialog, value) => {
        if (reduceMotion || !dialog.open) { dialog.close(value); return; }
        dialog.classList.add('is-closing');
        setTimeout(() => { dialog.classList.remove('is-closing'); dialog.close(value); }, 160);
    };
    window.fermerDialog = fermerDialog;

    const Modal = (() => {
        let dialog = null;
        const init = () => {
            dialog = document.createElement('dialog');
            dialog.className = 'modal';
            dialog.setAttribute('aria-labelledby', 'modal-title');
            dialog.setAttribute('aria-describedby', 'modal-text');
            dialog.innerHTML = `
                <div class="modal-inner">
                    <p class="modal-title" id="modal-title"></p>
                    <p class="modal-text" id="modal-text"></p>
                    <div class="modal-actions">
                        <button class="btn btn-secondary modal-cancel-btn" type="button">Annuler</button>
                        <button class="btn modal-confirm-btn" type="button">Confirmer</button>
                    </div>
                </div>`;
            document.body.appendChild(dialog);
            dialog.querySelector('.modal-cancel-btn').addEventListener('click', () => fermerDialog(dialog, 'cancel'));
            dialog.addEventListener('click', (e) => { if (e.target === dialog) fermerDialog(dialog, 'cancel'); });
            dialog.addEventListener('cancel', (e) => { e.preventDefault(); fermerDialog(dialog, 'cancel'); });
        };

        const show = ({ message, text = '', confirmLabel = 'Confirmer', type = 'danger' }) => new Promise(resolve => {
            if (!dialog) init();
            dialog.querySelector('#modal-title').textContent = message;
            dialog.querySelector('#modal-text').textContent = text;
            const old = dialog.querySelector('.modal-confirm-btn');
            const btn = old.cloneNode(true);
            old.replaceWith(btn);
            btn.className = `btn modal-confirm-btn${type === 'danger' ? ' btn-danger' : type === 'warning' ? ' btn-warning' : ''}`;
            btn.textContent = confirmLabel;
            const onClose = () => { dialog.removeEventListener('close', onClose); resolve(dialog.returnValue === 'confirm'); };
            dialog.addEventListener('close', onClose);
            btn.addEventListener('click', () => fermerDialog(dialog, 'confirm'), { once: true });
            dialog.returnValue = '';
            dialog.showModal();
            // Action destructive : le focus va sur « Annuler » (choix sûr par défaut)
            (type === 'danger' ? dialog.querySelector('.modal-cancel-btn') : btn).focus();
        });
        return { show };
    })();
    window.Modal = Modal;

    // Éléments avec data-confirm (liens et boutons)
    document.addEventListener('click', async (e) => {
        const el = e.target.closest('[data-confirm]');
        if (!el) return;
        e.preventDefault();
        e.stopPropagation();
        const ok = await Modal.show({
            message: el.dataset.confirm,
            text: el.dataset.confirmText || '',
            confirmLabel: el.dataset.confirmLabel || 'Confirmer',
            type: el.dataset.confirmType || 'danger',
        });
        if (!ok) return;
        if (el.tagName === 'A') {
            window.location.href = el.href;
        } else if (el.form) {
            el.removeAttribute('data-confirm');
            el.form.requestSubmit(el);
        }
    }, true);

    /* ============================================================
       MODALE DE FORMULAIRE (?action=new / edit) : ouverte au chargement
       ============================================================ */
    const formModal = document.getElementById('form-modal');
    if (formModal instanceof HTMLDialogElement) {
        formModal.showModal();
        const cancelHref = () => formModal.querySelector('a.modal-close-btn, .modal-footer a.btn-secondary')?.href;
        const quitter = () => { const h = cancelHref(); fermerDialog(formModal, 'cancel'); if (h) setTimeout(() => { window.location.href = h; }, 170); };
        formModal.addEventListener('click', (e) => { if (e.target === formModal) quitter(); });
        formModal.addEventListener('cancel', (e) => { e.preventDefault(); quitter(); });
        const first = formModal.querySelector('.field-error-msg:not(:empty)') ? formModal.querySelector('[aria-invalid="true"]')
            : formModal.querySelector('input:not([type=hidden]):not([disabled]):not([type=checkbox]), select, textarea');
        if (first) setTimeout(() => first.focus(), 60);
    }

    /* ============================================================
       STEPPER (long appui = défilement rapide)
       ============================================================ */
    let longPress = null, fast = null;
    const step = (btn, repeat) => {
        const input = document.getElementById(btn.dataset.target);
        if (!(input instanceof HTMLInputElement)) return;
        const s = parseFloat(input.step) || 1;
        const min = input.min !== '' ? parseFloat(input.min) : -Infinity;
        const max = input.max !== '' ? parseFloat(input.max) : Infinity;
        const cur = parseFloat(input.value) || 0;
        const next = Math.min(max, Math.max(min, Math.round((cur + (btn.classList.contains('js-step-up') ? s : -s)) * 100) / 100));
        if (next !== cur) {
            input.value = String(next);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            if (repeat && navigator.vibrate) navigator.vibrate(8);
        }
    };
    document.addEventListener('click', (e) => { const b = e.target.closest('.js-step-up, .js-step-down'); if (b) step(b); });
    document.addEventListener('pointerdown', (e) => {
        const b = e.target.closest('.js-step-up, .js-step-down');
        if (!b) return;
        b.classList.add('is-pressing');
        longPress = setTimeout(() => { fast = setInterval(() => step(b, true), 100); }, 450);
    });
    const stop = () => {
        document.querySelectorAll('.is-pressing').forEach(b => b.classList.remove('is-pressing'));
        clearTimeout(longPress); clearInterval(fast);
    };
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(t => document.addEventListener(t, stop));

    /* ============================================================
       HORLOGE DU SERVEUR
       L'heure du serveur est rendue en « heure murale » : on ajoute le
       décalage du fuseau du serveur transmis par PHP.
       ============================================================ */
    const clocks = document.querySelectorAll('[data-server-clock]');
    if (clocks.length && window.serverNow) {
        const fmt = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit', second: '2-digit', timeZone: 'UTC' });
        const offset = (window.SABRE_CONFIG && window.SABRE_CONFIG.tzOffsetMs) || 0;
        const tick = () => { const t = fmt.format(new Date(window.serverNow() + offset)); clocks.forEach(el => { el.textContent = t; }); };
        tick();
        setInterval(tick, 1000);
    }

    /* ============================================================
       FORMULAIRES À ENVOI IMMÉDIAT (interrupteurs) : <form data-autosubmit>
       ============================================================ */
    document.querySelectorAll('form[data-autosubmit]').forEach(form => {
        form.addEventListener('change', (e) => {
            if (e.target.matches('[role=switch]')) e.target.setAttribute('aria-checked', e.target.checked ? 'true' : 'false');
            form.requestSubmit();
        });
    });

    /* ============================================================
       FILTRE DE TABLEAU : <input data-filter="#id-du-tbody">
       ============================================================ */
    document.querySelectorAll('input[data-filter]').forEach(input => {
        const body = document.querySelector(input.dataset.filter);
        if (!body) return;
        const empty = document.querySelector(input.dataset.filterEmpty || '');
        const count = document.querySelector(input.dataset.filterCount || '');
        const run = () => {
            const q = input.value.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
            let n = 0;
            body.querySelectorAll('tr[data-search]').forEach(tr => {
                const hit = !q || tr.dataset.search.normalize('NFD').replace(/[̀-ͯ]/g, '').includes(q);
                tr.classList.toggle('is-hidden', !hit);
                if (hit) n++;
            });
            if (empty) empty.hidden = n > 0;
            if (count) count.textContent = q ? `${n} résultat${n > 1 ? 's' : ''}` : '';
        };
        input.addEventListener('input', run);
        // « / » place le curseur dans la recherche
        document.addEventListener('keydown', (e) => {
            if (e.key === '/' && !e.target.closest('input, textarea, select, [contenteditable]')) { e.preventDefault(); input.focus(); }
        });
    });
})();
