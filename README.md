# ⚔️ Sabre Laser Arbitrage

Plateforme web de gestion de tournoi de sabre laser : import des participants, génération de l'arbre,
arbitrage en direct à 3 arbitres, sabres et télécommandes Bluetooth, classement et écran de salle.
Elle tourne sur un **Raspberry Pi** qui diffuse son propre Wi-Fi, ou sur n'importe quel PC avec Docker.

**Projet CFPT — 2026-27, classe I.DA-P3B · Équipe : Tom & Lucas**

---

## 🚀 Installation rapide

Prérequis : [Docker](https://docs.docker.com/get-docker/) installé et démarré
(sous Windows, lancer les commandes dans **Git Bash**).

```bash
git clone https://github.com/CFPT-AWEB-PH/2026-27_I.DA-P3B_LuTo.git
cd 2026-27_I.DA-P3B_LuTo/app
./install.sh
```

Le site s'ouvre sur **http://localhost:8080** · compte `admin` / `admin123`
(le changement du mot de passe est imposé à la première connexion).

Installation sans Docker, Raspberry Pi (Wi-Fi, écran), sabres Bluetooth, télécommandes :
voir le **[guide d'installation](docs/installation.md)**.

---

## ✨ Fonctionnalités

| Pour qui | Ce que l'application permet |
|----------|-----------------------------|
| **Administrateur** | Import CSV des joueurs, génération de l'arbre et des combats, comptes et rôles, sabres, Wi-Fi, thèmes et logos |
| **Arbitres** | Pupitre de saisie des points en direct (1 central + 2 coins), chrono, pause, télécommande Bluetooth |
| **Public** | Combats en cours et à venir, arbre du tournoi, classement, écran de salle, application installable (PWA) |

**Technologies :** PHP 8.2 · MariaDB 11 · Apache · JavaScript sans framework · Docker Compose ·
Python (récepteur Bluetooth BLE) · CircuitPython (télécommandes) · Raspberry Pi OS.

---

## 📁 Organisation du dépôt

```
.
├── app/                     Application (à lancer depuis ce dossier)
│   ├── install.sh           Installation en une commande
│   ├── docker-compose.yml   Services : web, db, + optionnels ble / pi / tunnel
│   ├── admin/ arbitrage/ api/ includes/ config/ assets/   Code PHP / JS / CSS
│   ├── sql/                 Schéma complet + données de démonstration
│   ├── ble_receiver/        Récepteur Bluetooth (sabres, télécommandes)
│   ├── telecommandes/       Code des télécommandes d'arbitre
│   ├── raspberry/           Scripts d'installation du Raspberry Pi
│   └── tests/               Tests de fonctionnement et de sécurité
└── docs/
    ├── installation.md      Guide technique complet
    ├── cahier-des-charges.md
    ├── journal-de-bord.md
    ├── securite.md          Audit de sécurité
    ├── design-system.md     Règles de l'interface
    ├── guides/              Guides d'utilisation PDF (spectateur, arbitre, admin)
    ├── images/              Maquettes et schémas
    └── exemples/            Exemple de fichier CSV d'import des joueurs
```

---

## 📚 Documentation

- [Cahier des charges](docs/cahier-des-charges.md)
- [Journal de bord](docs/journal-de-bord.md)
- [Guide d'installation et d'exploitation](docs/installation.md)
- [Audit de sécurité](docs/securite.md) · [Design system](docs/design-system.md)
- Guides d'utilisation : [spectateur](docs/guides/Guide_utilisation_spectateur.pdf) ·
  [arbitre](docs/guides/Guide_utilisation_arbitre.pdf) ·
  [administrateur](docs/guides/Guide_utilisation_complet_admin.pdf)
  (aussi disponibles dans l'application, page **Aide**)

## ✅ Tests

```bash
cd app
python3 tests/verif_fonctionnement.py http://localhost:8080 --admin admin   # pages, API, droits
```
