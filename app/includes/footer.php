</main>
<footer class="footer">
    <div class="footer-inner">
        <a class="footer-brand" href="<?= BASE_URL ?>index.php">
            <?= marqueHtml($pdo) ?>
            <?= e($nomApplication ?? 'Sabre Laser Arbitrage') ?>
        </a>
        <nav class="footer-nav" aria-label="Liens de bas de page">
            <a href="<?= BASE_URL ?>index.php">Direct</a>
            <a href="<?= BASE_URL ?>arbre.php">Arbre</a>
            <a href="<?= BASE_URL ?>scores.php">Résultats</a>
            <a href="<?= BASE_URL ?>classement.php">Classement</a>
            <a href="<?= BASE_URL ?>ecran.php">Écran de salle</a>
            <a href="<?= BASE_URL ?>aide.php">Aide et guide</a>
        </nav>
        <button type="button" class="btn btn-ghost btn-sm footer-install" data-pwa-install hidden><?= icon('download') ?>Installer l'application</button>
        <p class="footer-copy">&copy; <?= date('Y') ?> <?= e($nomApplication ?? 'Sabre Laser Arbitrage') ?></p>
    </div>
</footer>
<?= scriptJs('app.js') ?>
<?= scriptJs('pwa.js') ?>
</body>
</html>
