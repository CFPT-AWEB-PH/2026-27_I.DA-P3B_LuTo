<?php
/**
 * includes/guide.php : contenu du guide d'utilisation (page aide.php).
 *
 * Chaque fiche dit à qui elle s'adresse (clé « acces ») : le guide n'affiche
 * que ce que le compte connecté peut réellement faire. Les permissions sont
 * celles de includes/permissions.php, vérifiées avec can().
 *
 *  acces : 'tous'      → tout le monde, connecté ou non
 *          'invite'    → seulement sans être connecté
 *          'connecte'  → tout compte connecté
 *          [perm, …]   → au moins une de ces permissions
 *
 * Dans les textes : {1}, {2}… = repère numéroté sur la capture d'écran,
 * {base} = BASE_URL. Le HTML des textes est écrit ici (jamais saisi par un visiteur).
 */

/** Sections du guide, dans l'ordre d'affichage : clé => [titre, icône, intro]. */
function guideSections(): array
{
    return [
        'demarrer'  => ['Premiers pas',                'home',     'Se repérer dans l\'application.'],
        'suivre'    => ['Suivre le tournoi',           'live',     'Direct, arbre, résultats, classement et écran de salle.'],
        'compte'    => ['Mon compte',                  'user',     'Connexion, inscription et mot de passe.'],
        'arbitrage' => ['Arbitrer un combat',          'target',   'Donner des points, pause, retard, télécommande.'],
        'tournoi'   => ['Organiser le tournoi',        'trophy',   'Le parcours complet, de l\'inscription au bilan.'],
        'joueurs'   => ['Joueurs',                     'users',    'Fiches, import et export CSV.'],
        'combats'   => ['Combats',                     'bolt',     'Créer, générer, démarrer, terminer, décaler.'],
        'comptes'   => ['Comptes et rôles',            'shield',   'Qui peut se connecter, et ce que chacun peut faire.'],
        'materiel'  => ['Sabres et télécommandes',     'saber',    'Matériel Bluetooth relié au Raspberry.'],
        'wifi'      => ['Wi-Fi de la salle',           'wifi',     'Accès au réseau du Raspberry.'],
        'reglages'  => ['Paramètres et apparence',     'settings', 'Règles du tournoi, grades, champs, thème et logo.'],
        'bilan'     => ['Bilan',                       'printer',  'Imprimer et exporter la journée.'],
        'faq'       => ['Problèmes fréquents',         'help',     'Que faire quand quelque chose ne marche pas.'],
    ];
}

/** Toutes les fiches du guide (filtrées ensuite par guideFichesVisibles()). */
function guideFiches(): array
{
    return [
        // ── Premiers pas ──────────────────────────────────────────────────
        [
            'id' => 'se-reperer', 'section' => 'demarrer', 'acces' => 'tous',
            'titre' => 'Se repérer dans l\'application',
            'resume' => 'La barre du haut, la barre du bas sur téléphone, et où trouver chaque chose.',
            'page' => 'index.php',
            'capture' => 'navigation.webp', 'legende' => 'La barre de navigation (ordinateur).',
            'etapes' => [
                '{1} Les quatre pages publiques : <strong>Direct</strong>, <strong>Arbre</strong>, <strong>Résultats</strong> et <strong>Classement</strong>. Sur téléphone, elles sont dans la barre en bas de l\'écran.',
                '{2} L\'<strong>heure du serveur</strong> : tous les horaires de l\'application s\'y réfèrent, même si l\'heure de votre téléphone est fausse.',
                '{3} Les espaces de travail (<strong>Arbitrage</strong>, <strong>Admin</strong>) n\'apparaissent que si votre compte y a accès.',
                '{4} L\'icône écran ouvre l\'<strong>écran de salle</strong> (pour la TV).',
                '{5} Votre <strong>compte</strong> : changer de mot de passe, se déconnecter, ouvrir ce guide. Sans compte, c\'est le bouton <strong>Connexion</strong>.',
            ],
            'astuces' => ['Le bouton <strong>Aide</strong> <svg class="icon" aria-hidden="true"><use href="#icon-help"></use></svg> en haut de chaque page ouvre directement la fiche du guide qui correspond à la page où vous êtes.'],
            'voir' => ['suivre-direct', 'se-connecter'],
            'mots' => 'menu navigation barre onglet accueil où trouver',
        ],
        [
            'id' => 'installer-app', 'section' => 'demarrer', 'acces' => 'tous',
            'titre' => 'Installer l\'application sur son téléphone',
            'resume' => 'L\'ajouter à l\'écran d\'accueil pour l\'ouvrir en plein écran, comme une vraie app.',
            'etapes' => [
                '<strong>Android / Chrome / Edge</strong> : en bas de n\'importe quelle page, touchez <strong>Installer l\'application</strong>, puis confirmez.',
                '<strong>iPhone / iPad (Safari)</strong> : touchez le bouton <strong>Partager</strong>, puis <strong>Sur l\'écran d\'accueil</strong>.',
                'L\'icône apparaît sur l\'écran d\'accueil : l\'application s\'ouvre en plein écran.',
            ],
            'astuces' => [
                'Le bouton d\'installation n\'apparaît qu\'en <strong>HTTPS</strong> (ou sur l\'ordinateur qui héberge l\'app). Sur le Wi-Fi de la salle, ouvrez <code>https://sabre.lan</code> après avoir installé le certificat proposé sur <code>http://sabre.lan/certificat</code>.',
                'Les scores ne sont jamais gardés en mémoire : l\'application installée affiche toujours les vrais scores en direct.',
            ],
            'voir' => ['wifi-rejoindre'],
            'mots' => 'pwa écran accueil icône raccourci iphone android installer télécharger',
        ],
        [
            'id' => 'wifi-rejoindre', 'section' => 'demarrer', 'acces' => 'tous',
            'titre' => 'Se connecter au Wi-Fi de la salle',
            'resume' => 'Rejoindre le Wi-Fi du Raspberry et ouvrir le site.',
            'etapes' => [
                'Dans les réglages Wi-Fi du téléphone, choisissez le réseau du tournoi (<strong>Sabre-Arbitrage</strong> par défaut).',
                'Une page de connexion s\'ouvre toute seule : entrez l\'<strong>identifiant</strong> et le <strong>mot de passe</strong> donnés par l\'organisation. Si elle ne s\'ouvre pas, tapez <code>10.42.0.1</code> dans le navigateur.',
                'Le site s\'affiche. Ensuite, dans n\'importe quel navigateur : <code>10.42.0.1</code> ou <code>http://sabre.lan</code>.',
            ],
            'astuces' => [
                'Si le téléphone signale « pas d\'accès à internet », répondez <strong>Rester connecté</strong> : ce Wi-Fi est volontairement sans internet.',
                'Sur Android, la petite fenêtre de connexion peut se fermer seule une fois connecté : ouvrez alors simplement le navigateur.',
            ],
            'voir' => ['installer-app'],
            'mots' => 'wifi réseau raspberry portail connexion internet sabre.lan 10.42.0.1',
        ],

        // ── Suivre le tournoi ─────────────────────────────────────────────
        [
            'id' => 'suivre-direct', 'section' => 'suivre', 'acces' => 'tous',
            'titre' => 'Suivre les combats en direct',
            'resume' => 'Scores et chronos en temps réel, sans rien recharger.',
            'page' => 'index.php',
            'capture' => 'direct.webp', 'legende' => 'Page Direct avec deux combats en cours.',
            'etapes' => [
                'Ouvrez <a href="{base}index.php">Direct</a> : la page se met à jour toute seule.',
                '{1} <strong>Combats en cours</strong> : score, chrono et joueur qui mène. Touchez une carte pour ouvrir le combat.',
                '{2} <strong>À venir</strong> : l\'heure de départ et un compte à rebours pour chaque combat.',
                'Plus bas : un aperçu du <strong>classement</strong> et des <strong>derniers résultats</strong>.',
            ],
            'astuces' => ['Rouge = <strong>Aka</strong> (joueur 1, à gauche) · bleu = <strong>Ao</strong> (joueur 2, à droite).', 'Un bandeau orange en haut signale un retard : les horaires affichés en tiennent déjà compte.'],
            'voir' => ['voir-combat', 'ecran-salle'],
            'mots' => 'live direct score chrono en cours temps réel accueil',
        ],
        [
            'id' => 'voir-combat', 'section' => 'suivre', 'acces' => 'tous',
            'titre' => 'Voir le détail d\'un combat',
            'resume' => 'Score, chrono, arbitres, points de chaque arbitre et touches des sabres.',
            'capture' => 'combat.webp', 'legende' => 'La fiche d\'un combat en cours.',
            'etapes' => [
                'Touchez un combat depuis <a href="{base}index.php">Direct</a>, <a href="{base}arbre.php">Arbre</a> ou <a href="{base}scores.php">Résultats</a>.',
                '{1} Le tableau de score : à gauche Aka (rouge), à droite Ao (bleu), avec le chrono.',
                '{2} <strong>Points par arbitre</strong> : ce que chacun des trois arbitres a donné. Le total retenu est en gras.',
                '{3} <strong>Touches</strong> : les touches enregistrées par les sabres connectés.',
            ],
            'astuces' => ['Avant le début, la fiche affiche l\'heure de départ et les arbitres à la place du score.'],
            'voir' => ['score-officiel', 'classement'],
            'mots' => 'détail combat fiche arbitres touches sabre',
        ],
        [
            'id' => 'arbre', 'section' => 'suivre', 'acces' => 'tous',
            'titre' => 'Lire l\'arbre du tournoi',
            'resume' => 'Le tableau à élimination directe, tour par tour jusqu\'à la finale.',
            'page' => 'arbre.php',
            'capture' => 'arbre.webp', 'legende' => 'L\'arbre : chaque colonne est un tour.',
            'etapes' => [
                'Ouvrez <a href="{base}arbre.php">Arbre</a>.',
                '{1} Chaque colonne est un tour (quarts, demi-finales, finale). Le vainqueur d\'un combat avance dans la colonne suivante.',
                '{2} Touchez un combat pour voir son score et ses touches.',
                '« À déterminer » : le combat existe dans l\'arbre mais ses joueurs ne sont pas encore connus.',
            ],
            'astuces' => ['Le bouton <strong>Imprimer</strong> sort l\'arbre sur papier ou en PDF.'],
            'voir' => ['combats-generer'],
            'mots' => 'arbre tableau bracket élimination finale demi quart tour',
        ],
        [
            'id' => 'resultats', 'section' => 'suivre', 'acces' => 'tous',
            'titre' => 'Consulter les résultats',
            'resume' => 'Tous les combats terminés, du plus récent au plus ancien.',
            'page' => 'scores.php',
            'capture' => 'resultats.webp', 'legende' => 'La liste des combats terminés.',
            'etapes' => [
                'Ouvrez <a href="{base}scores.php">Résultats</a>.',
                '{1} Chaque ligne montre le vainqueur mis en avant, le score final et les points de victoire gagnés.',
                'Touchez une ligne pour ouvrir le détail du combat.',
            ],
            'voir' => ['classement', 'voir-combat'],
            'mots' => 'résultats scores terminés historique vainqueur',
        ],
        [
            'id' => 'classement', 'section' => 'suivre', 'acces' => 'tous',
            'titre' => 'Comprendre le classement et les points de victoire',
            'resume' => 'Comment les joueurs sont classés, et ce que veut dire « PV ».',
            'page' => 'classement.php',
            'capture' => 'classement.webp', 'legende' => 'Le classement avec le podium.',
            'etapes' => [
                'Ouvrez <a href="{base}classement.php">Classement</a> : il se met à jour à la fin de chaque combat.',
                '{1} Les joueurs sont triés par <strong>points de victoire (PV)</strong>, puis par nombre de victoires.',
                'Un vainqueur gagne des PV selon l\'écart final : <strong>1 à 5 points d\'écart = 1 PV</strong>, <strong>6 à 15 = 2 PV</strong>, <strong>plus de 15 = 3 PV</strong>.',
                'Deux joueurs au même rang sont <strong>ex æquo</strong>. Les médailles n\'apparaissent qu\'à partir de 1 PV.',
            ],
            'voir' => ['resultats', 'score-officiel'],
            'mots' => 'classement pv points de victoire rang podium médaille ex aequo',
        ],
        [
            'id' => 'ecran-salle', 'section' => 'suivre', 'acces' => 'tous',
            'titre' => 'Afficher l\'écran de salle sur une TV',
            'resume' => 'Un affichage plein écran qui fait défiler le direct, l\'arbre et le classement.',
            'page' => 'ecran.php',
            'capture' => 'ecran.webp', 'legende' => 'L\'écran de salle (affichage TV).',
            'etapes' => [
                'Sur l\'ordinateur relié à la TV, ouvrez l\'<a href="{base}ecran.php">écran de salle</a> (icône écran en haut de chaque page).',
                '{1} Les onglets défilent tout seuls (le direct reste affiché deux fois plus longtemps).',
                '{2} Passez en <strong>plein écran</strong> : bouton en haut à droite, touche <kbd>F</kbd> ou double-clic.',
                'Au clavier : <kbd>←</kbd> <kbd>→</kbd> changer d\'écran, <kbd>Espace</kbd> mettre la rotation en pause.',
            ],
            'astuces' => ['L\'affichage s\'adapte à la taille de la TV : jamais de barre de défilement.'],
            'voir' => ['parametres'],
            'mots' => 'tv télévision écran salle projecteur plein écran affichage rotation',
        ],

        // ── Mon compte ────────────────────────────────────────────────────
        [
            'id' => 'se-connecter', 'section' => 'compte', 'acces' => 'invite',
            'titre' => 'Se connecter',
            'resume' => 'Pour les arbitres et l\'organisation. Aucun compte n\'est nécessaire pour suivre les combats.',
            'page' => 'login.php',
            'capture' => 'connexion.webp', 'legende' => 'La page de connexion.',
            'etapes' => [
                'Touchez <a href="{base}login.php">Connexion</a> en haut à droite.',
                '{1} Entrez votre <strong>nom d\'utilisateur</strong> et votre <strong>mot de passe</strong> (l\'œil affiche le mot de passe tapé).',
                '{2} Touchez <strong>Se connecter</strong>. À la première connexion, l\'application vous demande de choisir votre propre mot de passe.',
            ],
            'astuces' => ['Après 5 essais ratés, le compte est bloqué 15 minutes par sécurité.', 'Mot de passe oublié ? Demandez à un responsable de l\'organisation de le réinitialiser.'],
            'voir' => ['mot-de-passe', 'creer-compte'],
            'mots' => 'connexion login se connecter identifiant mot de passe oublié bloqué',
        ],
        [
            'id' => 'creer-compte', 'section' => 'compte', 'acces' => 'invite', 'si' => 'inscriptions',
            'titre' => 'Créer un compte joueur',
            'resume' => 'S\'inscrire soi-même comme joueur, quand les inscriptions sont ouvertes.',
            'page' => 'register.php',
            'etapes' => [
                'Ouvrez <a href="{base}register.php">Créer un compte joueur</a> (lien sur la page de connexion).',
                'Choisissez un nom d\'utilisateur, éventuellement votre nom de combat, puis un mot de passe de 8 caractères minimum.',
                'Touchez <strong>Créer mon compte</strong> : votre fiche joueur est créée automatiquement.',
            ],
            'astuces' => ['Les comptes d\'arbitre sont créés par l\'organisation, pas par cette page.'],
            'voir' => ['se-connecter'],
            'mots' => 'inscription inscrire créer compte joueur register',
        ],
        [
            'id' => 'mot-de-passe', 'section' => 'compte', 'acces' => 'connecte',
            'titre' => 'Changer mon mot de passe',
            'resume' => 'Choisir un nouveau mot de passe ; vos autres appareils sont déconnectés.',
            'page' => 'compte.php',
            'capture' => 'compte.webp', 'legende' => 'Mon compte : changement de mot de passe.',
            'etapes' => [
                'Touchez votre initiale en haut à droite, puis <a href="{base}compte.php">Changer de mot de passe</a>.',
                '{1} Tapez votre mot de passe <strong>actuel</strong>.',
                '{2} Tapez le <strong>nouveau</strong> deux fois : 8 caractères minimum, sans votre nom d\'utilisateur.',
                '{3} Touchez <strong>Enregistrer le nouveau mot de passe</strong>. Vos autres sessions (autres téléphones, ordinateurs) sont déconnectées.',
            ],
            'voir' => ['se-deconnecter'],
            'mots' => 'mot de passe changer modifier sécurité compte',
        ],
        [
            'id' => 'se-deconnecter', 'section' => 'compte', 'acces' => 'connecte',
            'titre' => 'Se déconnecter',
            'resume' => 'À faire sur un appareil partagé (tablette d\'arbitre, ordinateur de la salle).',
            'capture' => 'menu-compte.webp', 'legende' => 'Le menu du compte.',
            'etapes' => [
                '{1} Touchez votre initiale en haut à droite.',
                '{2} Touchez <strong>Se déconnecter</strong>.',
            ],
            'astuces' => ['Sans activité pendant 8 heures, la session se ferme toute seule.'],
            'mots' => 'déconnexion logout quitter sortir',
        ],

        // ── Arbitrage ─────────────────────────────────────────────────────
        [
            'id' => 'arbitrage-mes-combats', 'section' => 'arbitrage', 'acces' => ['arbitrage.acces', 'arbitrage.tous_combats'],
            'titre' => 'Trouver les combats que j\'arbitre',
            'resume' => 'L\'espace arbitrage liste vos combats en cours, à venir et terminés.',
            'page' => 'arbitrage/index.php',
            'capture' => 'arbitrage.webp', 'legende' => 'L\'espace arbitrage d\'un arbitre.',
            'etapes' => [
                'Ouvrez <a href="{base}arbitrage/index.php">Arbitrage</a> (en haut, ou 5e onglet en bas sur téléphone).',
                '{1} <strong>À arbitrer maintenant</strong> : vos combats en cours, avec votre poste (central, coin 1 ou coin 2). Touchez-en un pour ouvrir le pupitre.',
                '{2} <strong>À venir</strong> : vos prochains combats et leur heure.',
                'La page se met à jour toute seule dès qu\'un combat vous est attribué.',
            ],
            'voir' => ['arbitrage-points', 'score-officiel'],
            'mots' => 'arbitre arbitrage mes combats assigné poste central coin',
        ],
        [
            'id' => 'arbitrage-points', 'section' => 'arbitrage', 'acces' => ['arbitrage.voter'],
            'titre' => 'Donner des points pendant un combat',
            'resume' => 'Le pupitre : +1, +2, +3 pour chaque joueur, annulation et raccourcis clavier.',
            'capture' => 'pupitre.webp', 'legende' => 'Le pupitre d\'arbitrage pendant un combat.',
            'etapes' => [
                'Depuis <a href="{base}arbitrage/index.php">Arbitrage</a>, touchez le combat en cours.',
                '{1} Le grand chiffre = <strong>vos</strong> points pour ce joueur. Le score <strong>officiel</strong> est juste dessous.',
                '{2} Touchez <strong>+1</strong>, <strong>+2</strong> ou <strong>+3</strong> du côté du joueur qui marque (rouge = Aka, bleu = Ao).',
                '{3} Erreur ? <strong>Annuler mon dernier point</strong> retire votre dernier point. La liste à côté montre vos derniers points.',
                'Au clavier : <kbd>1</kbd> <kbd>2</kbd> <kbd>3</kbd> pour Aka, <kbd>7</kbd> <kbd>8</kbd> <kbd>9</kbd> pour Ao, <kbd>⌫</kbd> pour annuler.',
            ],
            'astuces' => [
                'Les boutons n\'apparaissent qu\'au coup d\'envoi, et sont bloqués pendant une pause.',
                'Si vous n\'êtes pas l\'un des 3 arbitres du combat, le pupitre est en lecture seule.',
            ],
            'voir' => ['score-officiel', 'arbitrage-pause', 'telecommande'],
            'mots' => 'points noter voter score pupitre bouton annuler raccourci clavier',
        ],
        [
            'id' => 'score-officiel', 'section' => 'arbitrage', 'acces' => 'tous',
            'titre' => 'Comment est calculé le score officiel',
            'resume' => 'Trois arbitres comptent chacun de leur côté ; on retient le total le plus bas.',
            'etapes' => [
                'Chaque combat a <strong>3 arbitres</strong> : 1 arbitre central et 2 arbitres de coin.',
                'Chacun donne ses points de son côté, pour chaque joueur.',
                'Le <strong>score officiel</strong> d\'un joueur est le <strong>total le plus bas</strong> des trois arbitres. Un point ne compte donc que si les trois arbitres l\'ont donné.',
                'C\'est pourquoi le score officiel peut ne pas bouger quand un arbitre donne un point : il attend les deux autres.',
                'À la fin du chrono, le combat se termine tout seul et le vainqueur gagne des points de victoire selon l\'écart.',
            ],
            'voir' => ['classement', 'voir-combat'],
            'mots' => 'calcul score officiel moyenne minimum trois arbitres règle',
        ],
        [
            'id' => 'arbitrage-pause', 'section' => 'arbitrage', 'acces' => ['arbitrage.voter', 'combats.demarrer', 'combats.terminer'],
            'titre' => 'Mettre un combat en pause et le reprendre',
            'resume' => 'Arrêter le chrono (blessure, matériel…) puis repartir.',
            'capture' => 'pupitre-pause.webp', 'legende' => 'Le bouton Pause du pupitre.',
            'etapes' => [
                'Sur le pupitre du combat en cours, touchez {1} <strong>Pause</strong>.',
                'Le chrono s\'arrête et les points sont bloqués pour les trois arbitres. Un bandeau orange l\'indique.',
                'Touchez <strong>Reprendre le combat</strong> : le chrono repart là où il s\'était arrêté.',
            ],
            'astuces' => ['Un arbitre ne peut mettre en pause que les combats qu\'il arbitre.'],
            'voir' => ['arbitrage-points'],
            'mots' => 'pause arrêter chrono stop reprendre blessure',
        ],
        [
            'id' => 'arbitrage-retard', 'section' => 'arbitrage', 'acces' => ['arbitrage.retard'],
            'titre' => 'Signaler un retard depuis le pupitre',
            'resume' => 'Décaler tous les combats à venir et prévenir les spectateurs.',
            'etapes' => [
                'En bas du pupitre, ouvrez le volet <strong>Signaler un retard</strong> et tapez un nombre de minutes (par exemple <code>10</code>).',
                'Touchez <strong>Décaler les combats</strong> : tous les combats à venir sont repoussés d\'autant.',
                'Un bandeau « Retard de X min » apparaît sur toutes les pages pour les spectateurs.',
            ],
            'astuces' => ['Un nombre négatif (par exemple <code>-5</code>) rattrape du retard.'],
            'voir' => ['combats-retard'],
            'mots' => 'retard décaler repousser minutes planning bandeau',
        ],
        [
            'id' => 'telecommande', 'section' => 'arbitrage', 'acces' => ['arbitrage.voter', 'sabres.modifier'],
            'titre' => 'Arbitrer avec une télécommande',
            'resume' => 'Donner des points sans téléphone, avec la télécommande Bluetooth.',
            'etapes' => [
                'L\'organisation associe la télécommande à votre compte (Admin › Sabres › Télécommandes).',
                'Bouton <strong>rouge</strong> = +1 Aka · bouton <strong>bleu</strong> = +1 Ao · <strong>les deux ensemble</strong> = annule votre dernier point.',
                'Les points comptent pour vous, dans le combat en cours où vous arbitrez, exactement comme sur le pupitre.',
                'L\'écran de la télécommande affiche vos points et le chrono, <strong>STOP</strong> en pause et le vainqueur à la fin.',
            ],
            'astuces' => ['« SANS ARBITRE » : la télécommande n\'est associée à personne. « PI ? » : le Raspberry ne répond plus (vérifiez qu\'il est allumé et à portée).'],
            'voir' => ['telecommandes-associer', 'arbitrage-points'],
            'mots' => 'télécommande bouton bluetooth tel boîtier manette',
        ],
        [
            'id' => 'arbitrage-superviser', 'section' => 'arbitrage', 'acces' => ['arbitrage.tous_combats'],
            'titre' => 'Superviser tous les combats',
            'resume' => 'Voir et ouvrir n\'importe quel combat, même sans y être assigné.',
            'page' => 'arbitrage/index.php',
            'etapes' => [
                'Ouvrez <a href="{base}arbitrage/index.php">Arbitrage</a> : la page affiche <strong>tous les combats du tournoi</strong>.',
                'Ouvrez un combat pour suivre les points de chaque arbitre en direct.',
                'Le pupitre est en lecture seule sur les combats où vous n\'êtes pas l\'un des 3 arbitres.',
            ],
            'voir' => ['arbitrage-mes-combats'],
            'mots' => 'superviser tous les combats chef arbitre surveiller',
        ],

        // ── Organiser le tournoi (parcours) ───────────────────────────────
        [
            'id' => 'parcours-tournoi', 'section' => 'tournoi', 'acces' => ['joueurs.creer', 'combats.generer', 'combats.creer', 'users.creer'],
            'titre' => 'Préparer et lancer un tournoi, étape par étape',
            'resume' => 'La check-list complète de l\'organisateur, dans l\'ordre.',
            'parcours' => [
                ['parametres',         'Régler le nom du tournoi, la durée des combats et les règles d\'appariement.'],
                ['grades',             'Créer les grades (niveaux) des joueurs.'],
                ['comptes-creer',      'Créer les comptes des arbitres.'],
                ['joueurs-importer',   'Importer la liste des joueurs (ou les ajouter un par un).'],
                ['joueurs-ajouter',    'Ajouter les retardataires à la main.'],
                ['wifi-acces',         'Créer les accès Wi-Fi pour les arbitres et le public.'],
                ['sabres-ajouter',     'Enregistrer les sabres et les associer aux joueurs.'],
                ['combats-generer',    'Générer l\'arbre et le planning.'],
                ['combats-demarrer',   'Démarrer les combats (ou laisser le démarrage automatique).'],
                ['ecran-salle',        'Afficher l\'écran de salle sur la TV.'],
                ['combats-retard',     'Gérer le retard en cours de journée.'],
                ['bilan',              'Imprimer le bilan de la journée.'],
            ],
            'mots' => 'organiser préparer tournoi checklist étapes début commencer parcours',
        ],
        [
            'id' => 'tableau-de-bord', 'section' => 'tournoi', 'acces' => ['admin.dashboard'],
            'titre' => 'Lire le tableau de bord',
            'resume' => 'Les chiffres du jour, les alertes et le programme.',
            'page' => 'admin/index.php',
            'capture' => 'admin-dashboard.webp', 'legende' => 'Administration › Vue d\'ensemble.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/index.php">Admin</a>.',
                '{1} Les onglets donnent accès à chaque section de l\'administration (seulement celles autorisées pour votre rôle).',
                '{2} Les compteurs (en cours, à venir, terminés, joueurs, arbitres) : touchez-en un pour ouvrir la liste filtrée.',
                '{3} Les raccourcis <strong>Ajouter un joueur</strong> et <strong>Nouveau combat</strong>.',
                'Un bandeau orange prévient si des combats à venir n\'ont pas leurs 3 arbitres : touchez <strong>Affecter les arbitres</strong>.',
            ],
            'voir' => ['parcours-tournoi', 'combats-modifier'],
            'mots' => 'tableau de bord admin statistiques vue ensemble accueil admin',
        ],

        // ── Joueurs ───────────────────────────────────────────────────────
        [
            'id' => 'joueurs-ajouter', 'section' => 'joueurs', 'acces' => ['joueurs.creer'],
            'titre' => 'Ajouter un joueur',
            'resume' => 'Créer une fiche joueur : nom de combat, grade, poids, équipe…',
            'page' => 'admin/joueurs.php',
            'capture' => 'joueur-form.webp', 'legende' => 'Le formulaire « Nouveau joueur ».',
            'etapes' => [
                'Ouvrez <a href="{base}admin/joueurs.php">Admin › Joueurs</a> puis <a href="{base}admin/joueurs.php?action=new">Nouveau joueur</a>.',
                '{1} Le <strong>nom de combat</strong> est le seul champ obligatoire : c\'est le nom affiché partout.',
                '{2} Sexe, date de naissance, <strong>poids</strong> et <strong>grade</strong> servent à former des paires équilibrées lors de la génération automatique.',
                '{3} Touchez <strong>Ajouter le joueur</strong>.',
            ],
            'voir' => ['joueurs-importer', 'champs-joueurs', 'grades'],
            'mots' => 'joueur ajouter créer inscrire combattant fiche nouveau',
        ],
        [
            'id' => 'joueurs-modifier', 'section' => 'joueurs', 'acces' => ['joueurs.modifier', 'joueurs.supprimer'],
            'titre' => 'Modifier ou supprimer un joueur',
            'resume' => 'Corriger une fiche, ou retirer un joueur.',
            'page' => 'admin/joueurs.php',
            'capture' => 'joueurs.webp', 'legende' => 'La liste des joueurs.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/joueurs.php">Admin › Joueurs</a>.',
                '{1} Tapez dans la <strong>recherche</strong> pour retrouver un joueur (raccourci <kbd>/</kbd>).',
                '{2} Touchez le nom du joueur pour modifier sa fiche, ou le menu <strong>⋯</strong> pour <strong>Modifier</strong> / <strong>Supprimer</strong>.',
            ],
            'astuces' => ['Un joueur qui a déjà des combats ne peut pas être supprimé (son historique serait perdu).'],
            'voir' => ['joueurs-ajouter'],
            'mots' => 'joueur modifier éditer corriger supprimer effacer rechercher',
        ],
        [
            'id' => 'joueurs-importer', 'section' => 'joueurs', 'acces' => ['joueurs.importer'],
            'titre' => 'Importer la liste des joueurs (CSV)',
            'resume' => 'Inscrire tous les joueurs d\'un coup depuis Excel ou Google Sheets.',
            'page' => 'admin/joueurs.php?action=import',
            'capture' => 'joueurs-import.webp', 'legende' => 'La fenêtre d\'import CSV.',
            'etapes' => [
                'Dans Excel, préparez une feuille avec en 1re ligne les colonnes : <code>pseudo</code>, <code>nom</code>, <code>prenom</code>, <code>sexe</code>, <code>date_naissance</code>, <code>poids</code>, <code>grade</code>, <code>equipe</code> (plus vos champs personnalisés).',
                'Enregistrez-la au format <strong>CSV</strong> (Fichier › Enregistrer sous › CSV).',
                'Ouvrez <a href="{base}admin/joueurs.php?action=import">Admin › Joueurs › Importer un CSV</a>, {1} choisissez le fichier, puis {2} <strong>Importer les joueurs</strong>.',
            ],
            'astuces' => [
                'Le <strong>pseudo sert de clé</strong> : un joueur déjà présent est mis à jour au lieu d\'être créé en double.',
                'Astuce : faites d\'abord un <strong>export CSV</strong> pour récupérer un fichier modèle avec les bonnes colonnes.',
                'Fichier de 1 Mo maximum.',
            ],
            'voir' => ['joueurs-exporter', 'champs-joueurs'],
            'mots' => 'import importer csv excel liste fichier sheets tableur',
        ],
        [
            'id' => 'joueurs-exporter', 'section' => 'joueurs', 'acces' => ['joueurs.exporter'],
            'titre' => 'Exporter les joueurs (CSV)',
            'resume' => 'Télécharger la liste des joueurs pour Excel.',
            'page' => 'admin/joueurs.php',
            'etapes' => [
                'Ouvrez <a href="{base}admin/joueurs.php">Admin › Joueurs</a>.',
                'Touchez <strong>Exporter</strong> : le fichier se télécharge, ouvrable dans Excel.',
            ],
            'voir' => ['joueurs-importer', 'bilan'],
            'mots' => 'export exporter csv excel télécharger sauvegarde',
        ],

        // ── Combats ───────────────────────────────────────────────────────
        [
            'id' => 'combats-generer', 'section' => 'combats', 'acces' => ['combats.generer'],
            'titre' => 'Générer le tournoi automatiquement',
            'resume' => 'Former les paires, placer l\'arbre, répartir les arbitres et créer le planning en une fois.',
            'page' => 'admin/combats.php?action=generer',
            'capture' => 'generer.webp', 'legende' => 'La fenêtre « Générer le tournoi ».',
            'etapes' => [
                'Ouvrez <a href="{base}admin/combats.php">Admin › Combats</a> puis <a href="{base}admin/combats.php?action=generer">Générer le tournoi</a>.',
                '{1} Cochez les <strong>joueurs</strong> qui participent (recherche, <strong>Tout sélectionner</strong>, <strong>Inverser</strong>).',
                '{2} Cochez les <strong>arbitres présents</strong> : ils sont répartis par 3 sur les combats.',
                '{3} <strong>Planning</strong> : heure du 1er combat, minutes entre deux combats et durée. L\'aperçu calcule l\'heure de fin.',
                '{4} Touchez <strong>Générer les combats</strong>.',
            ],
            'astuces' => [
                'Les paires respectent les règles d\'appariement (poids, âge, grade, sexe) de <a href="{base}admin/parametres.php">Paramètres</a>.',
                'Videz « 1er combat à » pour créer les combats sans horaire (démarrage à la main).',
                '« Remplacer les combats à venir déjà placés » refait l\'arbre de zéro (les combats terminés sont gardés).',
            ],
            'voir' => ['parametres', 'arbre', 'combats-demarrer'],
            'mots' => 'générer automatique arbre tournoi tirage paires appariement planning',
        ],
        [
            'id' => 'combats-creer', 'section' => 'combats', 'acces' => ['combats.creer'],
            'titre' => 'Créer un combat à la main',
            'resume' => 'Un combat isolé : deux joueurs, une heure, trois arbitres.',
            'page' => 'admin/combats.php?action=new',
            'capture' => 'combat-form.webp', 'legende' => 'Le formulaire « Nouveau combat ».',
            'etapes' => [
                'Ouvrez <a href="{base}admin/combats.php?action=new">Admin › Combats › Nouveau combat</a>.',
                '{1} Choisissez le joueur <strong>Aka</strong> (rouge) et le joueur <strong>Ao</strong> (bleu).',
                '{2} Heure de début et durée. Sans heure, le combat se démarre à la main.',
                '{3} Choisissez les 3 arbitres : <strong>central</strong>, <strong>coin 1</strong>, <strong>coin 2</strong>.',
                '{4} Touchez <strong>Créer le combat</strong>.',
            ],
            'astuces' => ['« Tour » et « Position » placent le combat dans l\'arbre (facultatif).'],
            'voir' => ['combats-modifier', 'combats-generer'],
            'mots' => 'créer combat nouveau manuel ajouter match',
        ],
        [
            'id' => 'combats-modifier', 'section' => 'combats', 'acces' => ['combats.modifier'],
            'titre' => 'Modifier un combat et affecter les arbitres',
            'resume' => 'Changer les joueurs, l\'heure ou les arbitres d\'un combat à venir.',
            'page' => 'admin/combats.php',
            'capture' => 'combats.webp', 'legende' => 'La liste des combats.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/combats.php">Admin › Combats</a>.',
                '{1} Un combat sans ses 3 arbitres affiche <strong>Affecter</strong> : touchez-le pour compléter les arbitres.',
                '{2} Pour toute autre modification : menu <strong>⋯</strong> › <strong>Modifier</strong>.',
                'Changez ce qu\'il faut puis <strong>Enregistrer les modifications</strong>.',
            ],
            'astuces' => ['Un combat déjà démarré ne peut plus être modifié.'],
            'voir' => ['combats-demarrer'],
            'mots' => 'modifier combat arbitres affecter changer heure joueur',
        ],
        [
            'id' => 'combats-demarrer', 'section' => 'combats', 'acces' => ['combats.demarrer'],
            'titre' => 'Démarrer les combats',
            'resume' => 'À la main, ou automatiquement à l\'heure prévue.',
            'page' => 'admin/combats.php',
            'capture' => 'combats-demarrer.webp', 'legende' => 'Démarrage manuel et automatique.',
            'etapes' => [
                '{1} <strong>Démarrage automatique</strong> : activé, chaque combat démarre tout seul à son heure s\'il a ses 3 arbitres.',
                '{2} Démarrage à la main : touchez <strong>Démarrer</strong> sur la ligne du combat.',
                'Plusieurs à la fois : cochez les combats puis <strong>Démarrer</strong> dans la barre qui apparaît.',
            ],
            'astuces' => ['Il faut 3 arbitres pour démarrer : sinon le bouton est grisé et « Affecter » apparaît.'],
            'voir' => ['combats-terminer', 'arbitrage-pause'],
            'mots' => 'démarrer lancer commencer start automatique heure',
        ],
        [
            'id' => 'combats-terminer', 'section' => 'combats', 'acces' => ['combats.terminer', 'combats.supprimer'],
            'titre' => 'Terminer ou supprimer un combat',
            'resume' => 'Arrêter un combat avant la fin du chrono, ou le retirer.',
            'page' => 'admin/combats.php?filtre=en_cours',
            'etapes' => [
                'Un combat se termine <strong>tout seul</strong> à la fin de son chrono.',
                'Pour l\'arrêter avant : <a href="{base}admin/combats.php?filtre=en_cours">Admin › Combats › En cours</a>, puis <strong>Terminer</strong> sur sa ligne.',
                'Pour supprimer : menu <strong>⋯</strong> › <strong>Supprimer</strong>, ou cochez plusieurs combats puis <strong>Supprimer</strong>. Une confirmation est demandée.',
            ],
            'astuces' => ['Une suppression est définitive : le combat disparaît aussi des résultats et du classement.'],
            'voir' => ['combats-demarrer'],
            'mots' => 'terminer arrêter finir supprimer effacer combat lot plusieurs',
        ],
        [
            'id' => 'combats-retard', 'section' => 'combats', 'acces' => ['combats.retard'],
            'titre' => 'Décaler le planning (retard global)',
            'resume' => 'Repousser ou avancer d\'un coup tous les combats à venir.',
            'page' => 'admin/combats.php',
            'capture' => 'combats-retard.webp', 'legende' => 'Le réglage du retard global.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/combats.php">Admin › Combats</a>.',
                '{1} Tapez le nombre de minutes : positif pour repousser, négatif pour rattraper.',
                'Touchez <strong>Décaler les combats</strong>. Un bandeau prévient les spectateurs sur toutes les pages.',
            ],
            'voir' => ['arbitrage-retard'],
            'mots' => 'retard décaler repousser avancer planning horaire minutes',
        ],

        // ── Comptes et rôles ──────────────────────────────────────────────
        [
            'id' => 'comptes-creer', 'section' => 'comptes', 'acces' => ['users.creer'],
            'titre' => 'Créer un compte (arbitre, organisateur…)',
            'resume' => 'Donner un accès à l\'application à quelqu\'un.',
            'page' => 'admin/users.php?action=new',
            'capture' => 'compte-form.webp', 'legende' => 'Le formulaire « Nouvel utilisateur ».',
            'etapes' => [
                'Ouvrez <a href="{base}admin/users.php">Admin › Comptes</a> puis <a href="{base}admin/users.php?action=new">Nouveau compte</a>.',
                '{1} Choisissez le <strong>nom d\'utilisateur</strong>. Laissez le mot de passe vide pour en générer un automatiquement.',
                '{2} Choisissez le <strong>rôle</strong> (par exemple <em>Arbitre</em>).',
                '{3} Touchez <strong>Créer le compte</strong>. Le mot de passe généré s\'affiche une seule fois : notez-le et transmettez-le.',
            ],
            'astuces' => ['À sa première connexion, la personne doit choisir son propre mot de passe.'],
            'voir' => ['comptes-modifier', 'roles'],
            'mots' => 'créer compte utilisateur arbitre accès login nouveau',
        ],
        [
            'id' => 'comptes-modifier', 'section' => 'comptes', 'acces' => ['users.modifier', 'users.mot_de_passe', 'users.changer_role', 'users.supprimer'],
            'titre' => 'Modifier un compte, réinitialiser un mot de passe',
            'resume' => 'Changer le rôle, désactiver, réinitialiser le mot de passe ou supprimer.',
            'page' => 'admin/users.php',
            'capture' => 'comptes.webp', 'legende' => 'La liste des comptes.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/users.php">Admin › Comptes</a>.',
                '{1} Filtrez par rôle avec les onglets en haut de la liste.',
                '{2} Menu <strong>⋯</strong> › <strong>Modifier</strong> sur la ligne du compte.',
                'Dans la fiche : <strong>nouveau mot de passe</strong> (vide = inchangé), <strong>rôle</strong>, interrupteur <strong>actif</strong> (désactivé = la personne ne peut plus se connecter).',
            ],
            'astuces' => [
                'Réinitialiser un mot de passe déconnecte la personne de tous ses appareils.',
                'Un compte assigné comme arbitre à un combat ne peut pas être supprimé : désactivez-le plutôt.',
            ],
            'voir' => ['roles'],
            'mots' => 'modifier compte rôle désactiver mot de passe oublié réinitialiser supprimer',
        ],
        [
            'id' => 'roles', 'section' => 'comptes', 'acces' => ['roles.voir', 'roles.gerer'],
            'titre' => 'Rôles et permissions',
            'resume' => 'Décider précisément ce que chaque type de compte peut voir et faire.',
            'page' => 'admin/roles.php',
            'capture' => 'roles.webp', 'legende' => 'Les permissions d\'un rôle.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/roles.php">Admin › Rôles</a> : la liste des rôles et le tableau « Qui peut faire quoi ».',
                '<strong>Nouveau rôle</strong> : donnez un nom (ex. <em>Arbitre chef</em>) et partez des permissions d\'un rôle existant.',
                '{1} Dans un rôle, cherchez une permission, cochez ou décochez-la.',
                '{2} Touchez <strong>Enregistrer le rôle</strong> (ou <kbd>Ctrl</kbd>+<kbd>S</kbd>). Le changement s\'applique immédiatement.',
            ],
            'astuces' => [
                'Le rôle <strong>Administrateur</strong> a toujours tout : il ne peut être ni restreint ni supprimé.',
                'Vous ne pouvez pas accorder une permission que votre propre rôle n\'a pas (elle est grisée).',
                'Ce guide s\'adapte aux permissions : un compte ne voit que les fiches de ce qu\'il peut faire.',
            ],
            'voir' => ['comptes-creer'],
            'mots' => 'rôle permission droit accès autoriser interdire',
        ],

        // ── Sabres et télécommandes ───────────────────────────────────────
        [
            'id' => 'sabres-ajouter', 'section' => 'materiel', 'acces' => ['sabres.ajouter'],
            'titre' => 'Enregistrer un sabre',
            'resume' => 'Ajouter un sabre Bluetooth et l\'associer à un joueur.',
            'page' => 'admin/sabres.php',
            'capture' => 'sabres.webp', 'legende' => 'Admin › Sabres.',
            'etapes' => [
                'Allumez le sabre près du Raspberry, puis ouvrez <a href="{base}admin/sabres.php">Admin › Sabres</a>.',
                '{1} Le sabre apparaît dans <strong>Appareils détectés</strong> : touchez <strong>Ajouter</strong> pour pré-remplir le formulaire.',
                'Sinon, dans <strong>Ajouter un sabre</strong> (plus bas), tapez son <strong>adresse MAC</strong> (ex. <code>C4:86:96:A2:90:92</code>) et un nom (ex. <em>Sabre-01</em>).',
                'Choisissez le <strong>joueur associé</strong> puis <strong>Enregistrer le sabre</strong>.',
            ],
            'astuces' => ['Le bandeau en haut indique l\'état du récepteur Bluetooth (actif, arrêté, en erreur).'],
            'voir' => ['sabres-tester'],
            'mots' => 'sabre bluetooth ble ajouter mac enregistrer appareil',
        ],
        [
            'id' => 'sabres-tester', 'section' => 'materiel', 'acces' => ['sabres.voir'],
            'titre' => 'Vérifier qu\'un sabre fonctionne',
            'resume' => 'Voir l\'état de connexion et les touches en direct.',
            'page' => 'admin/sabres.php',
            'etapes' => [
                'Ouvrez <a href="{base}admin/sabres.php">Admin › Sabres</a>.',
                'Dans <strong>Sabres enregistrés</strong>, chaque sabre affiche s\'il est connecté et quand il a été vu pour la dernière fois.',
                'Donnez une touche avec le sabre : la ligne s\'allume et la dernière touche se met à jour.',
            ],
            'voir' => ['sabres-modifier', 'faq-sabre'],
            'mots' => 'tester sabre touche connexion état batterie',
        ],
        [
            'id' => 'sabres-modifier', 'section' => 'materiel', 'acces' => ['sabres.modifier', 'sabres.supprimer'],
            'titre' => 'Modifier, désactiver ou supprimer un sabre',
            'resume' => 'Renommer, changer de joueur, mettre de côté un sabre.',
            'page' => 'admin/sabres.php',
            'etapes' => [
                'Dans <a href="{base}admin/sabres.php">Admin › Sabres</a>, menu <strong>⋯</strong> sur la ligne du sabre.',
                '<strong>Modifier</strong> : nom, joueur associé, interrupteur <strong>actif</strong> (un sabre inactif est ignoré).',
                '<strong>Supprimer</strong> pour l\'oublier définitivement.',
            ],
            'mots' => 'renommer sabre désactiver supprimer joueur associé',
        ],
        [
            'id' => 'telecommandes-associer', 'section' => 'materiel', 'acces' => ['sabres.modifier'],
            'titre' => 'Associer une télécommande à un arbitre',
            'resume' => 'Chaque télécommande donne les points d\'un arbitre précis.',
            'page' => 'admin/sabres.php',
            'etapes' => [
                'Allumez la télécommande à portée du Raspberry : elle apparaît toute seule dans <a href="{base}admin/sabres.php">Admin › Sabres</a> › <strong>Télécommandes</strong>.',
                'Dans sa ligne, choisissez l\'<strong>arbitre</strong> qui l\'utilise.',
                'C\'est tout : les points de la télécommande comptent pour cet arbitre, dans le combat en cours où il arbitre.',
            ],
            'astuces' => ['Le nom Bluetooth d\'une télécommande commence par <code>TEL</code> et doit être différent pour chacune.'],
            'voir' => ['telecommande'],
            'mots' => 'télécommande associer arbitre bluetooth tel',
        ],

        // ── Wi-Fi ─────────────────────────────────────────────────────────
        [
            'id' => 'wifi-acces', 'section' => 'wifi', 'acces' => ['wifi.gerer'],
            'titre' => 'Créer les accès Wi-Fi',
            'resume' => 'Identifiants pour rejoindre le Wi-Fi de la salle.',
            'page' => 'admin/wifi.php',
            'capture' => 'wifi.webp', 'legende' => 'Admin › Wi-Fi.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/wifi.php">Admin › Wi-Fi</a> puis {1} <strong>Nouvel accès</strong>.',
                'Choisissez un <strong>identifiant</strong>, un mot de passe (vide = généré automatiquement) et une description (ex. <em>Tablette arbitre table A</em>).',
                'Touchez <strong>Créer l\'accès</strong> : il est appliqué sur le Raspberry en 15 secondes environ.',
            ],
            'astuces' => ['Un accès désactivé ou supprimé est déconnecté du Wi-Fi en 15 secondes environ. Une connexion dure 12 h.'],
            'voir' => ['wifi-rejoindre', 'wifi-renommer'],
            'mots' => 'wifi accès identifiant mot de passe réseau créer portail',
        ],
        [
            'id' => 'wifi-renommer', 'section' => 'wifi', 'acces' => ['wifi.gerer'],
            'titre' => 'Renommer le Wi-Fi',
            'resume' => 'Changer le nom du réseau diffusé par le Raspberry.',
            'page' => 'admin/wifi.php',
            'etapes' => [
                'Dans <a href="{base}admin/wifi.php">Admin › Wi-Fi</a>, carte <strong>Point d\'accès</strong>, tapez le nouveau nom.',
                'Touchez <strong>Renommer</strong> : le point d\'accès redémarre quelques secondes.',
                'Les appareils rejoignent le nouveau nom sans devoir se réidentifier.',
            ],
            'mots' => 'renommer wifi ssid nom réseau',
        ],
        [
            'id' => 'wifi-voir', 'section' => 'wifi', 'acces' => ['wifi.voir'],
            'titre' => 'Voir l\'état du Wi-Fi',
            'resume' => 'Le point d\'accès fonctionne-t-il, qui y a accès.',
            'page' => 'admin/wifi.php',
            'etapes' => [
                'Ouvrez <a href="{base}admin/wifi.php">Admin › Wi-Fi</a>.',
                'La carte <strong>Point d\'accès</strong> indique le nom du Wi-Fi et son état sur le Raspberry.',
                'La liste montre les accès autorisés et s\'ils sont actifs.',
            ],
            'voir' => ['wifi-rejoindre'],
            'mots' => 'wifi état statut point accès',
        ],

        // ── Paramètres et apparence ───────────────────────────────────────
        [
            'id' => 'parametres', 'section' => 'reglages', 'acces' => ['parametres.voir', 'parametres.modifier'],
            'titre' => 'Régler le tournoi',
            'resume' => 'Nom, inscriptions, durée des combats, démarrage auto, appariement, affichage.',
            'page' => 'admin/parametres.php',
            'capture' => 'parametres.webp', 'legende' => 'Admin › Paramètres.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/parametres.php">Admin › Paramètres</a>.',
                '{1} Les raccourcis mènent à chaque groupe de réglages.',
                '<strong>Tournoi</strong> : nom affiché partout, inscriptions ouvertes, durée par défaut d\'un combat.',
                '<strong>Planning</strong> : démarrage automatique, temps entre deux combats.',
                '<strong>Appariement</strong> : écarts maximum de poids, d\'âge et de grade, même sexe (0 = sans limite).',
                '<strong>Affichage</strong> : vitesse de mise à jour des scores et de rotation de l\'écran de salle.',
                '{2} Touchez <strong>Enregistrer les paramètres</strong> (ou <kbd>Ctrl</kbd>+<kbd>S</kbd>) : appliqué immédiatement à tous les écrans.',
            ],
            'voir' => ['grades', 'champs-joueurs', 'apparence'],
            'mots' => 'paramètres réglages configuration nom tournoi durée appariement inscription',
        ],
        [
            'id' => 'grades', 'section' => 'reglages', 'acces' => ['grades.gerer'],
            'titre' => 'Gérer les grades',
            'resume' => 'Les niveaux des joueurs, utilisés pour l\'appariement.',
            'page' => 'admin/parametres.php#heading-grades',
            'etapes' => [
                'Dans <a href="{base}admin/parametres.php#heading-grades">Admin › Paramètres › Grades</a>, touchez <strong>Nouveau grade</strong>.',
                'Donnez un nom, un <strong>ordre</strong> (du plus bas au plus haut) et une couleur de badge.',
                'Touchez <strong>Créer le grade</strong>.',
            ],
            'astuces' => ['L\'ordre sert à calculer l\'écart de grade entre deux joueurs.'],
            'mots' => 'grade niveau ceinture rang',
        ],
        [
            'id' => 'champs-joueurs', 'section' => 'reglages', 'acces' => ['joueurs.champs'],
            'titre' => 'Ajouter des champs aux fiches joueurs',
            'resume' => 'N° de licence, taille, main forte… sur chaque fiche.',
            'page' => 'admin/parametres.php?action=new_champ',
            'etapes' => [
                'Ouvrez <a href="{base}admin/parametres.php?action=new_champ">Admin › Paramètres › Nouveau champ</a>.',
                'Donnez un nom et un type (texte, nombre, liste de choix…). Pour une liste, un choix par ligne.',
                'Touchez <strong>Créer le champ</strong> : il apparaît dans le formulaire joueur, la liste et l\'import / export CSV.',
            ],
            'mots' => 'champ personnalisé licence fiche joueur colonne',
        ],
        [
            'id' => 'apparence', 'section' => 'reglages', 'acces' => ['parametres.modifier'],
            'titre' => 'Changer le thème et le logo',
            'resume' => 'Couleurs et logo pour tout le site, l\'écran de salle et l\'app installée.',
            'page' => 'admin/apparence.php',
            'capture' => 'apparence.webp', 'legende' => 'Admin › Apparence.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/apparence.php">Admin › Apparence</a>.',
                '{1} Choisissez un thème (<em>Basique</em>, <em>Clair</em>, <em>Orange · blanc · bleu</em>…) puis <strong>Appliquer le thème</strong>.',
                '{2} <strong>Créer un thème</strong> : choisissez 8 couleurs avec un aperçu en direct, puis <strong>Enregistrer le thème</strong>.',
                'Plus bas, <strong>Logo</strong> : envoyez une image (PNG, JPEG ou WebP, 2 Mo max), puis <strong>Utiliser</strong>.',
            ],
            'astuces' => ['Le logo remplace aussi l\'icône de l\'application installée et le favicon.', '« Lame d\'origine » revient au logo de départ.'],
            'mots' => 'thème couleur logo apparence design personnaliser club',
        ],

        // ── Bilan ─────────────────────────────────────────────────────────
        [
            'id' => 'bilan', 'section' => 'bilan', 'acces' => ['bilan.voir'],
            'titre' => 'Imprimer le bilan de la journée',
            'resume' => 'Classement, arbre, historique complet et joueurs, en PDF ou CSV.',
            'page' => 'admin/bilan.php',
            'capture' => 'bilan.webp', 'legende' => 'Admin › Bilan.',
            'etapes' => [
                'Ouvrez <a href="{base}admin/bilan.php">Admin › Bilan</a>.',
                '{1} Choisissez la <strong>journée</strong> (ou toutes).',
                '{2} <strong>Imprimer / PDF</strong> : imprimez, ou choisissez « Enregistrer en PDF » comme imprimante.',
                '{3} <strong>Combats (CSV)</strong> : tous les combats dans un fichier pour Excel.',
            ],
            'mots' => 'bilan imprimer pdf rapport fin journée export csv historique',
        ],

        // ── Problèmes fréquents ───────────────────────────────────────────
        [
            'id' => 'faq-score-bouge-pas', 'section' => 'faq', 'acces' => ['arbitrage.voter'],
            'titre' => 'J\'ai donné un point mais le score officiel ne bouge pas',
            'resume' => 'C\'est normal : il attend les autres arbitres.',
            'etapes' => [
                'Le score officiel retient le <strong>total le plus bas</strong> des trois arbitres.',
                'Votre point compte (votre grand chiffre a bougé), mais le score officiel n\'augmente que quand les deux autres arbitres ont aussi donné ce point.',
            ],
            'voir' => ['score-officiel'],
            'mots' => 'score bloqué ne bouge pas officiel point pas compté',
        ],
        [
            'id' => 'faq-pas-de-boutons', 'section' => 'faq', 'acces' => ['arbitrage.acces', 'arbitrage.voter'],
            'titre' => 'Je ne vois pas les boutons de points',
            'resume' => 'Le combat n\'a pas commencé, est en pause, ou vous n\'en êtes pas l\'arbitre.',
            'etapes' => [
                '<strong>Combat à venir</strong> : les boutons apparaissent au coup d\'envoi (la page se met à jour seule).',
                '<strong>En pause</strong> : un bandeau orange l\'indique, attendez la reprise.',
                '<strong>« Lecture seule »</strong> : vous n\'êtes pas l\'un des 3 arbitres de ce combat. Vérifiez dans <a href="{base}arbitrage/index.php">Arbitrage</a> que vous ouvrez le bon.',
            ],
            'voir' => ['arbitrage-points'],
            'mots' => 'boutons absents pas de bouton lecture seule impossible noter',
        ],
        [
            'id' => 'faq-combat-demarre-pas', 'section' => 'faq', 'acces' => ['combats.demarrer', 'combats.modifier'],
            'titre' => 'Un combat ne démarre pas',
            'resume' => 'Il lui manque presque toujours des arbitres.',
            'etapes' => [
                'Un combat ne démarre (à la main ou automatiquement) que s\'il a ses <strong>3 arbitres</strong>.',
                'Dans <a href="{base}admin/combats.php">Admin › Combats</a>, touchez <strong>Affecter</strong> sur sa ligne et complétez les arbitres.',
                'Vérifiez aussi que le <strong>démarrage automatique</strong> est activé si vous comptez dessus.',
            ],
            'voir' => ['combats-modifier', 'combats-demarrer'],
            'mots' => 'combat démarre pas bloqué arbitres manquants',
        ],
        [
            'id' => 'faq-connexion-perdue', 'section' => 'faq', 'acces' => 'tous',
            'titre' => '« Connexion perdue » s\'affiche',
            'resume' => 'Le téléphone n\'arrive plus à joindre le serveur.',
            'etapes' => [
                'Vérifiez que vous êtes toujours sur le <strong>Wi-Fi de la salle</strong> (le téléphone bascule parfois sur la 4G/5G).',
                'La page réessaie toute seule : dès que le réseau revient, les scores se remettent à jour.',
                'Si rien ne revient, rechargez la page.',
            ],
            'voir' => ['wifi-rejoindre'],
            'mots' => 'connexion perdue réseau hors ligne offline bug ne charge pas',
        ],
        [
            'id' => 'faq-acces-refuse', 'section' => 'faq', 'acces' => 'connecte',
            'titre' => '« Accès refusé » ou une page qui manque',
            'resume' => 'Votre rôle n\'a pas la permission nécessaire.',
            'etapes' => [
                'Chaque compte a un rôle, et chaque rôle ouvre certaines pages seulement.',
                'Demandez à un administrateur d\'ajouter la permission à votre rôle (Admin › Rôles).',
                'Ce guide ne montre que ce que votre rôle permet : si une fiche manque ici, c\'est que vous n\'y avez pas accès.',
            ],
            'mots' => 'accès refusé interdit 403 permission manque page absente',
        ],
        [
            'id' => 'faq-sabre', 'section' => 'faq', 'acces' => ['sabres.voir'],
            'titre' => 'Un sabre n\'enregistre pas les touches',
            'resume' => 'Vérifier le récepteur, le sabre et son association.',
            'etapes' => [
                'En haut de <a href="{base}admin/sabres.php">Admin › Sabres</a>, le récepteur Bluetooth doit être <strong>actif</strong>.',
                'Le sabre doit être <strong>allumé</strong>, à portée du Raspberry, et <strong>actif</strong> dans sa fiche.',
                'Il doit être <strong>associé au joueur</strong> qui combat : sinon ses touches ne vont dans aucun combat.',
            ],
            'voir' => ['sabres-tester'],
            'mots' => 'sabre marche pas touche ne compte pas bluetooth récepteur',
        ],
    ];
}

/** La fiche est-elle accessible au visiteur actuel ? */
function guideFicheAccessible(array $fiche, PDO $pdo): bool
{
    if (($fiche['si'] ?? '') === 'inscriptions' && getParametreInt($pdo, 'inscriptions_ouvertes', 1) !== 1) return false;
    $acces = $fiche['acces'];
    $connecte = (bool)currentUser();
    if ($acces === 'tous') return true;
    if ($acces === 'invite') return !$connecte;
    if ($acces === 'connecte') return $connecte;
    return can(...$acces);
}

/** Fiches visibles par le visiteur actuel, indexées par id. */
function guideFichesVisibles(PDO $pdo): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    foreach (guideFiches() as $f) {
        if (guideFicheAccessible($f, $pdo)) $cache[$f['id']] = $f;
    }
    return $cache;
}

/** Remplace {base} et les repères {1}, {2}… d'un texte de fiche. */
function guideTexte(string $html): string
{
    $html = str_replace('{base}', BASE_URL, $html);
    return preg_replace('/\{(\d{1,2})\}/', '<span class="guide-pin" aria-label="repère $1">$1</span>', $html);
}

/**
 * Fiche d'aide qui correspond à la page affichée (bouton « Aide » de la barre).
 * Renvoie l'id de la première fiche visible dont la page correspond, sinon null.
 */
function guideFichePourPage(PDO $pdo, string $script, string $dossier): ?string
{
    $chemin = in_array($dossier, ['admin', 'arbitrage'], true) ? $dossier . '/' . $script : $script;
    $parPage = [
        'index.php'            => ['suivre-direct'],
        'combat.php'           => ['voir-combat'],
        'arbre.php'            => ['arbre'],
        'scores.php'           => ['resultats'],
        'classement.php'       => ['classement'],
        'ecran.php'            => ['ecran-salle'],
        'login.php'            => ['se-connecter'],
        'register.php'         => ['creer-compte'],
        'compte.php'           => ['mot-de-passe'],
        'arbitrage/index.php'  => ['arbitrage-mes-combats', 'arbitrage-superviser'],
        'arbitrage/saisie.php' => ['arbitrage-points', 'arbitrage-pause', 'score-officiel'],
        'admin/index.php'      => ['tableau-de-bord', 'parcours-tournoi'],
        'admin/joueurs.php'    => ['joueurs-ajouter', 'joueurs-modifier', 'joueurs-importer'],
        'admin/combats.php'    => ['combats-generer', 'combats-modifier', 'combats-demarrer', 'combats-creer'],
        'admin/users.php'      => ['comptes-creer', 'comptes-modifier'],
        'admin/roles.php'      => ['roles'],
        'admin/sabres.php'     => ['sabres-ajouter', 'sabres-tester'],
        'admin/wifi.php'       => ['wifi-acces', 'wifi-voir'],
        'admin/parametres.php' => ['parametres', 'grades'],
        'admin/apparence.php'  => ['apparence'],
        'admin/bilan.php'      => ['bilan'],
    ];
    $visibles = guideFichesVisibles($pdo);
    foreach ($parPage[$chemin] ?? [] as $id) {
        if (isset($visibles[$id])) return $id;
    }
    return null;
}
