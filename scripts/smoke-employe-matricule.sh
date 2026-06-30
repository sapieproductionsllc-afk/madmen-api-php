#!/usr/bin/env bash
# Garde anti-régression : prouve que TOUTE route /api/employes/{id}/... accepte un
# MATRICULE (pas seulement l'id numérique). Un 404 = la route fait encore
# (int)$params['id'] au lieu de MadMen\Core\Employe::resolveId -> bug à corriger.
#
# Usage :  BASE=https://api-madmen.ssmanager.uk  TOKEN=<jwt super_admin>  MATRICULE=EMP-0014 \
#            bash scripts/smoke-employe-matricule.sh
# (TOKEN = un JWT super_admin valide ; MATRICULE = un employé réel AYANT des données.)
set -u
BASE="${BASE:-https://api-madmen.ssmanager.uk}"
M="${MATRICULE:-EMP-0014}"
MOIS="$(date +%Y-%m 2>/dev/null || echo 2026-06)"
if [ -z "${TOKEN:-}" ]; then echo "TOKEN manquant (JWT super_admin)"; exit 2; fi

# Routes GET en lecture seule sous /api/employes/{id}/ (sûr à sonder).
paths=(
  ""                      "/horaire"            "/paie?mois=$MOIS"
  "/salaire"              "/biometrie"          "/biometrie/export"
  "/ajustements?mois=$MOIS" "/presence?mois=$MOIS" "/documents"
  "/historique-rh"
)
echo "== Smoke matricule ($M) sur $BASE =="
fail=0
for p in "${paths[@]}"; do
  code=$(curl -s -o /dev/null -w '%{http_code}' -m15 -H "Authorization: Bearer $TOKEN" "$BASE/api/employes/$M$p")
  label="GET /api/employes/$M${p%%\?*}"
  if [ "$code" = "404" ]; then printf '  ✗ %-40s -> %s  (matricule REFUSE)\n' "$label" "$code"; fail=1
  else printf '  ✓ %-40s -> %s\n' "$label" "$code"; fi
done
if [ "$fail" = "1" ]; then echo "ECHEC : au moins une route refuse le matricule."; exit 1; fi
echo "OK : toutes les routes employé acceptent le matricule."
