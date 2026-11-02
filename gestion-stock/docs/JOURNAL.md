# Journal des phases

## Phase 1 : audit et préparation (2026-11-02)

**Poste de développement (Windows 11)** : Git 2.55, Node 24, Docker 29.7 et Compose 5.4 installés (Docker Desktop arrêté) ; PHP et Composer absents (on les utilisera via Docker) ; une clé SSH ed25519 est présente.

**Raspberry Pi 10.5.35.39** : joignable, SSH accepte `publickey,password`. La clé du poste n'est pas encore autorisée. Architecture, OS, RAM, stockage et données existantes **non encore vérifiés**.

**Fait** : structure du dépôt, document d'architecture, `.env.example`, `.gitignore`.

**Bloquant** : installation de la clé SSH, choix du nom de domaine et du mode HTTPS, URL du dépôt distant.
