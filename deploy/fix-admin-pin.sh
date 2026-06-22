#!/usr/bin/env bash
# Remet le PIN de ADMIN-001 à 1234 de façon fiable (hash généré côté serveur).
# Usage : sudo bash fix.sh   (après l'avoir téléchargé)
set -e
cd /opt/madmen/madmen-api-php
git pull --ff-only || true
echo ">> Reconstruction (récupère le script set_admin_pin)..."
docker compose -f deploy/docker-compose.yml --env-file .env up -d --build
echo ">> Définition du PIN..."
docker compose -f deploy/docker-compose.yml --env-file .env exec -T api php database/set_admin_pin.php
echo ">> Fini. PIN de ADMIN-001 = 1234"
