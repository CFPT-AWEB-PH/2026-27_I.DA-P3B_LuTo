/**
 * Snippet BLE pour ecran.php : overlay "TOUCHÉ" en plein écran.
 *
 * INSTRUCTIONS D'INTÉGRATION dans ecran.php :
 *
 * 1) Ajouter ce CSS dans la balise <style> existante :
 *
 *    #ble-overlay {
 *        position: fixed; inset: 0; z-index: 9999;
 *        display: flex; flex-direction: column;
 *        align-items: center; justify-content: center;
 *        background: rgba(0,0,0,.85);
 *        opacity: 0; pointer-events: none;
 *        transition: opacity .25s;
 *    }
 *    #ble-overlay.visible { opacity: 1; }
 *    #ble-overlay .ble-label {
 *        font-family: 'Orbitron', sans-serif;
 *        font-size: clamp(3rem, 12vw, 9rem);
 *        font-weight: 900;
 *        letter-spacing: .05em;
 *        text-shadow: 0 0 40px currentColor;
 *    }
 *    #ble-overlay .ble-who {
 *        font-family: 'Rajdhani', sans-serif;
 *        font-size: clamp(1.5rem, 5vw, 3rem);
 *        font-weight: 600;
 *        margin-top: .5rem;
 *        opacity: .85;
 *    }
 *    #ble-overlay.type-touche  { color: #2be99a; }
 *    #ble-overlay.type-double  { color: #ffb020; }
 *
 * 2) Ajouter ce HTML avant la balise </body> :
 *
 *    <div id="ble-overlay" aria-live="assertive" aria-atomic="true">
 *        <div class="ble-label" id="ble-overlay-label"></div>
 *        <div class="ble-who"   id="ble-overlay-who"></div>
 *    </div>
 *
 * 3) Ajouter dans la balise <script> existante (ou une nouvelle) :
 *    window.BLE_COMBATS_EN_COURS = <?php echo json_encode($enCours); ?>;
 *    Et inclure ce script :
 *    <script src="<?= BASE_URL ?>assets/js/ble_ecran.js"></script>
 */

(function () {
    'use strict';

    const pollMs  = (window.SABRE_CONFIG && window.SABRE_CONFIG.pollMs) || 2000;
    const combats = window.BLE_COMBATS_EN_COURS || [];
    const overlay = document.getElementById('ble-overlay');
    const label   = document.getElementById('ble-overlay-label');
    const who     = document.getElementById('ble-overlay-who');

    if (!overlay || !combats.length) return;

    // { combat_id: lastId }
    const lastIds = {};
    combats.forEach(c => { lastIds[c.id] = 0; });

    function showOverlay(type, joueurPseudo) {
        overlay.className = '';
        overlay.classList.add('visible', 'type-' + type);
        if (type === 'touche') {
            label.textContent = 'TOUCHÉ !';
            who.textContent   = joueurPseudo || '';
        } else {
            label.textContent = 'DOUBLE TOUCHE';
            who.textContent   = 'Annulé';
        }
        clearTimeout(overlay._timer);
        overlay._timer = setTimeout(() => {
            overlay.classList.remove('visible');
            setTimeout(() => { overlay.className = ''; }, 300);
        }, 2500);
    }

    async function poll() {
        for (const c of combats) {
            try {
                const url = window.BASE_URL + 'api/ble_touches.php?combat_id=' +
                            c.id + '&since_id=' + (lastIds[c.id] || 0);
                const data = await fetch(url).then(r => r.json());
                const touches = data.touches || [];
                touches.forEach(t => {
                    lastIds[c.id] = Math.max(lastIds[c.id] || 0, t.id);
                    showOverlay(t.type, t.joueur_pseudo);
                });
            } catch (_) { /* silencieux */ }
        }
    }

    // Démarre le polling
    setInterval(poll, pollMs);
})();
