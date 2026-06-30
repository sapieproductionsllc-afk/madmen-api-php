# Super-Admin Foundation & Onboarding (Project A) — Design

- **Date**: 2026-06-30
- **Scope**: MadMen Admin — `madmen-api-php` (auth, data, endpoints) + `madmen-front-react-js` (login, admin mgmt)
- **Status**: design approved, pending implementation
- **Follow-up (separate spec)**: Project B — configurable team-leader permissions (limit what team leaders see/do)

## 1. Objective
An **invisible super-admin** account that bootstraps the system. It signs in with a **username + password** (no PIN, no biometrics, no clock-in, no attendance, and it never appears in any "employee" view). On a fresh (re)install, a default super-admin **`Admin` / `0000`** exists and **must change its password on first login**. The super-admin then creates other accounts: more **super-admins** (password) and **team leaders / workers** (matricule + PIN + biometrics + clock-in). Multiple super-admins are allowed.

Out of scope (→ Project B): configurable permissions controlling what team leaders can see/do.

## 2. Account model (keep the 4 existing roles)
| Role | Dashboard login | PIN / biometrics | Clocks in | Appears in employee/worker views |
|------|-----------------|------------------|-----------|----------------------------------|
| `super_admin` | **username + password** | no | no | **NO — invisible** |
| `directeur` / `superviseur` (team leaders) | matricule + PIN | yes | yes | yes |
| `employe` (worker) | (kiosk only) | yes | yes | yes |

The role is chosen at account creation and drives the account type. Only `super_admin` is special; everyone else is a normal clocking employee whose role sets their dashboard permission level.

## 3. Authentication
- **Two login modes on the dashboard login screen:**
  - **PIN mode (default):** matricule + 4-digit PIN — team leaders. Unchanged (`POST /api/auth/login`).
  - **Administrator mode (toggle):** username + password — super-admins. New `POST /api/auth/login-admin` `{ username, password }` → JWT (claims `sub`, `role=super_admin`).
  - Workers never log into the dashboard (they clock in at the kiosk).
- **Change password:** `POST /api/auth/changer-mot-de-passe` `{ nouveau }` (auth required) → sets `mot_de_passe_hash`, clears `doit_changer_mdp`.
- **First login (must-change):** if `doit_changer_mdp = 1`, the app forces a blocking **"Set your password"** screen before any other access.
- Password hashing: bcrypt (`password_hash`). A **chosen** password must be **≥ 8 chars** (validated on change); the temporary default `0000` is exempt (it exists only to be force-changed). JWT unchanged (HS256, `Jwt::encode`). Anti-brute-force reused (existing 429 after 5 fails / 15 min).

## 4. Data (migrations — additive, idempotent)
- `employe ADD COLUMN username VARCHAR(60) NULL UNIQUE` — login handle for super-admins (NULL for everyone else).
- `employe ADD COLUMN mot_de_passe_hash VARCHAR(255) NULL` — super-admin password (others use `code_pin_hash`).
- `employe ADD COLUMN doit_changer_mdp TINYINT(1) NOT NULL DEFAULT 0`.
- **Seed + backfill (idempotent, rerunnable):** guarantee ≥1 usable super-admin.
  - If a `super_admin` already exists but has **no `username`** (the current prod `ADMIN` account, id 1), **backfill it**: set `username='Admin'`, `mot_de_passe_hash = bcrypt('0000')`, `doit_changer_mdp=1`.
  - If **no** `super_admin` exists at all, **insert** one: `username='Admin'`, `role='super_admin'`, `mot_de_passe_hash = bcrypt('0000')`, `doit_changer_mdp=1`, `statut='actif'`, no biometrics.
  - Idempotent: only touches super-admins lacking a `username`, so re-running migrate never duplicates or resets an already-configured admin.

## 5. Account creation (by a super-admin)
- **+ Super-admin** → simple form: name, username, temporary password (typed or auto-generated), role `super_admin`, `doit_changer_mdp=1`. No matricule/PIN/biometrics. `POST /api/administrateurs`.
- **+ Team leader / Worker** → the EXISTING enrollment wizard (auto matricule, auto PIN, biometrics, attendance) + role select (directeur/superviseur/employe). Unchanged.

## 6. Super-admin invisibility
Exclude `role = 'super_admin'` from every worker-facing surface: `/api/employes` (enriched list + `?light`), `/api/dashboard/presence`, picker lists (manager dropdown, messaging recipients…), and the timesheet report (already done). Super-admins are visible/manageable ONLY under **Administration → Administrateurs** (`/api/utilisateurs?role=super_admin`, or a dedicated `/api/administrateurs`).

## 7. UI (front)
- **Login screen:** an "Connexion administrateur" toggle → username + password fields; otherwise matricule + PIN.
- **First-login screen:** "Définissez votre mot de passe" (new + confirm, rules shown), blocking until set.
- **Administration → Administrateurs:** list of super-admins (name, username, last login) + "Ajouter un administrateur" + "Réinitialiser le mot de passe" (regenerates a temporary one + forces change).

## 8. Edge cases / security
- The **last super-admin cannot be deleted or demoted** (always ≥ 1 super-admin).
- Super-admins are excluded from payroll/attendance calculations (no clock-in).
- `mot_de_passe_hash` is NEVER returned by the API.
- Username is unique (case-insensitive recommended); login is case-insensitive on username.

## 9. Acceptance / tests
- Seed creates `Admin`/`0000` must-change on a virgin DB; re-running migrate does not duplicate.
- Admin login succeeds with correct password, fails otherwise; must-change forces the password screen; after change, full access.
- `super_admin` absent from `/api/employes`, dashboard presence, and the timesheet report.
- Creating a super-admin works; "reset password" regenerates a temp + forces change; the last super-admin is not deletable.
