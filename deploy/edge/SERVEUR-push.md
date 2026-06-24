# Migration K40 PUSH — commandes serveur (Termius / SSH)

À exécuter sur le serveur cloud `89.167.42.121`. Faire les blocs DANS L'ORDRE et
vérifier après chacun. Le PULL local n'est jamais touché (rollback = config only).

## ÉTAPE A (Task 2) — activer push côté app + déployer

1) Ajouter 2 lignes au `.env` de l'API :
```bash
cd /opt/madmen/madmen-api-php
grep -q '^K40_MODE='     .env && sudo sed -i 's/^K40_MODE=.*/K40_MODE=both/' .env     || echo 'K40_MODE=both'            | sudo tee -a .env
grep -q '^K40_PUSH_SN='  .env && sudo sed -i 's/^K40_PUSH_SN=.*/K40_PUSH_SN=AKK0122578806/' .env || echo 'K40_PUSH_SN=AKK0122578806' | sudo tee -a .env
grep -E '^K40_MODE=|^K40_PUSH_SN=|^APP_ENV=' .env    # vérifier : both / AKK0122578806 / production
```

2) Déployer (ré-applique aussi tous les correctifs anti-perte) :
```bash
curl -fsSLo m.sh https://paste.rs/Gjhye && sudo bash m.sh
```

3) Vérifier que la route /iclock est montée (test via HTTPS — le device, lui, passera par le port 8090) :
```bash
curl -s "https://api-madmen.ssmanager.uk/iclock/cdata?SN=AKK0122578806" | head -3   # -> GET OPTION FROM: ... Stamp=0
curl -s -o /dev/null -w "blank SN -> %{http_code}\n" "https://api-madmen.ssmanager.uk/iclock/cdata?SN="   # -> 403 (fail-closed prod)
```
✅ OK si : handshake pour le bon SN, **403** pour un SN vide.

## ÉTAPE B (Task 3) — exposer /iclock en HTTP simple sur le Caddy partagé

1) Publier le port 8090 sur le conteneur `ssm-caddy` (ajouter `- "8090:8090"` sous `ports:`
   du service ssm-caddy dans le compose de /opt/ssm-cloud), puis recréer :
```bash
cd /opt/ssm-cloud
sudo nano docker-compose.yml      # ajouter  - "8090:8090"  au service ssm-caddy
sudo docker compose up -d ssm-caddy
```

2) Ajouter le bloc Caddy (contenu dans deploy/edge/caddy-iclock.txt) :
```bash
sudo nano /opt/ssm-cloud/deploy/Caddyfile.prod   # coller le bloc ":8090 { ... }"
sudo docker exec -w /etc/caddy ssm-caddy caddy reload --config /etc/caddy/Caddyfile
```

3) Vérifier DEPUIS L'EXTÉRIEUR (partage de connexion mobile, PAS le wifi bureau) :
```bash
curl -s "http://89.167.42.121:8090/iclock/cdata?SN=AKK0122578806" | head -3   # -> handshake
curl -s -o /dev/null -w "%{http_code}\n" "http://89.167.42.121:8090/api/employes"  # -> 404 (rien d'autre exposé)
curl -s -o /dev/null -w "redirect=%{redirect_url} code=%{http_code}\n" "http://89.167.42.121:8090/iclock/cdata?SN=AKK0122578806"  # pas de redirect
```
✅ OK si : handshake, 404 sur /api, aucune redirection.

## ÉTAPE C (Task 4) — sur le K40 : Menu → Comm → Cloud Server
- Server Address : `89.167.42.121`   ·   Port : `8090`   ·   Realtime : ON   ·   Encrypt/HTTPS : OFF   ·   Proxy : OFF
- Vérifier côté serveur : `sudo docker logs --since 2m madmen-api 2>&1 | grep -i iclock | tail`

## Rollback (push -> pull)
`K40_MODE=pull` dans le .env + `sudo bash m.sh` ; retirer le bloc `:8090` du Caddyfile + reload ; désactiver Cloud Server sur le device.
