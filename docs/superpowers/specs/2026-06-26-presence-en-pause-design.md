# Présence « En pause » sur le tableau de bord — design

**But :** sur l'écran *Agents présents aujourd'hui* (Dashboard), afficher trois états réels
au lieu de Présent/Absent : **En activité**, **En pause**, **Absent** (+ Parti, Congé déjà gérés).

## Règle métier (validée avec l'utilisateur)

Par agent, à l'instant `now` :

| État | Condition |
|---|---|
| **Congé** | employé en congé |
| **Absent** | aucune **entrée** pointée aujourd'hui |
| **En activité** | a pointé ET son **dernier passage du jour est une « entrée »** (actuellement au bureau) |
| **En pause** | a pointé, actuellement **ressorti**, ET `now` ∈ **[pause_debut, pause_fin]** (déjeuner, défaut 12:30–14:00) |
| **Parti** | a pointé, actuellement ressorti, **hors** de la fenêtre de pause (sortie avant la pause ou en fin de journée = parti) |

La fenêtre de pause est **par employé** (`horaire_employe.pause_debut/pause_fin`), avec repli sur
l'horaire global (`config/presence.php`, 12:30–14:00) quand l'employé n'a pas d'horaire défini.

## Composants

1. **`src/Core/Presence.php`** — nouvelle fonction PURE
   `etatLive(string $statutJour, bool $aPointe, bool $presentMaintenant, string $now, ?array $h): string`
   → `conge|absent|present|retard|pause|parti`. Réutilise `estPauseDejeuner()`. Testable seule.

2. **`src/Controllers/DashboardController.php`** (`presence()`) — pour chaque agent, calculer
   l'état live et le renvoyer dans `agents[].statut`. Données récupérées en **requêtes ENSEMBLISTES**
   (pas de N+1, conformément à la note de perf existante) :
   - dernier passage du jour par employé (fenêtre `ROW_NUMBER()`),
   - `pause_debut/pause_fin` via `LEFT JOIN horaire_employe`.
   Le total `presents` inclut désormais les agents **En pause** (cohérent avec le front).

3. **`madmen-front-react-js/src/pages/Dashboard.jsx`** — ajouter `pause: "En pause"` au mapping
   `STATUT_LIVE`. La carte ambre « En pause », le filtre et la couleur existent déjà → ils s'activent.

## Hors périmètre (YAGNI)
- Pages **Présence** et **Activité** (même correctif d'une ligne possible plus tard, sur demande).
- Sorties hors fenêtre de pause traitées comme **Parti** (pas de cas spécial « pause hors déjeuner »).

## Tests
`tests/presence_etat_live_test.php` (runner autonome existant) : couvre absent / present / pause
(dans la fenêtre) / parti (hors fenêtre, avant et après) / congé.
