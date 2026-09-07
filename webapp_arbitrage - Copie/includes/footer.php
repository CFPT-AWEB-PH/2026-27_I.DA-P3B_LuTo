</main>
<footer class="footer">
    <div class="footer-inner">
        <a class="footer-brand" href="<?= BASE_URL ?>index.php">
            <span class="brand-blade" aria-hidden="true"></span>
            <?= e($nomApplication ?? 'Sabre Laser Arbitrage') ?>
        </a>
        <nav class="footer-nav" aria-label="Liens rapides">
            <a href="<?= BASE_URL ?>index.php">Accueil</a>
            <a href="<?= BASE_URL ?>arbre.php">Arbre</a>
            <a href="<?= BASE_URL ?>classement.php">Classement</a>
            <a href="<?= BASE_URL ?>scores.php">Scores</a>
            <a href="<?= BASE_URL ?>ecran.php">Écran</a>
        </nav>
        <p class="footer-copy">&copy; <?= date('Y') ?> <?= e($nomApplication ?? 'Sabre Laser Arbitrage') ?></p>
    </div>
</footer>
<script src="<?= BASE_URL ?>assets/js/app.js"></script>
</body>
</html>
