# MadMen API (PHP natif)

API backend du **Module 1 — Gestion Intelligente des Employés & Contrôle des Postes de Travail**.
Écrite en **PHP natif** (PDO), sans framework, avec un système de **migrations maison**.

## Prérequis

- PHP >= 8.1 (avec `pdo_mysql`)
- MySQL / MariaDB

## Installation

```bash
# 1. Copier la config et l'adapter
cp .env.example .env
#   -> renseigner DB_HOST, DB_NAME, DB_USER, DB_PASS

# 2. Créer la base + toutes les tables
php database/migrate.php

# 2b. (optionnel) Données de démo : 17 employés sur 6 mois ouvrés
php database/seed.php

# 3. Lancer le serveur de dev
php -S 127.0.0.1:8000 -t public public/index.php
```

API dispo sur `http://127.0.0.1:8000`
- `GET /`           → infos de l'API
- `GET /health`     → test de connexion DB
- `GET /docs`       → **documentation Swagger UI**
- `GET /openapi.yaml` → spécification OpenAPI 3.0

## Documentation (Swagger)

La doc interactive est servie sur **`http://127.0.0.1:8000/docs`** (Swagger UI via CDN).
Le contrat OpenAPI se trouve dans `openapi.yaml` à la racine du projet — c'est le
**contrat cible** de l'API, à compléter au fur et à mesure de l'implémentation des routes `/api/*`.

## Migrations

```bash
php database/migrate.php           # applique les migrations en attente
php database/migrate.php status    # état des migrations
php database/migrate.php rollback  # annule le dernier lot
php database/migrate.php fresh     # supprime toutes les tables (puis relancer migrate)
```

Chaque table = un fichier dans `database/migrations/` (`NNN_create_xxx.php`)
retournant `['up' => 'SQL', 'down' => 'SQL']`. Pour ajouter une table, créer un
nouveau fichier avec le numéro suivant.

## Structure du projet

```
madmen-api-php/
├── config/
│   └── database.php          # config DB (lit le .env)
├── database/
│   ├── migrate.php           # runner de migrations
│   └── migrations/           # 18 migrations (1 par table)
├── public/
│   └── index.php             # point d'entrée de l'API
├── src/
│   └── Core/
│       └── Database.php      # connexion PDO (singleton)
├── .env.example
└── composer.json             # autoload PSR-4 (MadMen\ -> src/)
```

## Tables (19)

`departement`, `poste`, `employe`, `appareil_biometrique`, `employe_biometrie`,
`poste_travail`, `autorisation_poste`, `pointage`, `session_travail`,
`activite_echantillon`, `motif_absence`, `incident_inactivite`,
`tentative_connexion`, `alerte`, `productivite_jour`, `type_conge`,
`demande_conge`, `solde_conge`, `jour_ferie`.

> 🔐 Sécurité : le PIN est stocké **haché** (`code_pin_hash`), les gabarits
> biométriques en **BLOB chiffré** — jamais en clair.
