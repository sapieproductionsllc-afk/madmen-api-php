# Team-Leader Permissions (Project B) — Design

- **Date**: 2026-06-30
- **Scope**: MadMen Admin — `madmen-api-php` (data + enforcement) + `madmen-front-react-js` (hide what's not allowed) + super-admin matrix UI
- **Status**: design approved, pending implementation
- **Depends on**: Project A (super-admin foundation) — DONE
- **Out of scope**: per-person overrides (this is PER-ROLE), audit trail of permission changes (future project)

## 1. Objective
Let the super-admin **limit what each team-leader role can see and do**, from the UI, replacing today's hardcoded role-rank rules in `Auth.php`. Per **role** (Directeur, Superviseur). Each app **area** gets one level: **None** (hidden) · **Voir** (read-only) · **Gérer** (view + add/edit/approve/delete). Enforced on the backend (source of truth) and reflected on the frontend (hide what's not allowed). **Changes take effect immediately** (checked per request — no re-login).

## 2. Roles
- `super_admin` → **always all** (never stored, bypasses every check).
- `directeur`, `superviseur` → **configurable** (the "team leaders" who log into the dashboard).
- `employe` → **none** on the dashboard (workers use the kiosk only).

## 3. Areas (8) and level meaning
| Area key | Voir | Gérer |
|---|---|---|
| `presence` | live presence / dashboard | (view only) |
| `employes` | see profiles | create/edit/delete, biometrics |
| `pointages` | see timesheets & schedules | correct punches, set schedules |
| `paie` | see salaries & payslips | edit salaries, record payments, adjustments |
| `rapports` | open & print reports | (view only) |
| `demandes` | see leave/requests | approve/refuse, set/end vacation |
| `communication` | read messages/announcements | post announcements, send broadcast |
| `administration` | (n/a) | devices, K40, settings, jours fériés, postes |

Ordering: `gerer ≥ voir ≥ none`. (`administration` is effectively None/Gérer; managing accounts/admins stays `super_admin`-only regardless.)

## 4. Data model
- **`role_permission(role VARCHAR(20), area VARCHAR(20), niveau ENUM('none','voir','gerer') NOT NULL DEFAULT 'none', PRIMARY KEY(role, area))`** — tiny (2 roles × 8 areas = 16 rows).
- **Idempotent seed (migration 067)** — default levels (≈ today's behavior, sensitive areas locked until granted):

  | Area | Directeur | Superviseur |
  |---|---|---|
  | presence | voir | voir |
  | employes | voir | voir |
  | pointages | gerer | gerer |
  | paie | **voir** | **none** |
  | rapports | voir | voir |
  | demandes | gerer | gerer |
  | communication | gerer | voir |
  | administration | **none** | **none** |

  (Employés = Voir for both → only super-admin manages employees until the super-admin raises it to Gérer.) Seed uses `INSERT ... ON DUPLICATE KEY UPDATE niveau=niveau` (never overwrites a value the admin already set).

## 5. Backend enforcement (replaces hardcoded ranks)
- **`Core/Permissions`**: `niveau(role, area): string` (per-request cached read of `role_permission`); `peut(role, area, requis): bool` (`super_admin` always true; else compare on the `none<voir<gerer` scale).
- **Route → (area, requiredLevel) resolver** (a map analogous to today's `requiredRank`): e.g.
  - `GET /api/employes*` → (employes, voir); `POST|PUT|PATCH|DELETE /api/employes*`, `/api/biometrie*` → (employes, gerer)
  - `GET /api/pointages*`, `/api/employes/{id}/horaire|presence` → (pointages, voir); writes → (pointages, gerer)
  - `GET /api/paie`, `/api/employes/{id}/paie`, `/api/salaire*` → (paie, voir); writes → (paie, gerer)
  - `GET /api/rapports/*` → (rapports, voir)
  - `GET /api/demandes` → (demandes, voir); `POST /api/demandes`, `/api/demandes/{id}/decision`, `/api/employes/{id}/conge` (set/end) → (demandes, gerer)
  - `/api/conversations|messages|annonces` reads → (communication, voir); writes/broadcast → (communication, gerer)
  - `/api/config*|k40*|appareils*|jours-feries*|postes (write)|parametres` → (administration, gerer)
  - `GET /api/dashboard/*` → (presence, voir)
- **`Auth::guard`**: public/kiosk/gateway routes unchanged. For an authenticated JWT user: `super_admin` bypasses; otherwise resolve the route's (area, level) and require `Permissions::peut(role, area, level)`, else 403. (`/api/administrateurs` + account-management writes stay `super_admin`-only as in Project A.)
- **`GET /api/me/permissions`** → the caller's effective `{area: niveau}` map (super_admin → all `gerer`). Drives the frontend.
- **Super-admin matrix endpoints** (super_admin only): `GET /api/role-permissions` (full matrix for directeur+superviseur), `PUT /api/role-permissions` `{ role, area, niveau }` (or bulk) → upsert.

## 6. Frontend (hide what's not allowed)
- **AuthContext**: after login, fetch `/api/me/permissions` into context; expose `peut(area, level)`. Refetch on app load (so changes apply on next session/refresh; backend already enforces live).
- **SideNav**: hide any area whose level is `none`.
- **Routes**: a `PermissionRoute` wrapper (or per-page guard) redirects a blocked area to the user's **first allowed area** (and if the user has access to nothing, a clear "Accès refusé — contactez votre administrateur" screen). The post-login landing route is likewise the first allowed area, not a hardcoded dashboard.
- **Actions**: gate add/edit/approve/delete/print-manage buttons behind `peut(area, 'gerer')` — a `Voir` leader sees data but no management controls.
- **Administration → Rôles & permissions**: a matrix (rows = areas, columns = Directeur/Superviseur, each cell a None/Voir/Gérer select) bound to `GET/PUT /api/role-permissions`; saving applies immediately.

## 7. Safety / edge cases
- Backend is the **source of truth**; frontend hiding is convenience only.
- The super-admin's own access is **never** restricted; account/admin management stays super-admin-only.
- A leader hitting a forbidden route directly → frontend redirect + backend 403 on the API call.
- Unknown/unmapped routes default to a safe required level (deny for management writes; allow only known read areas).
- A missing `role_permission` row is treated as `none`.

## 8. Acceptance / tests
- Seed creates the 16 rows on a virgin DB; re-migrate doesn't overwrite admin-set values.
- A Superviseur with `paie=none`: sidebar hides Finance & Paie, `/finance` redirects, `GET /api/paie` → 403.
- Raising Superviseur `employes` to `gerer` makes the "Modifier/Supprimer" controls appear and `PUT /api/employes/{id}` succeed — **without re-login**.
- `super_admin` sees and can do everything regardless of the matrix.
- Lowering a permission while a leader is logged in blocks the next API call immediately.
