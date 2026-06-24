# K40 attendance: migrate PULL → PUSH (ADMS) — Design

**Date:** 2026-06-24
**Status:** Approved (design) — security trade-off accepted for now, to harden later.

## Problem
Attendance currently depends on an always-on office PC running a 30-second PULL loop
(`/api/k40/sync` → pyzk → cloud). If that PC is off, punches sit in the K40 and never
reach the app (happened this morning: 5 punches stranded). The data-loss audit also
confirmed the **last** open risk (#6): if the PULL loop is down long enough for the
K40's 80 000-record buffer to FIFO-overwrite, the oldest punches are lost — and this is
inherent to the pull model.

## Decision
Make the **K40 PUSH each punch straight to the cloud in real time** (ZKTeco ADMS /
`iclock`). The K40 dials OUT, so NAT/CGNAT is no obstacle and no always-on PC or local
gateway box is required. This also **closes residual #6** (no sync loop to stall; the
device offloads continuously, so the buffer never accumulates).

## Verified facts (checked live against device + code, do not re-trust blindly)
- Device: ZKTeco K40, LAN `192.168.1.201:4370`, SN **`AKK0122578806`**, firmware
  **Ver 6.60 (Sep 2019)**, platform **ZLM60_TFT**, 14 users, gateway `192.168.1.254`.
- **HTTP-only**: the ZLM60_TFT / 2019 firmware has no TLS client. ADMS cannot use
  `https://`. We MUST expose a plain-HTTP `/iclock` endpoint at the edge.
- Office public IP today: `102.141.33.0` — almost certainly **CGNAT/dynamic**, so a
  source-IP firewall lock is NOT reliably available (matches the prompt's assumption).
- Cloud: `https://api-madmen.ssmanager.uk` (server `89.167.42.121`), behind an EXTERNAL
  shared Caddy (`ssm-cloud_ssm` network; Caddyfile at `/opt/ssm-cloud/deploy/Caddyfile.prod`,
  reloaded via `docker exec ssm-caddy caddy reload`). Plain HTTP currently 308-redirects
  to HTTPS — the K40 would NOT follow that, so the `/iclock` HTTP path must NOT redirect.
- Code already present (confirmed): `K40PushController` implements `/iclock/cdata`
  (handshake + receive), `/iclock/getrequest`, `/iclock/devicecmd`; verifies `?SN=`
  against `K40_PUSH_SN`; fail-closed in `APP_ENV=production`; reuses `K40Pointage::record()`.
  Routes mounted only when `K40_MODE` ∈ {push, both} (`public/index.php`).
- **Data-loss safety already shipped** (this session): `record()` writes every punch to
  the append-only `k40_punch_brut` log BEFORE mapping/filter/ack; dedup via `client_uuid`;
  8 MB body cap; `post_max_size=16M`. So the PUSH path cannot drop a real punch.

## Architecture / data flow (after migration)
```
K40 (LAN 192.168.1.201)  --dials out-->  office router (CGNAT)  -->  internet
   -->  ssm-caddy :HTTP_PORT  (no redirect, /iclock/* only)  -->  madmen-api:8000
   -->  K40PushController::receive() --> K40Pointage::record() --> cloud DB
   -->  dashboard reads cloud DB
```
No local bridge in the attendance path. The PULL path stays as a manual fallback.

## Components / steps (verify after each; nothing on the server without showing diffs first)

### 1. Cloud app config + deploy
- `.env`: `K40_MODE=both`, `K40_PUSH_SN=AKK0122578806`, keep `APP_ENV=production`
  (fail-closed SN guard) and `AUTH_ENABLED` unchanged. Confirm `K40_ROLE` does not
  mount the pull routes if that's the intent.
- Deploy with `m.sh` (ships all the data-loss fixes too): `sudo bash m.sh`.
- Verify: `GET /iclock/cdata?SN=AKK0122578806` returns the ZKTeco text handshake;
  blank/unknown SN returns 403.

### 2. Edge: plain-HTTP `/iclock` on the shared Caddy (SSH, show snippet first)
- Add a plain-HTTP listener on a **non-standard port** that reverse-proxies ONLY
  `/iclock/*` to `madmen-api:8000`, with rate-limiting, and NO redirect to HTTPS.
  Leave every other path HTTPS-only. The `ssm-caddy` container must publish that port.
- Verify from outside: `GET http://89.167.42.121:<PORT>/iclock/cdata?SN=AKK0122578806`
  → handshake; bad SN → 403; any non-`/iclock` path over that HTTP port → not served.

### 3. Configure the K40 (on-device menu — click-by-click provided)
- `Menu → Comm → Cloud Server / ADMS`: Server address = `89.167.42.121` (use the IP if
  "Enable Domain Name" is unsupported), Port = `<PORT>`, Realtime ON, Encrypt OFF.

### 4. Recover this morning's punches
- Run the pull fallback once from a LAN machine (`php database/k40_sync.php`) to ingest
  the 5 buffered punches, OR confirm the device resends buffered logs on Stamp=0 once
  push is live. Verify they land with no duplicates (dedup by `client_uuid`).

### 5. End-to-end verification + keep the fallback
- Clock in on the K40 with the office PC OFF → punch appears in the cloud app within
  seconds, no duplicate. Confirm `database/k40_sync.php` still works as recovery.

## Security model (accepted trade-off)
Plain HTTP + a non-secret serial = forged punches are *possible*. Mitigations in place:
SN allowlist + production fail-closed + rate-limit + 8 MB cap + a non-standard/obscure
port. CGNAT means no reliable source-IP firewall today. **Accepted for now; revisit
later** (options: confirm a static IP with the ISP and firewall to it; or a tiny
always-on local relay that keeps the punch ingress off the public internet).

## Out of scope
- **Enrollment** (pushing a new fingerprint TO the K40) still needs LAN access or
  on-device enrollment — PUSH only covers attendance OUT of the device.
- Deeper auth on `/iclock` (shared-secret/path token) — future hardening.

## Rollback
Set `K40_MODE=pull` (or remove the `K40_PUSH_SN`), redeploy, remove the Caddy HTTP
block + reload, and disable ADMS on the device. The PULL path is untouched throughout,
so reverting is config-only.

## Done when
A worker clocks in on the K40, the punch shows up in the cloud app within seconds, the
office admin PC fully powered off, and no duplicate rows.
