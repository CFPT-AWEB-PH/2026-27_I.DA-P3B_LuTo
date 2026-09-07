/**
 * app.js — UX Global v2
 * Bottom nav mobile, ripple, long-press stepper, scroll topbar, animations scores
 */

/* ============================================================
   ANTI DOUBLE-SOUMISSION
   ============================================================ */
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
        submitBtn.innerHTML = '<svg class="icon spin" aria-hidden="true" focusable="false"><use href="#icon-refresh"></use></svg> Envoi…';
    } else {
        submitBtn.dataset.originalValue = submitBtn.value;
        submitBtn.value = 'Envoi en cours…';
    }
    window.setTimeout(() => {
        if (!submitBtn.isConnected) return;
        submitBtn.disabled = false;
        submitBtn.removeAttribute('aria-busy');
        submitBtn.classList.remove('is-submitting');
        if (submitBtn.dataset.originalHtml !== undefined) submitBtn.innerHTML = submitBtn.dataset.originalHtml;
        if (submitBtn.dataset.originalValue !== undefined) submitBtn.value = submitBtn.dataset.originalValue;
    }, 15000);
}, false);

/* ============================================================
   MENU MOBILE (topbar)
   ============================================================ */
(() => {
    const toggle = document.getElementById('nav-toggle');
    const nav = document.getElementById('main-nav');
    if (!toggle || !nav) return;

    const close = () => {
        nav.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        toggle.querySelector('span.visually-hidden') && (toggle.querySelector('span.visually-hidden').textContent = 'Ouvrir le menu');
    };

    toggle.addEventListener('click', () => {
        const open = nav.classList.toggle('is-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        const label = toggle.querySelector('span.visually-hidden');
        if (label) label.textContent = open ? 'Fermer le menu' : 'Ouvrir le menu';
    });

    nav.addEventListener('click', (event) => {
        if (event.target instanceof HTMLElement && event.target.closest('a')) close();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && nav.classList.contains('is-open')) {
            close();
            toggle.focus();
        }
    });

    // Ferme si clic hors du menu
    document.addEventListener('click', (e) => {
        if (!nav.contains(e.target) && !toggle.contains(e.target)) close();
    });
})();

/* ============================================================
   TOPBAR — EFFET SCROLL
   ============================================================ */
(() => {
    const topbar = document.querySelector('.topbar');
    if (!topbar) return;
    const onScroll = () => topbar.classList.toggle('is-scrolled', window.scrollY > 20);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
})();

/* ============================================================
   RIPPLE sur boutons
   ============================================================ */
document.addEventListener('pointerdown', (e) => {
    const btn = e.target.closest('button:not(.stepper-btn), .btn');
    if (!btn) return;
    const rect = btn.getBoundingClientRect();
    const size = Math.max(rect.width, rect.height) * 1.8;
    const x = e.clientX - rect.left - size / 2;
    const y = e.clientY - rect.top - size / 2;
    const ripple = document.createElement('span');
    ripple.className = 'ripple';
    ripple.style.cssText = `width:${size}px;height:${size}px;left:${x}px;top:${y}px`;
    btn.appendChild(ripple);
    ripple.addEventListener('animationend', () => ripple.remove());
});

/* ============================================================
   BOTTOM NAV MOBILE — injection dynamique
   ============================================================ */
(() => {
    // On génère la bottom nav uniquement sur mobile
    if (window.matchMedia('(min-width: 701px)').matches) return;

    const mainNav = document.getElementById('main-nav');
    if (!mainNav) return;

    // Récupère tous les liens de navigation (pas les boutons/form)
    const navLinks = Array.from(mainNav.querySelectorAll('a:not(.btn-link):not(.btn-link-alt)'));
    if (!navLinks.length) return;

    // Garde les 5 premiers liens publics (ignorer admin/arbitrage si plus de 5 liens)
    // Priorité : liens publics d'abord, puis spéciaux
    const publicLinks = navLinks.filter(a => !a.classList.contains('nav-admin') && !a.classList.contains('nav-arbitrage'));
    const specialLinks = navLinks.filter(a => a.classList.contains('nav-admin') || a.classList.contains('nav-arbitrage'));

    let links = publicLinks.slice(0, 5);
    // Si moins de 5 publics, ajouter les spéciaux
    if (links.length < 5) links = [...links, ...specialLinks].slice(0, 5);

    const nav = document.createElement('nav');
    nav.className = 'bottom-nav';
    nav.setAttribute('aria-label', 'Navigation rapide');

    const inner = document.createElement('div');
    inner.className = 'bottom-nav-inner';

    links.forEach(link => {
        const item = document.createElement('a');
        item.href = link.href;
        item.className = 'bottom-nav-item';

        // Clone l'icône SVG
        const icon = link.querySelector('svg, .icon');
        if (icon) item.appendChild(icon.cloneNode(true));

        // Label court (premier mot du texte)
        const text = link.textContent.trim().split(/\s+/)[0];
        const label = document.createElement('span');
        label.textContent = text;
        item.appendChild(label);

        // Actif si même page
        if (link.hasAttribute('aria-current')) {
            item.setAttribute('aria-current', 'page');
        }

        // Classes spéciales
        if (link.classList.contains('nav-admin')) item.style.color = '#c8a0f5';
        if (link.classList.contains('nav-arbitrage')) item.style.color = 'var(--accent)';

        inner.appendChild(item);
    });

    nav.appendChild(inner);
    document.body.appendChild(nav);
})();

/* ============================================================
   STEPPER — long-press pour incrément rapide
   ============================================================ */
(() => {
    let longPressTimer = null;
    let fastTimer = null;

    const step = (btn, repeat = false) => {
        const input = document.getElementById(btn.dataset.target);
        if (!(input instanceof HTMLInputElement)) return;
        const stepVal = parseFloat(input.step) || 1;
        const min = input.min !== '' ? parseFloat(input.min) : -Infinity;
        const max = input.max !== '' ? parseFloat(input.max) : Infinity;
        const current = parseFloat(input.value) || 0;
        const delta = btn.classList.contains('js-step-up') ? stepVal : -stepVal;
        const next = Math.min(max, Math.max(min, Math.round((current + delta) * 100) / 100));
        if (next !== current) {
            input.value = String(next);
            input.dispatchEvent(new Event('input', { bubbles: true }));
            // Feedback tactile si disponible
            if (repeat && navigator.vibrate) navigator.vibrate(8);
        }
    };

    document.addEventListener('click', (event) => {
        const btn = event.target.closest('.js-step-up, .js-step-down');
        if (btn) step(btn);
    });

    // Long-press : commence à accélérer après 500ms
    document.addEventListener('pointerdown', (event) => {
        const btn = event.target.closest('.js-step-up, .js-step-down');
        if (!btn) return;
        btn.classList.add('is-pressing');
        longPressTimer = setTimeout(() => {
            fastTimer = setInterval(() => step(btn, true), 100);
        }, 450);
    });

    const stopLongPress = (event) => {
        const btn = event && event.target instanceof Element && event.target.closest('.js-step-up, .js-step-down');
        if (btn) btn.classList.remove('is-pressing');
        // Retire pressing de tous
        document.querySelectorAll('.stepper-btn.is-pressing').forEach(b => b.classList.remove('is-pressing'));
        clearTimeout(longPressTimer);
        clearInterval(fastTimer);
        longPressTimer = null;
        fastTimer = null;
    };

    document.addEventListener('pointerup', stopLongPress);
    document.addEventListener('pointercancel', stopLongPress);
    document.addEventListener('pointerleave', stopLongPress);
})();

/* ============================================================
   ANIMATION SCORE — flash quand la valeur change (live.js hook)
   ============================================================ */
(() => {
    // Observe les mutations des éléments .js-score1, .js-score2
    const observer = new MutationObserver((mutations) => {
        for (const mutation of mutations) {
            if (mutation.type === 'characterData' || mutation.type === 'childList') {
                const el = mutation.target.nodeType === Node.TEXT_NODE
                    ? mutation.target.parentElement
                    : mutation.target;
                if (el && el.classList.contains('score-big')) {
                    el.classList.remove('score-updated');
                    void el.offsetWidth; // reflow pour relancer l'animation
                    el.classList.add('score-updated');
                    el.addEventListener('animationend', () => el.classList.remove('score-updated'), { once: true });
                }
            }
        }
    });

    const observeScores = () => {
        document.querySelectorAll('.score-big').forEach(el => {
            observer.observe(el, { childList: true, characterData: true, subtree: true });
        });
    };

    observeScores();

    // Si des cartes sont ajoutées dynamiquement
    const domObserver = new MutationObserver(() => observeScores());
    domObserver.observe(document.body, { childList: true, subtree: true });
})();

/* ============================================================
   BFCache — réactive les boutons
   ============================================================ */
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

/* ============================================================
   PASSWORD TOGGLE (login + register)
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.password-toggle-btn').forEach(btn => {
        const targetId = btn.dataset.target;
        const input = document.getElementById(targetId);
        if (!input) return;
        btn.addEventListener('click', () => {
            const isText = input.type === 'text';
            input.type = isText ? 'password' : 'text';
            btn.setAttribute('aria-label', isText ? 'Afficher le mot de passe' : 'Masquer le mot de passe');
            // Swap icon
            const use = btn.querySelector('use');
            if (use) use.setAttribute('href', isText ? '#icon-eye' : '#icon-eye-off');
            input.focus();
        });
    });
});

/* ============================================================
   FLASH MESSAGES — auto-dismiss après 6s + bouton fermeture
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.flash').forEach(flash => {
        // Bouton fermeture
        const closeBtn = document.createElement('button');
        closeBtn.className = 'flash-close';
        closeBtn.setAttribute('aria-label', 'Fermer');
        closeBtn.innerHTML = '×';
        closeBtn.addEventListener('click', () => dismissFlash(flash));
        flash.appendChild(closeBtn);

        // Auto-dismiss uniquement pour les success et info
        if (flash.classList.contains('flash-success') || flash.classList.contains('flash-info')) {
            const timer = setTimeout(() => dismissFlash(flash), 6000);
            flash.addEventListener('mouseenter', () => clearTimeout(timer));
        }
    });

    function dismissFlash(el) {
        el.classList.add('is-dismissing');
        el.addEventListener('animationend', () => el.remove(), { once: true });
    }
});

/* ============================================================
   CTRL+ENTER — submit rapide sur les formulaires de vote
   ============================================================ */
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        const voteForm = document.querySelector('form:has(.btn-vote-submit)');
        if (voteForm) {
            e.preventDefault();
            voteForm.requestSubmit();
        }
    }
});

/* ============================================================
   VALIDATION CLIENT — champs required avec retour immédiat
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    // Ajoute les messages d'erreur au DOM sur chaque .field
    document.querySelectorAll('form:not([data-no-validate]) .field').forEach(field => {
        const input = field.querySelector('input, select, textarea');
        if (!input || !input.required) return;

        // Crée le message d'erreur
        let errMsg = field.querySelector('.field-error-msg');
        if (!errMsg) {
            errMsg = document.createElement('span');
            errMsg.className = 'field-error-msg';
            errMsg.setAttribute('role', 'alert');
            field.appendChild(errMsg);
        }

        const validate = () => {
            if (!input.value.trim() && input.required) {
                field.classList.add('has-error');
                field.classList.remove('has-success');
                errMsg.textContent = 'Ce champ est requis.';
                return false;
            }
            if (input.type === 'password' && input.minLength > 0 && input.value.length < input.minLength) {
                field.classList.add('has-error');
                field.classList.remove('has-success');
                errMsg.textContent = `Minimum ${input.minLength} caractères.`;
                return false;
            }
            // Confirmation password
            if (input.id === 'password2') {
                const pw1 = document.getElementById('password');
                if (pw1 && input.value && pw1.value !== input.value) {
                    field.classList.add('has-error');
                    field.classList.remove('has-success');
                    errMsg.textContent = 'Les mots de passe ne correspondent pas.';
                    return false;
                }
            }
            field.classList.remove('has-error');
            if (input.value.trim()) field.classList.add('has-success');
            errMsg.textContent = '';
            return true;
        };

        input.addEventListener('blur', validate);
        input.addEventListener('input', () => {
            if (field.classList.contains('has-error')) validate();
        });
    });
});

/* ============================================================
   TABLE SCROLL HINTS — indicateur visuel de scroll horizontal
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.table-wrap').forEach(wrap => {
        const hint = document.createElement('div');
        hint.className = 'table-scroll-hint';
        hint.innerHTML = '<svg class="icon" aria-hidden="true" focusable="false"><use href="#icon-chevron-right"></use></svg> Faire défiler';
        wrap.before(hint);

        const checkOverflow = () => {
            const table = wrap.querySelector('table');
            hint.style.display = table && table.scrollWidth > wrap.clientWidth ? '' : 'none';
        };
        checkOverflow();
        window.addEventListener('resize', checkOverflow, { passive: true });
    });
});

/* ============================================================
   SYSTÈME DE MODALE — remplace window.confirm()
   ============================================================ */
const Modal = (() => {
    let dialog = null;

    const init = () => {
        dialog = document.createElement('dialog');
        dialog.className = 'modal';
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-labelledby', 'modal-title');
        dialog.innerHTML = `
            <div class="modal-inner">
                <div class="modal-icon-wrap" id="modal-icon" aria-hidden="true"></div>
                <p class="modal-title" id="modal-title"></p>
                <div class="modal-actions">
                    <button class="btn btn-secondary modal-cancel-btn" type="button">Annuler</button>
                    <button class="btn btn-danger modal-confirm-btn" type="button">Confirmer</button>
                </div>
            </div>`;
        document.body.appendChild(dialog);

        dialog.querySelector('.modal-cancel-btn').addEventListener('click', () => dialog.close('cancel'));
        dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close('cancel'); });
    };

    const show = ({ message, confirmLabel = 'Confirmer', type = 'danger', icon = '⚠️' }) => new Promise(resolve => {
        if (!dialog) init();

        dialog.querySelector('#modal-icon').textContent = icon;
        dialog.querySelector('#modal-title').textContent = message;

        const confirmBtn = dialog.querySelector('.modal-confirm-btn');
        confirmBtn.textContent = confirmLabel;
        confirmBtn.className = `btn btn-${type} modal-confirm-btn`;

        // Nettoie les anciens listeners
        const fresh = confirmBtn.cloneNode(true);
        confirmBtn.replaceWith(fresh);
        fresh.className = `btn btn-${type} modal-confirm-btn`;
        fresh.textContent = confirmLabel;

        const onClose = () => {
            dialog.removeEventListener('close', onClose);
            resolve(dialog.returnValue === 'confirm');
        };
        dialog.addEventListener('close', onClose);
        fresh.addEventListener('click', () => dialog.close('confirm'), { once: true });

        dialog.showModal();
        fresh.focus();
    });

    return { show };
})();

// Intercepte tous les éléments avec data-confirm
document.addEventListener('click', async (e) => {
    const el = e.target.closest('[data-confirm]');
    if (!el) return;
    e.preventDefault();
    e.stopPropagation();

    const confirmed = await Modal.show({
        message:      el.dataset.confirm,
        confirmLabel: el.dataset.confirmLabel  || 'Confirmer',
        type:         el.dataset.confirmType   || 'danger',
        icon:         el.dataset.confirmIcon   || '⚠️',
    });
    if (!confirmed) return;

    if (el.tagName === 'A') {
        window.location.href = el.href;
    } else if (el.tagName === 'BUTTON') {
        // Retire data-confirm pour éviter la re-interception
        el.removeAttribute('data-confirm');
        if (el.form) el.form.requestSubmit(el);
        else el.click();
    }
}, true); // capture pour intercepter avant d'autres handlers

/* ============================================================
   AUTO-OPEN FORM MODAL — ouvre <dialog id="form-modal"> au chargement
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
    const formModal = document.getElementById('form-modal');
    if (!(formModal instanceof HTMLDialogElement)) return;

    formModal.showModal();

    // Clic sur le backdrop → navigue vers le lien Annuler
    formModal.addEventListener('click', (e) => {
        if (e.target !== formModal) return;
        const cancelLink = formModal.querySelector('a.btn-secondary, a.modal-close-btn');
        if (cancelLink) window.location.href = cancelLink.href;
        else formModal.close();
    });

    // Focus sur le premier champ interactif
    const first = formModal.querySelector('input:not([type=hidden]):not([disabled]), select, textarea');
    if (first) setTimeout(() => first.focus(), 50);
});
