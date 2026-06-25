# Attendance Rules — Design Spec

**Date:** 2026-06-25
**Status:** Proposed (awaiting review)

## Goal

Refine the daily attendance computation with two corrections and one new metric:

1. **Work-time from start** — paid time counts only from the scheduled start (`début`), even if the worker clocked in earlier. *(Already implemented — documented for completeness.)*
2. **Retard déjeuner** — a fixed per-worker lunch window requires clock-out/clock-in; returning after the fixed window end (`pause_fin`) counts as lateness, tracked in a counter **separate** from morning lateness.
3. **Temps manquant** — a general daily "missing work time" = scheduled net hours − hours actually worked, computed every day.

## Context (current state)

- Schedule is **per-worker** (`horaire_employe`: `heure_arrivee`=début, `heure_depart`=fin, `pause_debut`/`pause_fin`=lunch window, `tolerance_minutes`, `avance_minutes`, `jours_travailles`, `planning` JSON per-day début/fin). Falls back to a global default in `config/presence.php`.
- Punches are stored as `pointage_passage` rows (entrée/sortie/entrée…). `K40Pointage::record` recomputes the daily `pointage` row on each punch via `resumeJournee`.
- `Presence::presenceMinutes(entrée, sortie, horaire)` = worked minutes bounded to `[début, fin]` minus the lunch-window overlap. **It already does `max(arrivée, début)`** → rule (1) holds today.
- `pointage.retard_minutes` = morning lateness only, via `retardDansFenetre` on the first entrée.
- Per-worker schedule editor: `ConfigHoraire.jsx` → `PUT /api/employes/{id}/horaire` (`HoraireController::upsert`).

## Rules (precise)

### Rule 1 — work-time from début (no logic change)

`presenceMinutes` already clamps start to `max(arrivée, début)` and end to `min(sortie, fin)`. A 06:00 clock-in on an 08:00 schedule counts from 08:00.
**Action:** confirm no early-punch filter rejects the morning clock-in — `Presence::estTropTot` must NOT be applied in the punch-recording path; early arrivals are still recorded as *présent*. (This was already removed once; re-verify.) No new logic expected.

### Rule 2 — retard déjeuner (new counter `retard_dejeuner_minutes`)

The lunch window `[pause_debut, pause_fin]` is fixed per worker. The **return deadline is `pause_fin`**, independent of when the worker actually left.

`Presence::retardRetourDejeuner(array $passages, string $date, ?array $horaire): int`
```
if pause_debut or pause_fin is null            -> 0
finTs   = timestamp(date + ' ' + pause_fin)
before  = passages with horodatage <= finTs    (sorted by horodatage)
if before is empty                             -> 0   # not present at pause_fin (e.g. arrived later)
if before.last.type != 'sortie'                -> 0   # clocked IN at pause_fin (on time / worked through)
firstReturn = first passage with horodatage > finTs AND type == 'entree'
if firstReturn is null                         -> 0   # never returned (covered by Rule 3)
return floor((firstReturn.horodatage - finTs) / 60)
```
Examples (pause_fin 14:00): left 12:40 / back 14:05 → **5** · back 13:55 → **0** · worked through (no lunch sortie) → **0** · never returned → **0** (see Rule 3).
The "clocked-out-at-`pause_fin`?" formulation isolates the lunch break even when other pauses (café, toilette) exist.

### Rule 3 — temps manquant (new counter `temps_manquant_minutes`)

General daily deficit between scheduled net hours and hours worked.

`Presence::tempsManquant(int $tempsPresentMinutes, ?array $fenetre, ?array $horaire): int`
```
if jour de repos (no fenetre)        -> 0
spanMin  = (fin - début) in minutes              # from the day's fenêtre
lunchMin = (pause_fin - pause_debut) if both set, else 0
prevuNet = max(0, spanMin - lunchMin)
return max(0, prevuNet - tempsPresentMinutes)
```
Example: 08:00–18:00, lunch 1h30 → prévu net **8h30**. In 08:00, left 12:30, no return → worked 4h30 → **temps manquant 4h00**. Full normal day → 0.
A late arrival (or early departure) also surfaces here in addition to `retard_minutes` — different lenses, intentional and accepted by the user.

## Data model

Migration `061_add_retard_dejeuner_temps_manquant_to_pointage.php`:
```sql
-- up
ALTER TABLE pointage
  ADD COLUMN retard_dejeuner_minutes INT NOT NULL DEFAULT 0 AFTER retard_minutes,
  ADD COLUMN temps_manquant_minutes  INT NOT NULL DEFAULT 0 AFTER retard_dejeuner_minutes;
-- down
ALTER TABLE pointage
  DROP COLUMN temps_manquant_minutes,
  DROP COLUMN retard_dejeuner_minutes;
```

## Backend

- New pure, unit-testable methods on `Presence`: `retardRetourDejeuner(...)` and `tempsManquant(...)`.
- `K40Pointage::resumeJournee` returns the two new values (it already receives the passages + the horaire); `K40Pointage::record` writes them in both the INSERT and the UPDATE of `pointage`.
- **Consistency requirement:** the day's expected span used by `tempsManquant` must use the SAME basis as `presenceMinutes` — the per-day fenêtre `début/fin` when a `planning` JSON exists, otherwise the single horaire — so `worked + manquant` reconcile. Resolve the exact wiring in the plan.
- `PointageController` (and any presence/dashboard endpoint returning a pointage row) include the two new fields.

## Config

- `config/presence.php`: default lunch window → `dejeuner_debut` `12:30`, `dejeuner_fin` `13:30` → **`14:00`** (1h30). Per-worker `pause_debut`/`pause_fin` continue to override.
- `ConfigHoraire.jsx`: ensure the lunch-window fields are present and editable per worker. Verify; add if missing.

## Relay / cloud

Reporters push RAW punches to `/iclock`; the cloud's `K40Pointage` recomputes the `pointage` row → both new metrics are computed cloud-side automatically. Verify the older bureau→cloud pointage relay (`RelaisCloud` + `GatewayController`) either carries the new columns or relies on cloud recomputation (must not overwrite the recomputed row with stale zeros).

## Frontend display

- `lib/mappers.js`: expose `retard_dejeuner_minutes` + `temps_manquant_minutes`.
- Surface both distinctly wherever lateness / hours appear: `RecapMensuel`, the day view (`CalendrierPresence` / `PointageJourModal`), the agent cards (`CarteAgent` / `CartePresence` / `BandeauAgent`), `CartePerformance`. Labels: « Retard déjeuner », « Temps manquant ». Exact placement in the plan.

## Edge cases

- No lunch window set → `retard_dejeuner` = 0; `temps_manquant` uses lunch = 0.
- Jour de repos → both 0.
- Multiple breaks in a day → the clocked-out-at-`pause_fin` rule isolates the lunch break.
- Live (still out, before `pause_fin`) → `retard_dejeuner` stays 0 until an actual return after the deadline.

## Testing

PHPUnit tests for both new `Presence` methods: on-time return, late return, early return, worked-through, never-returned, late arrival, early departure, jour de repos, no-lunch-window. Mirror the existing Presence test structure if present.

## Out of scope

- Live "currently overdue from lunch" dashboard indicator (compute-on-return only).
- Changes to how worked-time feeds payroll (unchanged).
- Per-DAY lunch windows (lunch stays a single per-worker window).
