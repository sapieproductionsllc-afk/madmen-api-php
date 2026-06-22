#!/usr/bin/env bash
# Ajoute la route api-madmen.ssmanager.uk au Caddy existant (idempotent) et recharge.
set -e
CF=/opt/ssm-cloud/deploy/Caddyfile.prod

if ! sudo grep -q "api-madmen.ssmanager.uk" "$CF"; then
  sudo tee -a "$CF" >/dev/null <<'EOF'

# ── API MadMen ───────────────────────────────────────────
api-madmen.ssmanager.uk {
    reverse_proxy madmen-api:8000
}
EOF
  echo ">> Bloc Caddy ajoute"
else
  echo ">> Bloc Caddy deja present"
fi

echo ">> Rechargement de Caddy (sans coupure)..."
sudo docker exec -w /etc/caddy ssm-caddy caddy reload --config /etc/caddy/Caddyfile
echo ">> Caddy recharge. Teste : https://api-madmen.ssmanager.uk"
