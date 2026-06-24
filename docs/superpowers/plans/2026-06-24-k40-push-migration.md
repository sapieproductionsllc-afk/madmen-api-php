# K40 PULL → PUSH (ADMS) Migration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the ZKTeco K40 push each punch straight to the cloud in real time (ADMS / `iclock`), so attendance no longer depends on an always-on office PC — and the last data-loss residual (#6) is closed.

**Architecture:** The K40 dials OUT over plain HTTP to a dedicated, non-redirecting `/iclock` listener on the shared Caddy, which proxies only `/iclock/*` to the `madmen-api` container. The existing `K40PushController` ingests each punch through the already-hardened `K40Pointage::record()` (append-only raw log + `client_uuid` dedup). The PULL path stays untouched as a manual fallback.

**Tech Stack:** PHP 8.4 (`php -S` in Docker), MySQL, Caddy (external shared `ssm-cloud` stack), ZKTeco ADMS/iclock protocol, pyzk (fallback only).

## Global Constraints

- Device: K40, LAN `192.168.1.201:4370`, SN **`AKK0122578806`**, firmware Ver 6.60 (HTTP-only — no HTTPS for ADMS).
- Cloud: `https://api-madmen.ssmanager.uk`, server IP `89.167.42.121`, external Caddy on docker network `ssm-cloud_ssm`, Caddyfile `/opt/ssm-cloud/deploy/Caddyfile.prod`, container name `ssm-caddy`, API container `madmen-api` (port 8000).
- **HTTP edge port for this plan: `8090`** (non-standard; change consistently if you pick another).
- Do NOT weaken auth, do NOT expose any path other than `/iclock/*` over plain HTTP, do NOT remove the SN whitelist guard, do NOT break the PULL fallback.
- Show the exact `.env` and Caddy diffs and get approval BEFORE applying anything on the server.
- Keep `APP_ENV=production` on the cloud (activates the fail-closed "refuse if SN not whitelisted" guard).

---

### Task 1: Pre-flight — confirm push-mode code is ready (local)

**Files:**
- Verify (no change): `public/index.php` (iclock route mounting), `config/k40.php` (`K40_MODE`/`K40_PUSH_SN`/`K40_ROLE`), `src/Controllers/K40PushController.php` (handshake + SN guard + receive), `src/Core/K40Pointage.php` (record → raw log first).

**Interfaces:**
- Produces (for later tasks): confirmation that `GET /iclock/cdata?SN=AKK0122578806` returns the ZKTeco handshake text and a bad/blank SN returns 403, when `K40_MODE` ∈ {push, both}.

- [ ] **Step 1: Confirm the repo is synced to origin/main (the data-loss fixes must be present)**

```bash
cd C:/dev/madmen/madmen-api-php
git fetch origin && git status -sb
git log --oneline -1 origin/main
```
Expected: local `main` not behind origin; HEAD includes the K40 data-loss commits (raw log, relay, alerts). If behind, `git pull --ff-only origin main` and resolve before continuing.

- [ ] **Step 2: Confirm `/iclock` routes mount only under push/both**

```bash
grep -nE "iclock|K40_MODE|K40_ROLE" public/index.php config/k40.php
```
Expected: the four `/iclock/*` routes are inside a guard keyed on `K40_MODE` being `push` or `both`; `config/k40.php` reads `K40_MODE`, `K40_PUSH_SN` (comma-separated), `K40_ROLE`. Note the exact condition.

- [ ] **Step 3: Local handshake test (temporarily enable push on the local API)**

In the LOCAL `.env`, set `K40_MODE=both` and `K40_PUSH_SN=AKK0122578806` (note the originals to restore). Restart the local API (`php -S 0.0.0.0:8000 -t public public/index.php`), then:
```bash
curl -s "http://127.0.0.1:8000/iclock/cdata?SN=AKK0122578806"
curl -s -o /dev/null -w "blank SN -> HTTP %{http_code}\n" "http://127.0.0.1:8000/iclock/cdata?SN="
```
Expected: first returns multi-line ZKTeco text (`GET OPTION FROM: AKK0122578806`, `Stamp=0`, …); second returns 403 (or, if `APP_ENV=local`, a 200 with a logged warning — note which, since the cloud runs `production` where it MUST be 403).

- [ ] **Step 4: Confirm a pushed punch is raw-logged before the ack**

```bash
# Simulate one ATTLOG line for a known device user id (replace 1 with a real device_user_id)
curl -s -X POST "http://127.0.0.1:8000/iclock/cdata?SN=AKK0122578806&table=ATTLOG" \
  --data-binary $'1\t2026-06-24 07:07:07\t0\t1\t\n'
# Verify it landed in the append-only raw log
"/c/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe" -u root --host=127.0.0.1 madmen \
  -e "SELECT device_user_id,horodatage,decision FROM k40_punch_brut WHERE horodatage='2026-06-24 07:07:07';"
```
Expected: the POST replies `OK: 1`; the row exists in `k40_punch_brut`. Then delete the test row: `DELETE FROM k40_punch_brut WHERE horodatage='2026-06-24 07:07:07';` and restore the local `.env` to its original `K40_MODE`.

- [ ] **Step 5: Commit (only if any verification doc/notes were added; otherwise skip — no code changed)**

No code change expected in this task. If you added notes to the spec/plan, commit them:
```bash
git add docs/ && git commit -m "docs(k40-push): pre-flight verification notes"
```

---

### Task 2: Cloud app config + deploy

**Files:**
- Modify (server, not in repo): `/opt/madmen/madmen-api-php/.env` on `89.167.42.121`.
- Run: `m.sh` (ships all data-loss fixes + mounts the iclock route).

**Interfaces:**
- Consumes: Task 1's confirmation that the handshake works under `K40_MODE=both`.
- Produces: a live cloud handshake at `https://api-madmen.ssmanager.uk/iclock/cdata?SN=AKK0122578806` (proof the route is mounted; the device itself will use the HTTP edge from Task 3).

- [ ] **Step 1: Prepare the exact `.env` diff (show it before applying)**

Add/ensure in the cloud `.env`:
```
K40_MODE=both
K40_PUSH_SN=AKK0122578806
```
Confirm unchanged: `APP_ENV=production`, `AUTH_ENABLED=true`. If `K40_ROLE` exists, keep it as is (it gates pull routes only).

- [ ] **Step 2: Apply on the server and redeploy**

On the server (Termius):
```bash
sudo nano /opt/madmen/madmen-api-php/.env   # add the two K40_ lines
curl -fsSLo m.sh https://paste.rs/Gjhye && sudo bash m.sh
```
Expected: build succeeds, migrations run (incl. 057/058 from the data-loss work), containers healthy.

- [ ] **Step 3: Verify the route is mounted (over HTTPS, for the check only)**

```bash
curl -s "https://api-madmen.ssmanager.uk/iclock/cdata?SN=AKK0122578806"
curl -s -o /dev/null -w "blank SN -> HTTP %{http_code}\n" "https://api-madmen.ssmanager.uk/iclock/cdata?SN="
```
Expected: handshake text for the valid SN; **403** for blank SN (proves the production fail-closed guard is active). If the valid SN also returns 403, `K40_PUSH_SN` didn't take — recheck `.env` + redeploy.

- [ ] **Step 4: Commit**

No repo change (server-side `.env` only). Record the deployed commit hash in the plan checkbox notes.

---

### Task 3: Edge — plain-HTTP `/iclock` listener on the shared Caddy

**Files:**
- Modify (server, not in this repo): `/opt/ssm-cloud/deploy/Caddyfile.prod` and the `ssm-cloud` compose file that runs `ssm-caddy` (to publish port 8090).

**Interfaces:**
- Consumes: Task 2's mounted route on `madmen-api:8000`.
- Produces: `http://89.167.42.121:8090/iclock/cdata?SN=AKK0122578806` returns the handshake (no HTTPS redirect); any non-`/iclock` path on 8090 returns 404; bad SN returns 403.

- [ ] **Step 1: Confirm `ssm-caddy` can reach `madmen-api` and see how ports are published**

```bash
sudo docker network inspect ssm-cloud_ssm | grep -A2 -E "madmen-api|ssm-caddy"
sudo docker inspect ssm-caddy --format '{{json .HostConfig.PortBindings}}'
```
Expected: both containers on `ssm-cloud_ssm`; note Caddy's current published ports (likely 80/443).

- [ ] **Step 2: Publish port 8090 on the Caddy container (show the compose diff first)**

In the `ssm-cloud` compose service for `ssm-caddy`, add under `ports:`:
```yaml
      - "8090:8090"
```
Apply (brief Caddy restart — warn that all sites blip for a few seconds):
```bash
cd /opt/ssm-cloud && sudo docker compose up -d ssm-caddy
```
Expected: `ssm-caddy` recreated with 8090 published; existing HTTPS sites still up.

- [ ] **Step 3: Add the plain-HTTP `/iclock` block to the Caddyfile (show the diff first)**

Append to `/opt/ssm-cloud/deploy/Caddyfile.prod`:
```
# ── MadMen K40 ADMS push — plain HTTP, /iclock ONLY, no redirect ──
:8090 {
	@iclock path /iclock /iclock/*
	handle @iclock {
		reverse_proxy madmen-api:8000
	}
	handle {
		respond "Not found" 404
	}
}
```
(`:8090` = bare port → Caddy serves plain HTTP with no auto-HTTPS/redirect.) Reload without downtime:
```bash
sudo docker exec -w /etc/caddy ssm-caddy caddy reload --config /etc/caddy/Caddyfile
```
Expected: `caddy reload` success.

- [ ] **Step 4: Verify from OUTSIDE the office network (phone hotspot / cloud shell)**

```bash
curl -s "http://89.167.42.121:8090/iclock/cdata?SN=AKK0122578806"          # -> handshake text
curl -s -o /dev/null -w "%{http_code}\n" "http://89.167.42.121:8090/iclock/cdata?SN="   # -> 403
curl -s -o /dev/null -w "%{http_code}\n" "http://89.167.42.121:8090/api/employes"        # -> 404 (not exposed)
curl -s -o /dev/null -w "%{redirect_url} %{http_code}\n" "http://89.167.42.121:8090/iclock/cdata?SN=AKK0122578806"  # no redirect
```
Expected: handshake; 403; 404; no `Location`/redirect. If `/api/*` is reachable on 8090, the `handle` fallthrough is wrong — fix before continuing.

- [ ] **Step 5: Commit**

Server-side only. Save the final Caddy snippet + compose diff into this repo for the record:
```bash
cd C:/dev/madmen/madmen-api-php
mkdir -p deploy/edge && printf '%s\n' '<paste the :8090 Caddy block here>' > deploy/edge/caddy-iclock.txt
git add deploy/edge/caddy-iclock.txt && git commit -m "docs(k40-push): record edge Caddy /iclock snippet"
```

---

### Task 4: Configure the K40 device (ADMS / Cloud Server)

**Files:** None (on-device menu).

**Interfaces:**
- Consumes: the working edge from Task 3 (`89.167.42.121:8090`).
- Produces: the device dials out and registers (a fresh punch reaches the cloud).

- [ ] **Step 1: Note the device clock is correct first (avoids future-dated alerts)**

On the device: `Menu → System → Date/Time` — confirm it matches real local time (we measured 2 s skew, fine). If off, set it.

- [ ] **Step 2: Enter the ADMS / Cloud Server settings**

`Menu → Comm → Cloud Server Setting` (a.k.a. ADMS / "Webserver"):
- **Server Address:** `89.167.42.121` (use the IP — the firmware may not resolve domain names; leave "Enable Domain Name" OFF unless the field requires a hostname).
- **Server Port:** `8090`
- **Enable Proxy Server:** OFF
- **HTTPS/Encrypt:** OFF
- **Realtime (Real-Time upload):** ON
Save and exit. Some firmwares require a reboot: `Menu → System → Reset` is NOT needed — a power-cycle is enough if it doesn't connect.

- [ ] **Step 3: Verify the device connected**

On the device, the comm/cloud status icon should show connected; OR check the server received the handshake:
```bash
# On the server, tail the API logs for an iclock hit from this SN
sudo docker logs --since 2m madmen-api 2>&1 | grep -i iclock | tail
```
Expected: a `GET /iclock/cdata?SN=AKK0122578806` (handshake) and/or `POST /iclock/cdata` appears shortly after enabling.

---

### Task 5: Recover buffered punches + live end-to-end test

**Files:** `database/k40_sync.php` (fallback, run once on a LAN machine).

**Interfaces:**
- Consumes: device pushing (Task 4).
- Produces: this morning's 5 punches present in `pointage`/`k40_punch_brut` with no duplicates; a brand-new punch appears in the cloud within seconds with the office PC OFF.

- [ ] **Step 1: Ingest this morning's buffered punches (belt-and-suspenders)**

From a LAN machine (the K40 still holds them; never purged):
```bash
cd C:/dev/madmen/madmen-api-php
php database/k40_sync.php
```
OR rely on the device resending its buffer on the ADMS Stamp=0 handshake once push is live. Either way, verify (against the CLOUD DB via Adminer `https://db-madmen.ssmanager.uk` or the dashboard):
- the morning punches are present, and
- counts are not doubled (dedup by `client_uuid`).

- [ ] **Step 2: Live test with the office PC OFF**

Power off the office admin PC. Clock in on the K40. Within a few seconds, check the cloud:
```bash
curl -s -H "Authorization: Bearer <API_KEY>" "https://api-madmen.ssmanager.uk/api/dashboard/presence" | head
```
OR look in the dashboard / `pointage_passage`. Expected: the new punch is there, exactly once, with the PC off.

- [ ] **Step 3: Confirm no future-dated / saturation alerts were raised spuriously**

```bash
"/c/Program Files/MySQL/MySQL Server 8.4/bin/mysql.exe" -u root --host=127.0.0.1 madmen \
  -e "SELECT type, message, horodatage FROM alerte WHERE type IN ('k40_horloge','k40_saturation') ORDER BY id DESC LIMIT 5;"
```
(Run against the CLOUD DB if attendance now lands there directly.) Expected: none from this test (clock is correct, buffer tiny).

---

### Task 6: Confirm the PULL fallback still works

**Files:** `database/k40_sync.php`, the local server stack.

**Interfaces:**
- Consumes: nothing new.
- Produces: confirmation that a manual PULL still ingests + dedups cleanly, so push and pull coexist (`K40_MODE=both`).

- [ ] **Step 1: Run a manual pull and confirm idempotent dedup**

From a LAN machine:
```bash
cd C:/dev/madmen/madmen-api-php
php database/k40_sync.php
php database/k40_sync.php   # run twice
```
Expected: second run reports `traités: 0` (everything already ingested) and creates no duplicate rows. This proves push + pull can't double-count (shared `client_uuid` dedup).

- [ ] **Step 2: Document the rollback path in the runbook**

Append to `serveur/LISEZ-MOI.md`: to revert to pull-only — set `K40_MODE=pull` in the cloud `.env`, redeploy, remove the `:8090` Caddy block + reload, and disable Cloud Server on the device.
```bash
git add serveur/LISEZ-MOI.md && git commit -m "docs(k40-push): rollback notes (push -> pull)"
```

---

## Self-Review

- **Spec coverage:** §1 cloud config → Task 2; §2 edge Caddy → Task 3; §3 device → Task 4; §4 recover punches → Task 5.1; §5 e2e + fallback → Tasks 5 & 6; security model (SN allowlist/port/cap) → Tasks 2–3; data-loss safety already shipped → verified in Task 1.4. All covered.
- **Placeholders:** `<API_KEY>` (Task 5.2) and `<paste …>` (Task 3.5) are operator-supplied secrets/captures by design, not unspecified logic. Port `8090`, SN, IP, container names are all concrete.
- **Consistency:** `8090`, `89.167.42.121`, `AKK0122578806`, `madmen-api:8000`, `ssm-caddy` used identically throughout.
