# Attendance Rules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two daily attendance counters — `retard_dejeuner_minutes` (late return from the fixed lunch window) and `temps_manquant_minutes` (scheduled net hours − hours worked) — to the backend computation and surface both in the dashboard, with PHPUnit tests for the two new pure functions.

**Architecture:** Two new pure static methods on `Presence` (unit-tested) are called from `K40Pointage::enregistrer` after the daily résumé is computed, and written into the `pointage` row (new migration adds the two columns). `PointageController` already uses `SELECT *`, so the API exposes them with no change. The frontend maps the two new fields and renders them. The lunch window is per-worker (`horaire_employe.pause_debut/pause_fin`); `ConfigHoraire.jsx` gains inputs for it and the company default moves to 12:30–14:00.

**Tech Stack:** PHP 8.1+ (native PDO, PSR-4 `MadMen\`), MySQL, plain-array migrations run by `php database/migrate.php`; React + Vite + Tailwind frontend; new: PHPUnit ^10.

## Global Constraints

- Lunch window is a single per-worker window `[pause_debut, pause_fin]` (NOT per-day). Default company window: **12:30–14:00**.
- Return deadline = `pause_fin` exactly: back at 14:00:00 = on time; 14:01:00 = 1 min late; minutes counted as `floor((retour − pause_fin)/60)`.
- `temps_manquant` expected span uses the SAME début/fin basis as `Presence::presenceMinutes` (the `$horaire` passed around), so `worked + manquant` reconcile.
- Both new counters are 0 on a jour de repos (`$fenetre === null`).
- Migration files are `database/migrations/NNN_name.php` returning `['up'=>'SQL','down'=>'SQL']`; run with `php database/migrate.php` (status: `php database/migrate.php status`).
- PHP runtime `>=8.1` → PHPUnit `^10` (not ^11).
- Frontend field-name convention: API `retard_minutes` → UI `retardMin`; so new fields → `retardDejeunerMin`, `tempsManquantMin`.
- Commit after every task. Do NOT push unless asked.

---

### Task 1: PHPUnit test harness

**Files:**
- Modify: `composer.json`
- Create: `phpunit.xml.dist`
- Create: `tests/SmokeTest.php`

**Interfaces:**
- Produces: a runnable `vendor/bin/phpunit`, testsuite `MadMen`, test namespace `MadMen\Tests\` → `tests/`.

- [ ] **Step 1: Add dev dependency + dev autoload + test script to `composer.json`**

Replace the file with (adds `require-dev`, `autoload-dev`, and a `test` script; everything else unchanged):
```json
{
    "name": "sapieproductions/madmen-api-php",
    "description": "API native PHP (PDO) - MadMen Module 1 : Gestion Intelligente des Employes & Controle des Postes",
    "type": "project",
    "license": "proprietary",
    "require": {
        "php": ">=8.1",
        "ext-pdo": "*",
        "ext-json": "*",
        "rats/zkteco": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10"
    },
    "autoload": {
        "psr-4": {
            "MadMen\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "MadMen\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "migrate": "php database/migrate.php migrate",
        "rollback": "php database/migrate.php rollback",
        "seed": "php database/seed.php",
        "serve": "php -S 127.0.0.1:8000 -t public public/index.php",
        "test": "phpunit"
    }
}
```

- [ ] **Step 2: Install PHPUnit**

Run: `composer update phpunit/phpunit --with-all-dependencies` (or `composer install` if the lock is regenerated).
Expected: creates `vendor/bin/phpunit` and regenerates the autoloader with `autoload-dev`.

- [ ] **Step 3: Create `phpunit.xml.dist`**
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         bootstrap="vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="MadMen">
      <directory>tests</directory>
    </testsuite>
  </testsuites>
</phpunit>
```

- [ ] **Step 4: Create `tests/SmokeTest.php`**
```php
<?php
declare(strict_types=1);

namespace MadMen\Tests;

use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testHarnessRuns(): void
    {
        $this->assertTrue(true);
    }
}
```

- [ ] **Step 5: Run the suite — verify green**

Run: `vendor/bin/phpunit`
Expected: `OK (1 test, 1 assertion)`.

- [ ] **Step 6: Commit**
```bash
git add composer.json composer.lock phpunit.xml.dist tests/SmokeTest.php
git commit -m "test: add PHPUnit harness (MadMen\\Tests, phpunit ^10)"
```

---

### Task 2: `Presence::tempsManquant`

**Files:**
- Modify: `src/Core/Presence.php` (add method after `heuresSupMinutes`, currently ending line 351)
- Create: `tests/Core/PresenceTempsManquantTest.php`

**Interfaces:**
- Produces: `Presence::tempsManquant(int $presentMinutes, ?array $h = null): int` — `max(0, (span − lunch) − worked)`; uses `$h['debut']`, `$h['fin']`, `$h['dejeuner_debut']`, `$h['dejeuner_fin']`; `$h` null → `defaultHoraire()`.

- [ ] **Step 1: Write the failing test** — `tests/Core/PresenceTempsManquantTest.php`
```php
<?php
declare(strict_types=1);

namespace MadMen\Tests\Core;

use MadMen\Core\Presence;
use PHPUnit\Framework\TestCase;

final class PresenceTempsManquantTest extends TestCase
{
    /** @var array<string,mixed> 08:00–18:00, déjeuner 12:30–14:00 → prévu net 8h30 = 510 min */
    private array $h = [
        'debut' => '08:00', 'fin' => '18:00',
        'dejeuner_debut' => '12:30', 'dejeuner_fin' => '14:00', 'tolerance' => 0,
    ];

    public function testFullDayNoDeficit(): void
    {
        $this->assertSame(0, Presence::tempsManquant(510, $this->h));
    }

    public function testWorkedLessIsDeficit(): void
    {
        // worked 4h30 (270) → 510 − 270 = 240 (4h00)
        $this->assertSame(240, Presence::tempsManquant(270, $this->h));
    }

    public function testWorkedMoreClampsToZero(): void
    {
        $this->assertSame(0, Presence::tempsManquant(600, $this->h));
    }

    public function testNoLunchWindow(): void
    {
        $h = ['debut' => '08:00', 'fin' => '12:00', 'dejeuner_debut' => null, 'dejeuner_fin' => null];
        // prévu net = 4h = 240 ; worked 180 → 60
        $this->assertSame(60, Presence::tempsManquant(180, $h));
    }
}
```

- [ ] **Step 2: Run — verify it fails**

Run: `vendor/bin/phpunit --filter PresenceTempsManquantTest`
Expected: FAIL — `Error: Call to undefined method MadMen\Core\Presence::tempsManquant()`.

- [ ] **Step 3: Implement** — add to `src/Core/Presence.php` immediately after the `heuresSupMinutes` method (after line 351, before the closing `}` of the class)
```php
    /**
     * Temps de travail MANQUANT (minutes) sur la journée : ce que l'employé était
     * censé travailler (durée prévue − pause déjeuner) MOINS ce qu'il a réellement
     * travaillé ; 0 si à jour ou au-delà. Base début/fin = celle de presenceMinutes
     * (pour que travaillé + manquant se réconcilient). C'est un solde de FIN DE JOURNÉE.
     */
    public static function tempsManquant(int $presentMinutes, ?array $h = null): int
    {
        $h = $h ?? self::defaultHoraire();
        $debut = (int) strtotime('2000-01-01 ' . $h['debut']);
        $fin   = (int) strtotime('2000-01-01 ' . $h['fin']);
        $spanMin = max(0, (int) (($fin - $debut) / 60));

        $lunchMin = 0;
        if (!empty($h['dejeuner_debut']) && !empty($h['dejeuner_fin'])) {
            $ld = (int) strtotime('2000-01-01 ' . $h['dejeuner_debut']);
            $lf = (int) strtotime('2000-01-01 ' . $h['dejeuner_fin']);
            $lunchMin = max(0, (int) (($lf - $ld) / 60));
        }

        $prevuNet = max(0, $spanMin - $lunchMin);

        return max(0, $prevuNet - $presentMinutes);
    }
```

- [ ] **Step 4: Run — verify it passes**

Run: `vendor/bin/phpunit --filter PresenceTempsManquantTest`
Expected: `OK (4 tests, ...)`.

- [ ] **Step 5: Commit**
```bash
git add src/Core/Presence.php tests/Core/PresenceTempsManquantTest.php
git commit -m "feat(presence): tempsManquant (deficit heures = prevu net - travaille)"
```

---

### Task 3: `Presence::retardRetourDejeuner`

**Files:**
- Modify: `src/Core/Presence.php` (add method after `tempsManquant`)
- Create: `tests/Core/PresenceRetardDejeunerTest.php`

**Interfaces:**
- Produces: `Presence::retardRetourDejeuner(array $passages, ?array $h = null): int` where each `$passages[i]` is `['type'=>'entree'|'sortie', 'horodatage'=>'YYYY-MM-DD HH:MM:SS']`, sorted ascending. Returns `floor((firstReturnAfterFin − pause_fin)/60)` when the worker is out at `pause_fin` and returns later, else 0.

- [ ] **Step 1: Write the failing test** — `tests/Core/PresenceRetardDejeunerTest.php`
```php
<?php
declare(strict_types=1);

namespace MadMen\Tests\Core;

use MadMen\Core\Presence;
use PHPUnit\Framework\TestCase;

final class PresenceRetardDejeunerTest extends TestCase
{
    /** @var array<string,mixed> déjeuner 12:30–14:00 */
    private array $h = [
        'debut' => '08:00', 'fin' => '18:00',
        'dejeuner_debut' => '12:30', 'dejeuner_fin' => '14:00', 'tolerance' => 0,
    ];

    /** @return array{type:string,horodatage:string} */
    private function pp(string $type, string $time): array
    {
        return ['type' => $type, 'horodatage' => '2026-06-25 ' . $time];
    }

    public function testBackOnTime(): void
    {
        $p = [$this->pp('entree', '08:00:00'), $this->pp('sortie', '12:40:00'), $this->pp('entree', '13:55:00')];
        $this->assertSame(0, Presence::retardRetourDejeuner($p, $this->h));
    }

    public function testBackExactlyAtDeadline(): void
    {
        $p = [$this->pp('entree', '08:00:00'), $this->pp('sortie', '12:40:00'), $this->pp('entree', '14:00:00')];
        $this->assertSame(0, Presence::retardRetourDejeuner($p, $this->h));
    }

    public function testBackOneMinuteLate(): void
    {
        $p = [$this->pp('entree', '08:00:00'), $this->pp('sortie', '12:40:00'), $this->pp('entree', '14:01:00')];
        $this->assertSame(1, Presence::retardRetourDejeuner($p, $this->h));
    }

    public function testBackFiveLate(): void
    {
        $p = [$this->pp('entree', '08:00:00'), $this->pp('sortie', '12:40:00'), $this->pp('entree', '14:05:00')];
        $this->assertSame(5, Presence::retardRetourDejeuner($p, $this->h));
    }

    public function testLeftLateDeadlineNotExtended(): void
    {
        // left 12:50, back 14:05 → still 5 (NOT 15)
        $p = [$this->pp('entree', '08:00:00'), $this->pp('sortie', '12:50:00'), $this->pp('entree', '14:05:00')];
        $this->assertSame(5, Presence::retardRetourDejeuner($p, $this->h));
    }

    public function testWorkedThroughNoSortie(): void
    {
        $p = [$this->pp('entree', '08:00:00')];
        $this->assertSame(0, Presence::retardRetourDejeuner($p, $this->h));
    }

    public function testNeverReturned(): void
    {
        // out 12:40, no return → 0 (covered by tempsManquant)
        $p = [$this->pp('entree', '08:00:00'), $this->pp('sortie', '12:40:00')];
        $this->assertSame(0, Presence::retardRetourDejeuner($p, $this->h));
    }

    public function testNoLunchWindowIsZero(): void
    {
        $h = ['debut' => '08:00', 'fin' => '18:00', 'dejeuner_debut' => null, 'dejeuner_fin' => null];
        $p = [$this->pp('entree', '08:00:00'), $this->pp('sortie', '12:40:00'), $this->pp('entree', '14:05:00')];
        $this->assertSame(0, Presence::retardRetourDejeuner($p, $h));
    }
}
```

- [ ] **Step 2: Run — verify it fails**

Run: `vendor/bin/phpunit --filter PresenceRetardDejeunerTest`
Expected: FAIL — `Call to undefined method ...::retardRetourDejeuner()`.

- [ ] **Step 3: Implement** — add to `src/Core/Presence.php` immediately after `tempsManquant`
```php
    /**
     * Retard de RETOUR de pause déjeuner (minutes). La pause est une fenêtre FIXE
     * [dejeuner_debut, dejeuner_fin] : quelle que soit l'heure de DÉPART, il faut être
     * repointé À/AVANT dejeuner_fin. Si, à dejeuner_fin, le dernier passage est une
     * SORTIE (l'employé est dehors) et qu'il repointe une ENTRÉE après dejeuner_fin,
     * retard = floor((retour − dejeuner_fin)/60). Sinon 0 (à l'heure ; traversée sans
     * pause ; jamais revenu — ce dernier cas relève de tempsManquant).
     *
     * @param array<int,array{type:string,horodatage:string}> $passages triés ascendant
     */
    public static function retardRetourDejeuner(array $passages, ?array $h = null): int
    {
        $h = $h ?? self::defaultHoraire();
        if (empty($h['dejeuner_debut']) || empty($h['dejeuner_fin']) || $passages === []) {
            return 0;
        }
        $date  = substr((string) $passages[0]['horodatage'], 0, 10);
        $finTs = (int) strtotime($date . ' ' . $h['dejeuner_fin']);

        // Dernier passage à/avant dejeuner_fin : décide si l'employé est DEHORS à 14:00.
        $avant = null;
        foreach ($passages as $p) {
            if ((int) strtotime((string) $p['horodatage']) <= $finTs) {
                $avant = $p;
            }
        }
        if ($avant === null || $avant['type'] !== 'sortie') {
            return 0; // pas dehors à dejeuner_fin (pointé entrée = à l'heure / sans pause)
        }

        // Première ENTRÉE après dejeuner_fin = le retour tardif.
        foreach ($passages as $p) {
            if ($p['type'] === 'entree' && (int) strtotime((string) $p['horodatage']) > $finTs) {
                return (int) floor(((int) strtotime((string) $p['horodatage']) - $finTs) / 60);
            }
        }

        return 0; // jamais revenu
    }
```

- [ ] **Step 4: Run — verify it passes**

Run: `vendor/bin/phpunit --filter PresenceRetardDejeunerTest`
Expected: `OK (8 tests, ...)`.

- [ ] **Step 5: Commit**
```bash
git add src/Core/Presence.php tests/Core/PresenceRetardDejeunerTest.php
git commit -m "feat(presence): retardRetourDejeuner (retour apres l'heure fixe de fin de pause)"
```

---

### Task 4: Migration — two new `pointage` columns

**Files:**
- Create: `database/migrations/061_add_dejeuner_retard_to_pointage.php`

**Interfaces:**
- Produces: columns `pointage.retard_dejeuner_minutes INT NOT NULL DEFAULT 0` and `pointage.temps_manquant_minutes INT NOT NULL DEFAULT 0`.

- [ ] **Step 1: Create the migration**
```php
<?php
return [
    // Deux compteurs quotidiens supplémentaires du résumé pointage :
    //  - retard_dejeuner_minutes : retour de pause déjeuner APRÈS l'heure fixe de fin.
    //  - temps_manquant_minutes  : (durée prévue − déjeuner) − temps réellement travaillé.
    'up' => "ALTER TABLE pointage
        ADD COLUMN retard_dejeuner_minutes INT NOT NULL DEFAULT 0 AFTER retard_minutes,
        ADD COLUMN temps_manquant_minutes INT NOT NULL DEFAULT 0 AFTER retard_dejeuner_minutes",
    'down' => "ALTER TABLE pointage
        DROP COLUMN temps_manquant_minutes,
        DROP COLUMN retard_dejeuner_minutes",
];
```

- [ ] **Step 2: Run the migration**

Run: `php database/migrate.php`
Expected: `  [up]   061_add_dejeuner_retard_to_pointage` then `OK : 1 migration(s) appliquee(s).`

- [ ] **Step 3: Verify the columns exist**

Run: `php -r "require 'config/database.php'; " ` is not needed — instead:
Run: `php database/migrate.php status`
Expected: line `  [x] 061_add_dejeuner_retard_to_pointage`.

- [ ] **Step 4: Commit**
```bash
git add database/migrations/061_add_dejeuner_retard_to_pointage.php
git commit -m "feat(db): pointage.retard_dejeuner_minutes + temps_manquant_minutes (migration 061)"
```

---

### Task 5: Wire the two metrics into `K40Pointage::enregistrer`

**Files:**
- Modify: `src/Core/K40Pointage.php:202-245` (the résumé reload, the INSERT, the UPDATE)

**Interfaces:**
- Consumes: `Presence::retardRetourDejeuner(array $passages, ?array $h)`, `Presence::tempsManquant(int $present, ?array $h)`, `$resume['present']`, `$fenetre`, `$horaire`.
- Produces: `retard_dejeuner_minutes` + `temps_manquant_minutes` written to the `pointage` row on both INSERT and UPDATE.

- [ ] **Step 1: Keep the passages array + compute the two metrics**

Replace lines 202-207:
```php
        // 3) Recharge tous les passages du jour et recalcule le résumé.
        $stmt = $db->prepare(
            'SELECT type, horodatage FROM pointage_passage WHERE employe_id = ? AND date = ? ORDER BY horodatage, id'
        );
        $stmt->execute([$employeId, $date]);
        $resume = self::resumeJournee($stmt->fetchAll(), $horaire);
```
with:
```php
        // 3) Recharge tous les passages du jour et recalcule le résumé.
        $stmt = $db->prepare(
            'SELECT type, horodatage FROM pointage_passage WHERE employe_id = ? AND date = ? ORDER BY horodatage, id'
        );
        $stmt->execute([$employeId, $date]);
        $passages = $stmt->fetchAll();
        $resume = self::resumeJournee($passages, $horaire);

        // Compteurs du jour (jour de repos -> 0). retard_dejeuner = retour de pause après
        // l'heure fixe de fin ; temps_manquant = prévu net − travaillé (solde fin de journée).
        $retardDej = $fenetre ? Presence::retardRetourDejeuner($passages, $horaire) : 0;
        $tempsManq = $fenetre ? Presence::tempsManquant($resume['present'], $horaire) : 0;
```

- [ ] **Step 2: Add both columns to the INSERT**

Replace lines 222-230:
```php
            $db->prepare(
                'INSERT INTO pointage
                    (employe_id, appareil_id, date, heure_entree, heure_sortie, methode,
                     retard_minutes, temps_present_minutes, temps_pause_minutes, nb_pauses, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $employeId, $appareilId, $date, $resume['entree'], $resume['sortie'], 'empreinte',
                $retard, $resume['present'], $resume['pause'], $resume['nb_pauses'], $statut,
            ]);
```
with:
```php
            $db->prepare(
                'INSERT INTO pointage
                    (employe_id, appareil_id, date, heure_entree, heure_sortie, methode,
                     retard_minutes, retard_dejeuner_minutes, temps_manquant_minutes,
                     temps_present_minutes, temps_pause_minutes, nb_pauses, statut)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $employeId, $appareilId, $date, $resume['entree'], $resume['sortie'], 'empreinte',
                $retard, $retardDej, $tempsManq,
                $resume['present'], $resume['pause'], $resume['nb_pauses'], $statut,
            ]);
```

- [ ] **Step 3: Add both columns to the UPDATE**

Replace lines 234-244:
```php
            $db->prepare(
                "UPDATE pointage SET heure_sortie = ?, temps_present_minutes = ?,
                    temps_pause_minutes = ?, nb_pauses = ?,
                    statut = CASE WHEN ? = 1 THEN 'parti'
                                  WHEN statut = 'retard' THEN 'retard'
                                  ELSE 'present' END
                 WHERE id = ?"
            )->execute([
                $resume['sortie'], $resume['present'], $resume['pause'], $resume['nb_pauses'],
                $estParti ? 1 : 0, (int) $pid,
            ]);
```
with:
```php
            $db->prepare(
                "UPDATE pointage SET heure_sortie = ?, retard_dejeuner_minutes = ?,
                    temps_manquant_minutes = ?, temps_present_minutes = ?,
                    temps_pause_minutes = ?, nb_pauses = ?,
                    statut = CASE WHEN ? = 1 THEN 'parti'
                                  WHEN statut = 'retard' THEN 'retard'
                                  ELSE 'present' END
                 WHERE id = ?"
            )->execute([
                $resume['sortie'], $retardDej, $tempsManq, $resume['present'], $resume['pause'], $resume['nb_pauses'],
                $estParti ? 1 : 0, (int) $pid,
            ]);
```

- [ ] **Step 4: Lint**

Run: `php -l src/Core/K40Pointage.php`
Expected: `No syntax errors detected in src/Core/K40Pointage.php`.

- [ ] **Step 5: Smoke-test end to end (manual pointage writes the columns)**

With the dev DB + server available, create a same-day lunch sequence for one employee via the manual endpoint, then read the row:
```bash
# replace {ID} with a real employe id; run on a server that can reach the DB
curl -s -X POST "http://127.0.0.1:8000/api/employes/{ID}/pointage-manuel" -H "Content-Type: application/json" -d "{\"horodatage\":\"2026-06-25 08:00:00\",\"type\":\"entree\"}" >/dev/null
curl -s -X POST "http://127.0.0.1:8000/api/employes/{ID}/pointage-manuel" -H "Content-Type: application/json" -d "{\"horodatage\":\"2026-06-25 12:40:00\",\"type\":\"sortie\"}" >/dev/null
curl -s -X POST "http://127.0.0.1:8000/api/employes/{ID}/pointage-manuel" -H "Content-Type: application/json" -d "{\"horodatage\":\"2026-06-25 14:05:00\",\"type\":\"entree\"}"
```
Expected: the returned `pointage` object has `"retard_dejeuner_minutes": 5` (back 14:05) and `temps_manquant_minutes` > 0. (If no DB/server is available, skip and rely on Tasks 2–3 unit coverage + the lint; note the skip.)

- [ ] **Step 6: Commit**
```bash
git add src/Core/K40Pointage.php
git commit -m "feat(pointage): calcule + stocke retard_dejeuner_minutes & temps_manquant_minutes"
```

---

### Task 6: Default company lunch window → 12:30–14:00

**Files:**
- Modify: `config/presence.php:8-9,26`

**Interfaces:**
- Produces: `defaultHoraire()['dejeuner_fin']` resolves to `14:00` when `DEJEUNER_FIN` env is unset.

- [ ] **Step 1: Change the default + comment**

Replace line 26:
```php
    'dejeuner_fin'   => $env['DEJEUNER_FIN'] ?? '13:30',
```
with:
```php
    'dejeuner_fin'   => $env['DEJEUNER_FIN'] ?? '14:00',
```
And update the doc comment — replace lines 8-10:
```php
 * Présence comptée de 08:30 à 18:00 ; retard si arrivée après 08:30 ;
 * pause déjeuner 12:30–13:30 (1h, NON comptée, exclue du temps de présence) ;
 * tout travail après 18:00 = heures supplémentaires.
```
with:
```php
 * Présence comptée de 08:30 à 18:00 ; retard si arrivée après 08:30 ;
 * pause déjeuner 12:30–14:00 (1h30, NON comptée, exclue du temps de présence) ;
 * tout travail après 18:00 = heures supplémentaires.
```

- [ ] **Step 2: Verify**

Run: `php -r "require 'src/Core/Env.php'; \$c = require 'config/presence.php'; echo \$c['dejeuner_fin'], PHP_EOL;"`
Expected: `14:00`.

- [ ] **Step 3: Commit**
```bash
git add config/presence.php
git commit -m "chore(presence): defaut pause dejeuner 12:30-14:00 (1h30)"
```

---

### Task 7: Persist the lunch window in the planning branch of `HoraireController::upsert`

**Files:**
- Modify: `src/Controllers/HoraireController.php:71-104` (planning branch — currently writes `pause_debut`/`pause_fin` as `NULL`)

**Interfaces:**
- Consumes: `$this->normTime($v): ?string` (existing, lines 159-166).
- Produces: planning-mode `PUT /api/employes/{id}/horaire` now persists a single `pause_debut`/`pause_fin` window when supplied (both-or-neither, `pause_fin > pause_debut`).

- [ ] **Step 1: Parse + validate + persist the pause in the planning branch**

Replace the planning branch body (lines 80-103) — keep the existing validation of tolerance/avance/planning above it (lines 72-89) — i.e. replace from the `$arr = ...` computation through the `return;`:

Find (lines 87-103):
```php
            $arr = min(array_column($planning, 'debut')) . ':00';
            $dep = max(array_column($planning, 'fin')) . ':00';
            $jours = implode(',', array_keys($planning));

            Database::connection()->prepare(
                "INSERT INTO horaire_employe
                    (employe_id, heure_arrivee, heure_depart, pause_debut, pause_fin, tolerance_minutes, avance_minutes, jours_travailles, planning)
                 VALUES (?, ?, ?, NULL, NULL, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    heure_arrivee = VALUES(heure_arrivee), heure_depart = VALUES(heure_depart),
                    pause_debut = NULL, pause_fin = NULL,
                    tolerance_minutes = VALUES(tolerance_minutes), avance_minutes = VALUES(avance_minutes),
                    jours_travailles = VALUES(jours_travailles), planning = VALUES(planning)"
            )->execute([$id, $arr, $dep, $tol, $avance, $jours, json_encode($planning)]);

            $this->show($params);
            return; // ne pas tomber dans le mode legacy ci-dessous
```
Replace with:
```php
            $arr = min(array_column($planning, 'debut')) . ':00';
            $dep = max(array_column($planning, 'fin')) . ':00';
            $jours = implode(',', array_keys($planning));

            // Pause déjeuner : fenêtre UNIQUE optionnelle, valable aussi en mode planning.
            $pdeb = isset($body['pause_debut']) ? $this->normTime($body['pause_debut']) : null;
            $pfin = isset($body['pause_fin']) ? $this->normTime($body['pause_fin']) : null;
            if (($pdeb === null) !== ($pfin === null)) {
                Response::error("La pause déjeuner exige 'pause_debut' ET 'pause_fin' (ou aucune des deux)", 422);
            }
            if ($pdeb !== null && $pfin !== null && $pfin <= $pdeb) {
                Response::error("La fin de la pause doit être après son début", 422);
            }

            Database::connection()->prepare(
                "INSERT INTO horaire_employe
                    (employe_id, heure_arrivee, heure_depart, pause_debut, pause_fin, tolerance_minutes, avance_minutes, jours_travailles, planning)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    heure_arrivee = VALUES(heure_arrivee), heure_depart = VALUES(heure_depart),
                    pause_debut = VALUES(pause_debut), pause_fin = VALUES(pause_fin),
                    tolerance_minutes = VALUES(tolerance_minutes), avance_minutes = VALUES(avance_minutes),
                    jours_travailles = VALUES(jours_travailles), planning = VALUES(planning)"
            )->execute([$id, $arr, $dep, $pdeb, $pfin, $tol, $avance, $jours, json_encode($planning)]);

            $this->show($params);
            return; // ne pas tomber dans le mode legacy ci-dessous
```

- [ ] **Step 2: Lint**

Run: `php -l src/Controllers/HoraireController.php`
Expected: `No syntax errors detected`.

- [ ] **Step 3: Commit**
```bash
git add src/Controllers/HoraireController.php
git commit -m "feat(horaire): conserve la pause dejeuner aussi en mode planning"
```

---

### Task 8: Lunch-window inputs in `ConfigHoraire.jsx`

**Files:**
- Modify: `C:/dev/madmen/madmen-front-react-js/src/components/horaire/ConfigHoraire.jsx`

**Interfaces:**
- Consumes: `PUT /api/employes/{id}/horaire` now accepts `pause_debut`/`pause_fin` in both branches (Task 7).
- Produces: the schedule editor sends `pause_debut` + `pause_fin` (default `12:30`/`14:00`).

- [ ] **Step 1: Read the existing time-input pattern**

Read `ConfigHoraire.jsx` and note how `heure_arrivee` is rendered (component import — likely `TimePicker` from `../ui/TimePicker.jsx`, or `<input type="time">`) and the `Field` wrapper. Reuse that exact pattern for the two new inputs in Step 3.

- [ ] **Step 2: Add the lunch window to the form defaults**

In `const DEFAUT = {...}` (lines 21-27), add two keys:
```jsx
const DEFAUT = {
  heure_arrivee: "08:00",
  heure_depart: "17:00",
  pause_debut: "12:30",
  pause_fin: "14:00",
  jours: [1, 2, 3, 4, 5],
  tolerance_minutes: 10,
  avance_minutes: 30,
};
```

- [ ] **Step 3: Add the lunch-window inputs**

Immediately AFTER the tolerances block (which ends around line 344, the `</div>` closing the `grid` started at line 309), insert a lunch-window section, using the SAME time-input component the file already uses for `heure_arrivee` (read in Step 1 — shown here with `TimePicker`; if the file uses `<input type="time">`, mirror that instead):
```jsx
      {/* Pause déjeuner : fenêtre fixe (clock-out/in requis ; retour après pause_fin = retard) */}
      <div className="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <Field label="Début de la pause déjeuner">
          <TimePicker value={form.pause_debut} onChange={(v) => setForm((f) => ({ ...f, pause_debut: v }))} />
        </Field>
        <Field label="Fin de la pause (heure de retour limite)">
          <TimePicker value={form.pause_fin} onChange={(v) => setForm((f) => ({ ...f, pause_fin: v }))} />
        </Field>
      </div>
```
If `TimePicker` is not imported in this file, add `import TimePicker from "../ui/TimePicker.jsx";` at the top, OR use the file's existing time input (e.g. `<input type="time" className={...} value={form.pause_debut} onChange={(e) => setForm((f) => ({ ...f, pause_debut: e.target.value }))} />`).

- [ ] **Step 4: Send the pause in BOTH payload branches**

In `enregistrer` (lines 175-193), add `pause_debut`/`pause_fin` to each payload:
```jsx
    let payload;
    if (form.avance) {
      const planning = {};
      for (const iso of form.jours) {
        const c = form.planning[iso] || {};
        planning[String(iso)] = { debut: c.debut, fin: c.fin };
      }
      payload = {
        planning,
        pause_debut: form.pause_debut,
        pause_fin: form.pause_fin,
        tolerance_minutes: tolerance,
        avance_minutes: avanceMin,
      };
    } else {
      payload = {
        heure_arrivee: form.heure_arrivee,
        heure_depart: form.heure_depart,
        pause_debut: form.pause_debut,
        pause_fin: form.pause_fin,
        jours_travailles: [...form.jours].sort((a, b) => a - b).join(","),
        tolerance_minutes: tolerance,
        avance_minutes: avanceMin,
      };
    }
```

- [ ] **Step 5: Build**

Run (in `C:/dev/madmen/madmen-front-react-js`): `npm run build`
Expected: `✓ built in ...` with no error.

- [ ] **Step 6: Commit**
```bash
git -C C:/dev/madmen/madmen-front-react-js add src/components/horaire/ConfigHoraire.jsx
git -C C:/dev/madmen/madmen-front-react-js commit -m "feat(horaire-ui): champs pause dejeuner (debut + heure de retour limite)"
```

---

### Task 9: Surface the two figures in the dashboard

**Files:**
- Modify: `C:/dev/madmen/madmen-front-react-js/src/lib/mappers.js:23-31` (employe `today`)
- Modify: `C:/dev/madmen/madmen-front-react-js/src/components/ui/CartePresence.jsx:33-60`
- Modify: `C:/dev/madmen/madmen-front-react-js/src/components/ui/CartePerformance.jsx:46-53` (per-pointage mapper)

**Interfaces:**
- Consumes: API pointage/employe objects now carry `retard_dejeuner_minutes` + `temps_manquant_minutes`.
- Produces: UI fields `retardDejeunerMin`, `tempsManquantMin`.

- [ ] **Step 1: Map the two new fields on the employe `today`**

In `mappers.js`, inside the `today: {...}` object (after line 26 `retardMin: t.retard_minutes ?? null,`), add:
```js
      retardMin: t.retard_minutes ?? null,
      retardDejeunerMin: t.retard_dejeuner_minutes ?? null,
      tempsManquantMin: t.temps_manquant_minutes ?? null,
```

- [ ] **Step 2: Map the two new fields per-pointage**

In `CartePerformance.jsx` `mapPresence()` (lines 46-53), add two keys after `retardMin`/`temps`:
```js
    .map((p) => ({
      date: dateCourte(p.date),
      statut: STATUT_PRESENCE[p.statut] ?? "Absent",
      arrivee: hhmm(p.heure_entree),
      depart: hhmm(p.heure_sortie),
      retardMin: Number(p.retard_minutes) || 0,
      retardDejeunerMin: Number(p.retard_dejeuner_minutes) || 0,
      tempsManquantMin: Number(p.temps_manquant_minutes) || 0,
      temps: dureeTexte(p.temps_present_minutes),
    }));
```

- [ ] **Step 3: Add a "Retard déj." tile to the live presence card**

In `CartePresence.jsx`, after line 37 (`const retard = ...`), add:
```jsx
  const retard = t.retardMin > 0 ? `+${t.retardMin} min` : "0 min";
  const retardDej = t.retardDejeunerMin > 0 ? `+${t.retardDejeunerMin} min` : "0 min";
```
Then change the metrics grid (lines 56-61) from `grid-cols-2` (4 tiles) to `grid-cols-3` and add the new tile:
```jsx
      <div className="px-5 pb-4 grid grid-cols-3 gap-3 border-t border-border pt-4">
        <Metrique icon="login" label="Arrivée" value={arrivee} tone="text-emerald-600" />
        <Metrique icon="logout" label="Départ" value={present ? "En cours" : "—"} />
        <Metrique icon="schedule" label="Retard" value={retard} tone={t.retardMin > 0 ? "text-rose-600" : "text-ink"} />
        <Metrique icon="lunch_dining" label="Retard déj." value={retardDej} tone={t.retardDejeunerMin > 0 ? "text-rose-600" : "text-ink"} />
        <Metrique icon="timelapse" label="Temps travaillé" value={temps} />
      </div>
```
(Note: `temps_manquant` is an end-of-day figure — it is intentionally NOT shown on this live card; it surfaces in the 7-day history via `CartePerformance` mapper from Step 2. A follow-up can add it to a closed-day/history row.)

- [ ] **Step 4: Build**

Run (in `C:/dev/madmen/madmen-front-react-js`): `npm run build`
Expected: `✓ built in ...` with no error.

- [ ] **Step 5: Commit**
```bash
git -C C:/dev/madmen/madmen-front-react-js add src/lib/mappers.js src/components/ui/CartePresence.jsx src/components/ui/CartePerformance.jsx
git -C C:/dev/madmen/madmen-front-react-js commit -m "feat(dashboard): affiche retard dejeuner (live) + mappe temps manquant (historique)"
```

---

## Self-Review

**Spec coverage:**
- Rule 1 (count from début): already implemented — confirmed in spec; no task needed (the K40 early-arrival path is unchanged and already records early punches — see `enregistrer` comment b).
- Rule 2 (retard déjeuner): Task 3 (logic) + Task 5 (wiring) + Task 4 (column) + Task 9 (display). ✓
- Rule 3 (temps manquant): Task 2 (logic) + Task 5 (wiring) + Task 4 (column) + Task 9 (mapper). ✓
- Per-worker lunch window + default 12:30–14:00: Task 6 (default) + Task 7 (planning persist) + Task 8 (editor). ✓
- API exposure: no task needed — `PointageController` uses `SELECT *` (documented). ✓
- Tests: Task 1 (harness) + Tasks 2–3 (unit). ✓

**Placeholder scan:** Task 8 Step 1 instructs reading the existing time-input pattern (because `ConfigHoraire.jsx` was not captured verbatim) and Step 3 gives both `TimePicker` and `<input type="time">` concrete forms — not a placeholder, a documented branch. No TBD/TODO elsewhere.

**Type consistency:** `retardRetourDejeuner(array $passages, ?array $h)` and `tempsManquant(int $present, ?array $h)` are used with those exact signatures in Task 5. UI fields `retardDejeunerMin`/`tempsManquantMin` are produced in Task 9 Steps 1–2 and consumed in Step 3. Column names `retard_dejeuner_minutes`/`temps_manquant_minutes` are identical across Tasks 4, 5, 9.

**Open decision flagged for review:** `temps_manquant` is stored every punch but is only meaningful for a CLOSED day (mid-day it reads inflated because the open in-progress session is not yet counted). The plan shows it in history only, not on the live today card. Confirm this is acceptable, or we add a "show only when parti / after fin" rule to the live card.
