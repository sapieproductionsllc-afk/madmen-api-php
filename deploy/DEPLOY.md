# Déploiement de l'API MadMen (Docker + Caddy)

Met l'API en ligne sur **https://api-madmen.ssmanager.uk**, derrière le Caddy déjà présent
sur le serveur `ssmanager.uk` (89.167.42.121). Aucune modification du site `ssm-cloud` existant.

## Architecture
- `madmen-api` : conteneur PHP 8.4 (serveur intégré, port interne 8000), rejoint le réseau
  `ssm-cloud_ssm` pour que Caddy l'atteigne.
- `madmen-db` : conteneur MySQL 8.4 (réseau privé, non exposé).
- Caddy ajoute une route `api-madmen.ssmanager.uk -> madmen-api:8000` (HTTPS automatique).

## Prérequis (déjà faits)
- DNS : `api-madmen.ssmanager.uk` (A) -> 89.167.42.121, **DNS only** (nuage gris Cloudflare). ✅

## Étapes (sur le serveur, en SSH)

```bash
# 0) (si disque plein) faire un peu de place
docker system prune -f

# 1) Récupérer le code (branche de travail = code testé ; 'main' est en retard)
sudo mkdir -p /opt/madmen && sudo chown "$USER" /opt/madmen
cd /opt/madmen
git clone -b feat/controle-activite-fondations https://github.com/sapieproductionsllc-afk/madmen-api-php.git
cd madmen-api-php

# 2) Créer le .env de prod
cp deploy/.env.server.example .env
# Générer les secrets et les coller dans .env (APP_KEY / API_KEY) :
echo "APP_KEY=$(openssl rand -hex 32)"
echo "API_KEY=$(openssl rand -hex 24)"
# Puis éditer .env : coller APP_KEY/API_KEY ci-dessus + choisir DB_PASS et DB_ROOT_PASSWORD.
nano .env

# 3) Démarrer l'API + la base
docker compose -f deploy/docker-compose.yml --env-file .env up -d --build

# 4) Migrations (création des tables)
docker compose -f deploy/docker-compose.yml exec api php database/migrate.php migrate

# (optionnel) données de démo :
# docker compose -f deploy/docker-compose.yml exec api php database/seed.php

# 5) Vérifier que l'API répond en interne
docker compose -f deploy/docker-compose.yml exec api wget -qO- http://localhost:8000/ || true
```

## Brancher à Caddy (route + HTTPS)

> Bloc exact + commande de reload fournis séparément après lecture du Caddyfile actuel
> (`/opt/ssm-cloud/deploy/Caddyfile.prod`). Principe :

```caddy
api-madmen.ssmanager.uk {
    reverse_proxy madmen-api:8000
}
```
Puis recharge Caddy (sans coupure) :
```bash
docker exec -w /etc/caddy ssm-caddy caddy reload --config /etc/caddy/Caddyfile
```

## Vérifier en ligne
```bash
curl -i https://api-madmen.ssmanager.uk/
```

## Mettre à jour plus tard (nouveau code)
```bash
cd /opt/madmen/madmen-api-php && git pull
docker compose -f deploy/docker-compose.yml --env-file .env up -d --build
```
