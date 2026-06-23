#!/usr/bin/env bash
# Bootstrap COMPLET de l'API MadMen — à lancer en root (sudo bash).
# Clone le repo, crée le .env, build+démarre API+MySQL, migre, branche Caddy.
# Idempotent : peut être relancé sans danger.
set -e
echo "== MadMen API : déploiement =="

mkdir -p /opt/madmen
cd /opt/madmen
if [ -d madmen-api-php/.git ]; then
  echo ">> Repo présent -> mise à jour"
  cd madmen-api-php
  git fetch --all
  # Déploiement depuis 'main' (branche consolidée). Bascule propre même si le clone
  # existant était sur l'ancienne branche de travail. reset --hard ne touche pas .env
  # (fichier non suivi). Pour revenir à l'ancienne branche : remplacer 'main' ci-dessous.
  git checkout main 2>/dev/null || git checkout -b main origin/main
  git reset --hard origin/main
else
  rm -rf madmen-api-php
  git clone -b main https://github.com/sapieproductionsllc-afk/madmen-api-php.git
  cd madmen-api-php
fi

if [ ! -f .env ]; then
  cat > .env <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=$(openssl rand -hex 32)
API_KEY=$(openssl rand -hex 24)
AUTH_ENABLED=true
BRUTE_FORCE_ENABLED=true
BIO_SIMULATION=false
CORS_ORIGIN=http://localhost:5210,http://localhost:5220
DB_HOST=db
DB_PORT=3306
DB_NAME=madmen
DB_USER=madmen
DB_PASS=$(openssl rand -hex 16)
DB_ROOT_PASSWORD=$(openssl rand -hex 16)
EOF
  echo ">> .env créé"
else
  echo ">> .env déjà présent"
fi

# Corrige un .env existant resté en CORS_ORIGIN=* (refusé en production).
sed -i 's|^CORS_ORIGIN=\*[[:space:]]*$|CORS_ORIGIN=http://localhost:5210,http://localhost:5220|' .env

echo ">> Build + démarrage (API + MySQL)... quelques minutes"
docker compose -f deploy/docker-compose.yml --env-file .env up -d --build

echo ">> Attente du démarrage de la base (20s)..."
sleep 20

echo ">> Migrations..."
docker compose -f deploy/docker-compose.yml --env-file .env exec -T api php database/migrate.php migrate \
  || echo "!! Migrations en échec (base pas prête ?) — relance: sudo bash /opt/madmen/madmen-api-php/deploy/bootstrap.sh"

echo ">> Compte admin (créé seulement si aucun super_admin n'existe)..."
docker compose -f deploy/docker-compose.yml --env-file .env exec -T api php database/creer_admin.php || true

CF=/opt/ssm-cloud/deploy/Caddyfile.prod
if [ -f "$CF" ] && ! grep -q "api-madmen.ssmanager.uk" "$CF"; then
  cat >> "$CF" <<'EOF'

# ── API MadMen ───────────────────────────────────────────
api-madmen.ssmanager.uk {
    reverse_proxy madmen-api:8000
}
EOF
  echo ">> Route Caddy API ajoutée"
else
  echo ">> Route Caddy API déjà présente (ou Caddyfile introuvable)"
fi

if [ -f "$CF" ] && ! grep -q "db-madmen.ssmanager.uk" "$CF"; then
  cat >> "$CF" <<'EOF'

# ── Adminer (tableau de bord DB MadMen) ──────────────────
db-madmen.ssmanager.uk {
    reverse_proxy madmen-adminer:8080
}
EOF
  echo ">> Route Caddy Adminer ajoutée"
fi

echo ">> Reload de Caddy..."
docker exec -w /etc/caddy ssm-caddy caddy reload --config /etc/caddy/Caddyfile || true

echo ""
echo ">> État des conteneurs :"
docker ps --format 'table {{.Names}}\t{{.Status}}'
echo ""
echo ">> FINI. Teste : https://api-madmen.ssmanager.uk"
