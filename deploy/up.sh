#!/usr/bin/env bash
# Déploie l'API MadMen : crée le .env (secrets auto), construit l'image,
# démarre API + MySQL, applique les migrations. Idempotent.
set -e
cd "$(dirname "$0")/.."   # racine du repo

if [ ! -f .env ]; then
  cat > .env <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=$(openssl rand -hex 32)
API_KEY=$(openssl rand -hex 24)
AUTH_ENABLED=true
BRUTE_FORCE_ENABLED=true
BIO_SIMULATION=false
CORS_ORIGIN=*
DB_HOST=db
DB_PORT=3306
DB_NAME=madmen
DB_USER=madmen
DB_PASS=$(openssl rand -hex 16)
DB_ROOT_PASSWORD=$(openssl rand -hex 16)
EOF
  echo ">> .env cree"
else
  echo ">> .env deja present (conserve)"
fi

echo ">> Construction + demarrage (API + MySQL) -- quelques minutes..."
sudo docker compose -f deploy/docker-compose.yml --env-file .env up -d --build

echo ">> Attente du demarrage de la base (20s)..."
sleep 20

echo ">> Migrations (creation des tables)..."
sudo docker compose -f deploy/docker-compose.yml --env-file .env exec -T api php database/migrate.php migrate \
  || echo "!! Migrations en echec (base pas prete ?). Relance: bash deploy/up.sh"

echo ""
echo ">> Etat des conteneurs :"
sudo docker ps --format 'table {{.Names}}\t{{.Status}}'
echo ""
echo ">> Termine. Etape suivante : bash deploy/caddy.sh"
