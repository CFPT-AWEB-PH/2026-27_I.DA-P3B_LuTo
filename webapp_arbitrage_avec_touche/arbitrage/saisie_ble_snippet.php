<?php /* ── Snippet BLE à insérer dans arbitrage/saisie.php ──────────────────────
 *
 * INSTRUCTIONS D'INTÉGRATION :
 *   Insérer ce bloc JUSTE AVANT la ligne :
 *     <script nonce="<?= e(cspNonce()) ?>" src="<?= BASE_URL ?>assets/js/live.js"></script>
 *   (vers la fin de saisie.php, avant le footer)
 *
 * Ce bloc affiche les touches BLE en direct pour le combat en cours.
 * ─────────────────────────────────────────────────────────────────────────── */
?>

<?php /* ── Section Touches BLE ── */ ?>
<?php if ($combat['statut'] === 'en_cours'): ?>
<div class="card" id="ble-touches-card" style="margin-top:var(--space-5);">
    <h3 style="margin-bottom:var(--space-3);">
        <?= icon('bluetooth') ?> Touches électroniques
        <span id="ble-status-dot" title="Connexion BLE"
              style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--text-faint);margin-left:6px;vertical-align:middle;"></span>
    </h3>

    <div id="ble-touches-list" style="display:flex;flex-direction:column;gap:var(--space-2);min-height:60px;">
        <p class="hint" id="ble-empty-msg">En attente des touches…</p>
    </div>
</div>
<?php endif; ?>

<script nonce="<?= e(cspNonce()) ?>">
(function () {
    if (<?= $combat['statut'] === 'en_cours' ? 'true' : 'false' ?>) {
        const combatId = <?= (int)$combatId ?>;
        const j1 = <?= json_encode($combat['joueur1_pseudo']) ?>;
        const j2 = <?= json_encode($combat['joueur2_pseudo']) ?>;
        const pollMs = (window.SABRE_CONFIG && window.SABRE_CONFIG.pollMs) ? window.SABRE_CONFIG.pollMs : 4000;
        const dot  = document.getElementById('ble-status-dot');
        const list = document.getElementById('ble-touches-list');
        const emptyMsg = document.getElementById('ble-empty-msg');
        let lastId = 0;

        function labelType(t, num) {
            if (t === 'double') return '<span style="color:var(--warning)">⚡ DOUBLE TOUCHE</span>';
            const who = num == 1 ? j1 : num == 2 ? j2 : '?';
            return '<span style="color:var(--success)">⚔️ TOUCHÉ : ' + who + '</span>';
        }

        function pollTouches() {
            fetch('<?= BASE_URL ?>api/ble_touches.php?combat_id=' + combatId + '&since_id=' + lastId)
                .then(r => r.json())
                .then(data => {
                    if (dot) dot.style.background = 'var(--success)';
                    const touches = data.touches || [];
                    if (touches.length) {
                        if (emptyMsg) emptyMsg.remove();
                        touches.forEach(t => {
                            lastId = Math.max(lastId, t.id);
                            const div = document.createElement('div');
                            div.className = 'flash flash-info';
                            div.style.cssText = 'display:flex;justify-content:space-between;align-items:center;margin:0;';
                            const ts = new Date(t.timestamp_ms);
                            div.innerHTML = labelType(t.type, t.joueur_num) +
                                '<small style="color:var(--text-faint)">' +
                                ts.toLocaleTimeString('fr-FR') + '</small>';
                            list.appendChild(div);
                        });
                        list.scrollTop = list.scrollHeight;
                    }
                })
                .catch(() => { if (dot) dot.style.background = 'var(--danger)'; });
        }

        // Premier poll immédiat pour charger l'historique
        pollTouches();
        setInterval(pollTouches, pollMs);
    }
})();
</script>
