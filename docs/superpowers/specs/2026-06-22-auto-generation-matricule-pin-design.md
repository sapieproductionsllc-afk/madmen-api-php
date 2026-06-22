# Auto-génération du matricule et du code PIN (API employés)

> Spec de conception — 2026-06-22
> Projet : `madmen-api-php`
> Statut : approuvé (design), en attente de relecture du spec avant plan d'implémentation.

## 1. Contexte & problème

La création d'un employé (`POST /api/employes`) exige aujourd'hui que l'admin **saisisse à la main** le `matricule` ET le `code_pin`.

Deux problèmes en découlent :

1. **Risque d'erreur humaine sur l'unicité.** Le matricule est `UNIQUE` en base (un doublon est rejeté), mais le **PIN n'est jamais vérifié pour l'unicité** à la création. Or la connexion de l'app employé se fait **par PIN seul** (`POST /api/auth/login-pin`, voir `AuthController::loginPin`). Deux employés avec le même PIN provoquent un **409** qui bloque la connexion des deux comptes.
2. **PIN haché = pas récupérable.** Le PIN est stocké en `bcrypt` (`code_pin_hash`). En cas d'oubli, il n'existe aucun moyen de le retrouver ; il faut pouvoir en **régénérer** un.

## 2. Objectifs

- L'API **génère automatiquement** le matricule (format séquentiel `EMP-0001`) et un PIN **unique à 4 chiffres** au backend, sans saisie humaine du PIN.
- L'admin **peut** fournir un matricule (reprise d'un matricule existant lors d'une migration) ; sinon il est auto-généré. Le **PIN est toujours auto-généré** (jamais saisi).
- Fournir un endpoint de **régénération de PIN** (PIN oublié).
- **Garantir l'unicité du PIN** entre tous les employés au moment de la génération.
- Au passage : **corriger l'exposition des salaires** au superviseur (bloquant sécurité #3 du rapport de vérification du 2026-06-22).

## 3. Non-objectifs (hors périmètre)

- Aucune modification des apps front (le PIN reste à **4 chiffres**, `PIN_LENGTH=4` inchangé dans le kiosque et l'app employé).
- Pas de refonte du modèle d'authentification (on garde `bcrypt` + login par PIN seul). L'optimisation d'un index de PIN (HMAC poivré pour recherche O(1)) est notée comme amélioration future, **pas** dans ce périmètre.
- Les autres findings du rapport de vérification (usurpation `X-Employe-Id`, forge JWT via `APP_KEY` par défaut, anti-collision PIN kiosque, bugs de fuseau du front, etc.) sont traités dans des lots séparés.

## 4. Décisions actées

| Sujet | Décision |
|---|---|
| Format matricule | Séquentiel `EMP-NNNN` (zéro-paddé sur 4, croît au-delà : `EMP-10000`) |
| Matricule fourni | Optionnel : si fourni et valide → utilisé ; sinon auto-généré |
| Longueur PIN | 4 chiffres |
| Génération PIN | Toujours automatique ; jamais saisi par l'admin |
| Unicité PIN | Vérifiée à la génération (comparaison bcrypt sur tous les employés) |
| Régénération PIN | Endpoint dédié inclus |
| Fix salaire (#3) | Inclus : `salaire` retiré des colonnes génériques employé |

## 5. Composants

### 5.1 Nouveau : `src/Core/Identite.php`

Unité dédiée et isolée (garde `EmployeController` mince et testable).

- `prochainMatricule(PDO $db): string`
  - Lit le plus grand suffixe numérique parmi les matricules au format `EMP-<chiffres>` :
    `SELECT MAX(CAST(SUBSTRING(matricule, 5) AS UNSIGNED)) FROM employe WHERE matricule REGEXP '^EMP-[0-9]+$'`.
  - Renvoie `EMP-` + (max + 1) formaté sur au moins 4 chiffres (`sprintf('EMP-%04d', $n)`).
  - Ignore les matricules non conformes existants (`Ss-23`, `DEMO-EMP`).

- `genererPinUnique(PDO $db): string`
  - Charge une fois tous les `code_pin_hash` des employés.
  - Garde-fou de saturation : si le nombre d'employés ≥ 10 000, lève une exception dédiée (aucun PIN 4 chiffres libre possible).
  - Boucle (jusqu'à `MAX_TENTATIVES = 200`) : tire un candidat `random_int(0, 9999)` zéro-paddé sur 4 (`str_pad(..., 4, '0', STR_PAD_LEFT)`) ; le candidat est **libre** si `password_verify($candidat, $hash)` est faux pour **tous** les hash. Premier candidat libre → renvoyé **en clair**.
  - Si aucun candidat libre après `MAX_TENTATIVES` → lève l'exception de saturation.
  - Coût : O(employés) bcrypt par candidat ; acceptable à l'échelle de l'entreprise (dizaines d'employés). Documenté comme tel.

> Le hachage du PIN reste de la responsabilité du contrôleur (`password_hash(..., PASSWORD_BCRYPT)`), cohérent avec l'existant.

### 5.2 Modifié : `src/Controllers/EmployeController.php`

- **`store()`**
  - Champs requis réduits à `nom` + `prenom` (plus de `code_pin` requis).
  - `matricule` : si fourni → validé (non vide, ≤ 20 car.) et utilisé ; sinon `Identite::prochainMatricule()`.
  - `code_pin` éventuellement présent dans le corps est **ignoré** : le PIN est toujours `Identite::genererPinUnique()`.
  - Hache le PIN, insère. En cas de collision matricule concurrente (`SQLSTATE 23000`) avec matricule **auto-généré**, recalcule le matricule et réessaie (borné, ex. 5 tentatives). Si le matricule était **fourni** par l'admin, renvoie `422` « Ce matricule existe déjà » (pas de retry).
  - Réponse `201` : l'employé (via `find()`) **+ champ `code_pin_genere`** (le PIN en clair, affiché une seule fois).
  - Conserve le push K40 best-effort existant.

- **`regenererPin(array $params)`** *(nouveau)*
  - `POST /api/employes/{id}/regenerer-pin`.
  - `404` si employé inconnu.
  - Génère un nouveau PIN unique, le hache, `UPDATE employe SET code_pin_hash = ?`.
  - Réponse `200` : `{ "id": <id>, "matricule": "<matricule>", "code_pin_genere": "<pin>" }`.

- **`update()`**
  - Ne fixe **plus** `code_pin_hash` à partir du corps (suppression du bloc `code_pin`). Tout changement de PIN passe désormais par `regenererPin`. Évite de réintroduire le risque de doublon de PIN.

- **`COLUMNS`** (fix #3)
  - Retirer `salaire` de la liste des colonnes renvoyées par `index()` (`GET /api/employes`) et `show()` (`GET /api/employes/{id}`), routes accessibles au superviseur (rang 2).
  - L'employé voit toujours **son propre** salaire via `GET /api/me/profil`. Le bulletin de paie (`GET /api/employes/{id}/paie`, rang 3 directeur) calcule à partir de `employe.salaire` côté serveur, non affecté.
  - À vérifier : si le dashboard admin affiche les salaires depuis `GET /api/employes`, il faudra une route de niveau directeur dédiée (hors périmètre, à signaler).

### 5.3 Modifié : `public/index.php`

- Ajouter : `$router->post('/api/employes/{id}/regenerer-pin', [EmployeController::class, 'regenererPin']);`
- **Aucune config d'auth supplémentaire** : `Auth::requiredRank()` impose déjà le rang **4 (super_admin)** à toute écriture (`method != GET`) sur `^/api/(employes|biometrie|k40|config)`. La nouvelle route en hérite automatiquement.

### 5.4 Documentation : `openapi.yaml`

- Ajouter/mettre à jour : `POST /api/employes` (réponse avec `code_pin_genere`, `code_pin` non requis), nouveau `POST /api/employes/{id}/regenerer-pin`, retrait de `salaire` des schémas de réponse `GET /api/employes` / `{id}`.

## 6. Contrat d'API

### Création
```
POST /api/employes
{ "nom": "Mbeki", "prenom": "Jean", "poste_id": 3 }          // matricule optionnel, pas de code_pin
→ 201
{ "id": 39, "matricule": "EMP-0004", ..., "code_pin_genere": "4821" }
```
Avec matricule fourni :
```
POST /api/employes
{ "nom": "X", "prenom": "Y", "matricule": "EMP-0099" }
→ 201 { ..., "matricule": "EMP-0099", "code_pin_genere": "7310" }
```

### Régénération de PIN
```
POST /api/employes/39/regenerer-pin
→ 200 { "id": 39, "matricule": "EMP-0004", "code_pin_genere": "0573" }
```

## 7. Gestion des erreurs

| Cas | Code | Message |
|---|---|---|
| `nom` ou `prenom` manquant | 422 | « Le champ '<x>' est obligatoire » |
| matricule **fourni** en doublon | 422 | « Ce matricule existe déjà » |
| régénération sur id inconnu | 404 | « Employé introuvable » |
| espace PIN saturé (≥ 10 000 employés ou aucun libre) | 507 | « Impossible de générer un PIN unique (espace saturé) » |

## 8. Sécurité

- `code_pin_genere` est renvoyé **en clair, une seule fois** (création et régénération uniquement). Jamais stocké en clair, jamais renvoyé par les lectures (`GET`). À transmettre via HTTPS en production ; l'admin le communique à l'employé puis ne peut plus le revoir (régénération nécessaire si perdu).
- `salaire` n'est plus exposé aux superviseurs via les routes employé génériques (fix #3).
- Le throttle anti-brute-force par IP existant (`AuthController`) reste la défense principale contre le devinage d'un PIN à 4 chiffres.

## 9. Vérification (pas de framework de test dans le projet)

Smoke runtime contre le serveur `:8000`, puis nettoyage des données de test :

1. `POST /api/employes {nom,prenom}` → `201`, `matricule` ∈ `^EMP-\d{4,}$`, `code_pin_genere` ∈ `^\d{4}$`.
2. `POST /api/auth/login-pin {code_pin: <code_pin_genere>}` → `200` + token (le PIN généré fonctionne).
3. `POST /api/employes/{id}/regenerer-pin` → `200`, nouveau `code_pin_genere` ; l'**ancien** PIN ne logue plus (`401`), le **nouveau** logue (`200`).
4. `POST /api/employes {nom,prenom,matricule:"EMP-0001"}` (déjà pris) → `422`.
5. `GET /api/employes` → la réponse ne contient **plus** `salaire`.
6. Suppression des employés de test créés.

> Note : éviter de déclencher le throttle anti-brute-force par IP (ne pas tester de mauvais PIN en boucle).

## 10. Notes de déploiement / rétrocompatibilité

- Aucune migration de schéma (le schéma `employe` est inchangé : `matricule UNIQUE`, `code_pin_hash`).
- Changement de contrat **non cassant** pour les fronts existants : ceux-ci n'appellent pas `POST /api/employes` (création réservée au dashboard admin). Le dashboard admin devra cesser d'envoyer `code_pin` (ignoré) et lire `code_pin_genere` dans la réponse — à coordonner.
- Les employés existants (matricules non conformes `Ss-23`, `DEMO-EMP`) ne sont pas modifiés ; la séquence `EMP-NNNN` repart du max conforme existant.
