# Distributed K40 Reporter — Design

**Date:** 2026-06-24
**Status:** Approved in concept (user); awaiting spec review.

## Problem
The office K40 clock can't push to the internet (its firmware has no ADMS/Cloud-Server
feature — confirmed on-device), and the cloud can't reach into the office LAN (NAT). So
*something on the office network* must read the clock and forward punches to the cloud.
A single bridge PC is fragile: if the admin's PC is off / travels, or a bad-faith worker
unplugs it, forwarding stops. We need a forwarder with **no single point of failure**,
**no new hardware**, and that a worker can't quietly defeat.

## Decision
Turn **every office PC into a potential reporter** by embedding a quiet background
"reporter" in the **MADMEN User** app (installed on every office PC). At any moment only
**one** reporter is on duty (coordinated by the cloud); if it goes offline, another takes
over within ~1 minute. No data is ever lost (the K40 buffers ~80k punches; the cloud
append-only raw log keeps everything; duplicates are discarded by `client_uuid`).

## Why MADMEN User (not WatchMEN / MadMen Studio)
- **MADMEN User** (`mad_man-user`, `com.madmen.employe`) is on **every** office PC → max
  redundancy. It's a normal windowed app today, so we add: auto-start at login + start
  hidden in the system tray + the background reporter (Windows-desktop only).
- **WatchMEN** kiosk (`app-en-mode-kiosque`) already runs in the background and may also
  be present; it can host the **same** reporter module as extra coverage (optional, see
  Open Questions).
- **MadMen Studio** (admin) is admin-PC only → not a reporter host.
- Mobile builds of MADMEN User are **out of scope** (no LAN reach to the clock).

## Verified context (from the codebase)
- MADMEN User: Tauri 2.11.3, Rust 1.77, logic in `src-tauri/src/lib.rs` (`app_lib::run()`),
  already has `tauri-plugin-http`. No autostart/tray yet.
- The K40 is read today by PHP via a **pyzk** Python bridge (`scripts/k40_push_template.py`)
  — proven working against `192.168.1.201:4370`.
- Cloud already has: `K40Pointage::record()` (writes append-only `k40_punch_brut` FIRST,
  dedup via deterministic `client_uuid`), the `/iclock` receiver, `RelaisCloud`/
  `GatewayController` relay, and `GET /api/k40/push-status` (raw-log health).
- The plain-HTTP edge (`madmen-edge`, port 8090) and the K40 ADMS attempt are **no longer
  needed** — reporters are real computers and push over HTTPS. (Retire the edge.)

## Architecture / data flow
```
K40 (192.168.1.201, office LAN)
   ▲ read (ZK protocol)            every office PC runs MADMEN User (hidden, autostart)
   │                                each = a standby reporter
   └── on-duty reporter ──HTTPS──► cloud  POST /api/relay/punches
                                     → K40Pointage::record() → k40_punch_brut (dedup) → pointage
   coordination:  reporter ──HTTPS──► cloud POST /api/relay/claim  (atomic 60s lease)
   alert:         cloud notices no relay for N min → email admin + dashboard banner
```

## Components

### 1. Clock-reader sidecar (bundled with MADMEN User, Windows)
- A self-contained binary `k40-reader.exe` (PyInstaller-packaged pyzk script) — reuses the
  proven `k40_push_template.py` read logic. Input: K40 IP/port. Output: JSON array of raw
  punches `[{device_user_id, timestamp}]` read from the device buffer (non-destructive;
  the device is never cleared). Bundled as a Tauri **sidecar** (`externalBin`), Windows only.
- *Proven, low-risk baseline.* A Rust-native ZK reader is a possible later optimization
  (removes the Python bundle) — see Open Questions; not required for v1.

### 2. MADMEN User background reporter (Rust, `lib.rs`, Windows-desktop only)
- Add `tauri-plugin-autostart` (launch at login) + a system tray; the window starts hidden
  (employee opens it from the tray when needed). Quiet by design.
- Background loop every ~30–60 s (with small random jitter):
  1. `POST /api/relay/claim` → if `granted=false`, sleep and loop (another reporter is on duty).
  2. If `granted=true`: run `k40-reader.exe` → get punches → `POST /api/relay/punches`.
  3. Release/renew the lease.
- Only active when the PC can reach the K40 LAN; if not (e.g. laptop off-site), it simply
  never wins/uses a lease — harmless.

### 3. Cloud coordination — atomic lease
- New migration: `relay_lease` (`id` singleton, `holder VARCHAR`, `lease_until DATETIME`).
- `POST /api/relay/claim` (auth: relay token): atomic grant via conditional UPDATE
  (`SET holder=?, lease_until=NOW()+INTERVAL 60 SECOND WHERE lease_until < NOW() OR holder=?`).
  Returns `{granted: bool, lease_until}`. Exactly one holder at a time; auto-expires if a
  holder dies. Lease loss never causes data loss — dedup is the backstop.

### 4. Cloud ingest — raw punches
- `POST /api/relay/punches` (auth: relay token): body = `[{device_user_id, timestamp}]`.
  For each, call `K40Pointage::record()` (→ append-only raw log first, dedup by `client_uuid`,
  then mapping/filtering). Returns counts `{recus, appliques}`. Idempotent: re-sending the
  same punches is safe (no duplicates).

### 5. Cloud alert — "office went quiet" (audit residual #6)
- Track `last_relay_at` (updated on each successful `/api/relay/punches`).
- **Dashboard banner** (no scheduler): on dashboard load, if `NOW() - last_relay_at` exceeds
  N minutes during work hours, show a red "Office offline since HH:MM" banner.
- **Email** (proactive): a small scheduled check (cron in the cloud stack) hits an internal
  endpoint; if silent > N min during work hours, email the admin once per incident. (SMTP
  config required; banner works without it.)

## Security / integrity
- Reporters can **only add** punches (append-only); they cannot delete or overwrite — there
  is no delete path in this flow (hard or soft). A bad-faith worker can't erase attendance.
- All reporters read the **same** clock; identical punches collapse to one row via
  `client_uuid`. No per-computer data, no overwrites.
- Relay token (like `GATEWAY_TOKEN`) authenticates `/api/relay/*`; HTTPS end-to-end.
- "Hidden" = a quiet background tray app, doing only the company's attendance relay on the
  company's own machines — it touches only K40 attendance, nothing personal.

## Out of scope
- Mobile reporter (no LAN reach). Employee/PIN sync (managed centrally in the cloud).
- Replacing the existing local gateway for offices that prefer it — this is additive.

## Rollback
- Disable the reporter via a config flag (`REPORTER_ENABLED=false`) pushed to the apps, or
  ship a build with it off. Cloud endpoints are passive (no caller = no effect). The K40
  buffer + existing relay remain as fallback.

## Done when
- With the admin PC **off** and any one office PC unplugged, a fresh clock-in still appears
  in the cloud within ~1–2 minutes, **exactly once**.
- Two reporters briefly overlapping produce **no duplicate**.
- If **all** reporters go silent during work hours, the admin gets the banner (+ email if
  configured) within N minutes.

## Open questions (to confirm in review)
1. Is **WatchMEN** also on every PC? If yes, host the same reporter there too for extra
   coverage; if it's only on a few clock terminals, MADMEN User alone is the host.
2. Email alert: is SMTP available on the cloud, or start with the dashboard banner only?
3. v1 clock-reader = bundled pyzk sidecar (proven). Spike a Rust-native reader later to drop
   the Python bundle? (optimization, not a blocker.)
