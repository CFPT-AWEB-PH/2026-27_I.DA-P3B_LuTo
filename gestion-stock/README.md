# Gestion de salles, rangements et stock en 3D

Application web (PWA) pour modéliser des salles en 3D, y placer armoires et rangements imbriqués, et gérer le stock d'objets avec traçabilité complète (prises, retours, déplacements). Hébergée sur un Raspberry Pi via Docker Compose.

## Structure

| Dossier | Contenu |
|---|---|
| `frontend/` | Interface HTML/CSS/JS, PWA (manifeste, service worker), Three.js local |
| `backend/` | API PHP 8.3 (routeur, contrôleurs, services, authentification) |
| `database/` | Migrations SQL numérotées et données initiales |
| `docker/` | Dockerfiles et configuration (PHP-FPM, Caddy, sauvegardes) |
| `scripts/` | Déploiement, sauvegarde, restauration, retour arrière |
| `docs/` | Architecture, exploitation, tests |

## État

Phase 1 (audit et préparation) en cours. Voir [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) et [docs/JOURNAL.md](docs/JOURNAL.md).
