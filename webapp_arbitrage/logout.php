<?php
require_once __DIR__ . '/config/config.php';

// La déconnexion change l'état de session : on exige un POST + jeton CSRF
// (via le petit formulaire du header) pour qu'un lien tiers du style
// <img src="logout.php"> ne puisse pas déconnecter un utilisateur à son insu.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && checkCsrf($_POST['csrf'] ?? null)) {
    logout();
}

redirect('index.php');
