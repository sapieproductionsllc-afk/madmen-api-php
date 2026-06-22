# Intégration `madmen-front-react-js` ⇄ `madmen-api-php` — Plan d'alignement API

> **Principe.** Le **front React est la référence** : on **ne touche PAS** à son UI/UX/design/logique.
> C'est **l'API qui s'aligne** sur les contrats de données du front.
> **Règle d'or anti-casse :** on ne fait QUE de l'**additif** — nouvelles tables, nouveaux endpoints,
> **champs ajoutés** aux réponses existantes (jamais retirés/renommés). Aucun endpoint consommé par
> le **kiosque** ou la **sync offline** n'est modifié dans sa forme (voir §6).
>
> Tout ce qui **pourrait casser** ou demande une **décision produit** est en **§4 (À DISCUTER)** —
> à arbitrer avec le collègue, **non implémenté** pour l'instant.
>
> État de départ (audité) : front = **prototype 100 % mock** (données dans `src/data/`, auth factice) ;
> API = **~70 endpoints** + auth JWT (`/api/auth/login`, `/api/auth/me`). Migrations jusqu'à **040**
> (prochaine = **041**). `employe.role` existe (migration 027) ; `alerte.lu` existe (migration 014).

---

## 0. État d'implémentation (2026-06-22) — vérifié en live (DB dev)

✅ **Fait + testé** (migrations 041-045 appliquées, endpoints répondent 200, endpoints existants inchangés) :
- §3.C : `depenses`, journal paie (`POST /api/paie/{id}/payer`, `/payer-lot`, `GET /api/mouvements`, `/api/finance/synthese`), `parametres`, `utilisateurs`/`roles`, `appareils`, `rapports/synthese`, `documents`/`historique-rh` RH.
- §3.B : `GET /api/presence/temps-reel` + `GET /api/employes/{id}/presence` (calendrier admin).
- §3.A : `GET /api/employes(/{id})` enrichi (+`name`, `poste_libelle`, `departement_nom`, `manager_nom`, `role`) ; `GET /api/alertes` enrichi (+`employe_nom`, `severite`) + `POST /api/alertes/{id}/lu` & `POST /api/alertes/tout-lire`.

⏳ **Reste (additif, à faire) :**
- §3.A : objet `today` (pointage du jour) sur `/api/employes` ; détail `agents[]` sur `/api/dashboard/presence`.
- §3.D : `GET /api/productivite/global`.
- `openapi.yaml` : documenter les ~22 nouveaux endpoints.

⏸️ **Non touché** : tout le **§4** (décisions produit, à voir avec le collègue).

---

## 1. Conventions d'alignement (écarts de forme systématiques)

Le front et l'API ne parlent pas tout à fait la même langue. On résout ça **côté API, en additif** (on ajoute des champs, on n'enlève rien) + une **couche de mappers côté front** (hors périmètre ici, ne touche pas au design) :

| Le front attend | L'API renvoie aujourd'hui | Résolution (additive, côté API) |
|---|---|---|
| `id` = matricule `"AUR-8821"` | `id` numérique + `matricule` séparé | On **garde `id` numérique** et on expose **`matricule`** (déjà là). Le front mappe `id↔matricule`. |
| `name` (concaténé) | `nom`, `prenom` séparés | On **ajoute `name = CONCAT(prenom,' ',nom)`** sans retirer `nom`/`prenom`. |
| `department` (libellé) | `departement_id` (FK) | On **ajoute `departement_nom`** (JOIN). |
| `poste` / `fonction` (libellé) | `poste_id` (FK) | On **ajoute `poste_libelle`** (JOIN). *(« fonction » vs « poste » : voir §4.)* |
| manager (nom) | `superieur_id` (FK) | On **ajoute `manager_nom`** (self-JOIN). |
| `status` (libellé riche) | `statut` enum `actif/suspendu/conge` | On **garde** `statut` et on **ajoute `statut_libelle`** ; valeurs riches → §4. |
| `today` (pointage du jour) | rien d'agrégé | On **ajoute un objet `today`** dérivé (pointage + planning + `Core\Presence`). |
| montants en **FCFA** | nombres bruts | L'API renvoie des **nombres** ; le formatage FCFA reste **côté front** (`fcfa()`). |
| `live` (statut temps réel) | sessions brutes | **Nouvel endpoint** `/api/presence/temps-reel` (voir §3.B). |

---

## 2. Ce qui est DÉJÀ prêt (juste à câbler côté front, 0 travail API)

Auth JWT (`/api/auth/login`, `/api/auth/login-pin`, `/api/auth/me`), messagerie complète, prêts/avances
(`/api/prets`), demandes manager (`/api/demandes` + `/{id}/decision`), motifs, jours fériés, sessions/lock,
productivité (`/classement`, `/{id}`), heures sup, biométrie/enrôlement, notifications self-service
(`/api/me/notifications`). → **Rien à faire côté API**, ces pages se branchent telles quelles (+ mappers de forme).

---

## 3. ✅ À FAIRE — sûr, **additif**, non bloquant

> Tout ci-dessous est **non destructif** : nouveaux endpoints, nouvelles tables (migrations **041+**),
> ou **champs ajoutés**. Les consommateurs existants ignorent les champs supplémentaires.

### A. Enrichissements additifs d'endpoints EXISTANTS

- **`GET /api/employes` et `GET /api/employes/{id}`** — ajouter au SELECT (sans retirer les colonnes actuelles) :
  `name` (CONCAT), `poste_libelle` (JOIN `poste`), `departement_nom` (JOIN `departement`),
  `manager_nom` (self-JOIN `employe`), **`role`** (colonne déjà existante, juste l'ajouter à `COLUMNS`),
  `date_embauche` (= `created_at` faute de mieux, à confirmer §4), et un objet **`today`** (pointage du jour).
  *Sûr : additif, aucun champ retiré.*
- **`GET /api/alertes`** — ajouter via JOIN : `employe_nom`, `poste_machine` (libellé poste_travail),
  et un champ dérivé **`severite`** (`Critique/Moyen/Faible` mappé depuis `type`). Garder `type`, `message`, `lu`.
- **`POST /api/alertes/{id}/lu`** et **`POST /api/alertes/tout-lire`** — marquer lu (colonne `lu` déjà là). *Nouvel endpoint, sûr.*
- **`GET /api/dashboard/presence`** — **ajouter** (en plus des compteurs actuels) un tableau `agents[]`
  avec statut/agence/poste par employé (le front en a besoin pour Présence/Dashboard). *Additif.*
- **`GET /api/productivite/classement`** — ajouter `agence`/`fonction` à chaque ligne (JOIN), garder le reste.

### B. Statut temps réel — **nouvel endpoint**

- **`GET /api/presence/temps-reel`** → `[{ employe_id, matricule, name, live, detail, depuis }]`
  où `live ∈ {En activité, En pause, Absent, Congé}` dérivé de `session_travail` (ouverte/verrouillée) +
  `kiosque_activite` + `employe.statut`. Utilisé par Dashboard, Présence, Activité, BandeauAgent, Annuaire.
  *Nouveau `PresenceController`, lecture seule, sûr.*

### C. Nouvelles ressources (nouvelles tables + endpoints, 100 % additif)

1. **Dépenses société** — migration `041_create_depense` (`id, libelle, categorie, montant, date, note, created_at`)
   + `DepenseController` : `GET /api/depenses?periode=YYYY-MM`, `POST /api/depenses`, `DELETE /api/depenses/{id}`.
2. **Journal des paiements de paie** — migration `042_create_paie_paiement`
   (`id, employe_id, periode CHAR(7), montant, statut ENUM('paye'), paye_le, created_at`, UNIQUE `(employe_id, periode)`)
   + sur `PaieController` (additif) : `POST /api/paie/{employe_id}/payer`, `POST /api/paie/payer-lot`,
   `GET /api/finance/synthese?periode=` (totaux courant + précédent pour le delta), `GET /api/mouvements?periode=`.
   *`/api/paie` (lecture/calcul) reste inchangé.*
3. **Paramètres globaux** — migration `043_create_parametre` (`cle VARCHAR PK, valeur JSON/TEXT`)
   + `ParametreController` : `GET /api/parametres`, `PUT /api/parametres`. *Nouvelle table, n'affecte pas `ConfigController`.*
4. **Comptes & rôles (dashboard)** — `UtilisateurController` (lecture depuis `employe`) :
   `GET /api/utilisateurs` (`id, matricule, name, role, agence?, statut, derniere_connexion`),
   `GET /api/roles` (catalogue + comptage). *Lecture additive ; `derniere_connexion` depuis `tentative_login`/`session`.*
   *(invitation / création de compte → §4.)*
5. **Appareils biométriques** — `AppareilController` : `GET /api/appareils` (lecture de la table
   `appareil_biometrique`, migration 004, déjà existante mais non exposée) + `GET /api/appareils/{id}`.
6. **Rapports (agrégats)** — `RapportController` : `GET /api/rapports/synthese?from=&to=&service=`
   (jauges présence/temps écran + donut Présents/Retards/Absents/Congés + tendance). *(export PDF → §4.)*
7. **Calendrier de présence d'un agent (vue admin)** — `GET /api/employes/{id}/presence?mois=YYYY-MM`
   (états par jour, fériés, taux, heures). Équivalent admin de `/api/me/pointages`, **non scopé au JWT**.
8. **Documents RH & historique RH** — migrations `044_create_document_rh`, `045_create_historique_rh`
   + `GET /api/employes/{id}/documents`, `GET /api/employes/{id}/historique-rh`. *Nouvelles tables, sûr.*

### D. Productivité globale — **nouvel endpoint**

- **`GET /api/productivite/global`** → `{ value, series[12], weeklyGrowth, tempsTravailleMoyen, inactiviteMoyenne }`
  (agrégat entreprise pour le bloc haut de Productivité.jsx + KPI Dashboard). *Lecture seule, sûr.*

---

## 4. ⏸️ À DISCUTER / METTRE DE CÔTÉ (risque ou décision produit)

> **Non implémenté.** À trancher avec le collègue — soit ça **modifie le modèle existant** (risque de casse),
> soit c'est une **décision produit** (ajouter un champ vs retirer de l'UI).

1. **`email`** — affiché partout dans le front, **inexistant en base**. → Décision : **ajouter une colonne `email`**
   (migration additive, faible risque) **ou** retirer le champ de l'UI. *Recommandation : colonne nullable.*
2. **`agence`** — c'est le **regroupement principal** du front (Siège Social, Agence Nord…), mais l'API n'a que
   `departement` et `poste`, **aucune notion d'agence**. → Décision **structurante** : créer une entité `agence`
   (table + FK sur employe/poste) **ou** mapper agence↔département. Impacte presque toutes les pages.
3. **`fonction` / `matiere` / `tauxHoraire`** — champs **métier école** (« Professeur d'anglais », « Anglais »,
   taux horaire) présents dans le front. `fonction` ≈ libellé `poste` ? `matiere`/`tauxHoraire` n'existent pas.
   → Décision de modélisation (réutiliser `poste` vs nouvelles colonnes).
4. **Statut employé riche** — le front affiche `Actif / En congé / En vacances / Pause maladie`, l'API n'a que
   l'enum `actif/suspendu/conge`. → Étendre l'enum (risque migration) ou dériver côté présence ? À trancher.
5. **Composition du salaire** — le front décompose `base / primes / avances / retenues / retardRetenue`. L'API
   ne stocke qu'un `salaire` unique et calcule un net auto (retard/absence). → **Modifie le modèle de paie**
   (risque sur le calcul existant + le kiosque). Mettre de côté : ajouter primes/retenues manuelles = chantier.
6. **Objectifs partagés (admin)** — `Objectifs.jsx` veut des objectifs **partagés/membres** ; l'API n'a que
   `/api/me/objectifs` (self-service, modèle incompatible). → Nouveau modèle `objectif` + `objectif_membre` à concevoir.
7. **Demandes** — l'enum `type` côté API ne couvre pas `Permission`/`Absence` du front ; pas de **création par un
   manager au nom d'un agent** ni de transition **« revenir en attente »**. → Étendre l'enum (risque) + endpoints.
8. **Canal « Tout le personnel » (broadcast messagerie)** — l'API ne connaît que `direct`/`groupe`.
   Ajouter un type `annonce`/broadcast **modifie l'enum conversation** (consommé par le kiosque ?). À valider.
9. **Export PDF des rapports** — nécessite une lib/infra (dompdf…) ; à cadrer séparément.
10. **`% de travail` / statut `Congé` sur le pointage** — notions affichées, **absentes du modèle pointage**.

---

## 5. Décisions produit bloquantes (à figer AVANT d'industrialiser les mappers)

- [ ] `email` : colonne en base **ou** retiré de l'UI ?
- [ ] `agence` : entité réelle **ou** alias de `département` ?
- [ ] `fonction`/`matiere`/`tauxHoraire` : réutiliser `poste` **ou** nouvelles colonnes ?
- [ ] Salaire : net auto simple **ou** composition saisie (primes/retenues) ?
- [ ] Statut employé : enum étendu **ou** dérivé présence ?

---

## 6. Garde-fous « rien ne casse » (à respecter pour toute implémentation)

- **NE PAS modifier la forme** des endpoints consommés par le **kiosque** et la **sync** :
  `POST /api/sessions/login-pin`, `/{id}/lock`, `/{id}/unlock`, `/{id}/logout`, `/{id}/activite`,
  `POST /api/sync`, `GET /api/postes/{code}/roster`, `GET /api/config/postes`, `GET /api/motifs`,
  `POST /api/auth/login*`. → uniquement **ajouter** des champs, jamais retirer/renommer.
- **Migrations 041+** uniquement (ne pas réutiliser de numéro ; NB : collision existante sur `032` à ne pas aggraver).
  Toute nouvelle colonne sur une table existante = **NULLABLE** + valeur par défaut.
- **Vérifier** chaque fichier modifié/créé : `php -l` (syntaxe) + `php database/migrate.php` à blanc sur une base de test.
- Tenir **`openapi.yaml`** à jour pour chaque nouvel endpoint (cohérence avec `/docs`).
- L'auth (`Auth::enforce`) protège déjà les routes ; les nouveaux endpoints admin doivent rester derrière le JWT.

---

## 7. Plan d'exécution (côté API)

1. **§3.A + §3.B + §3.D** (enrichissements additifs + temps-réel + productivité globale) — débloque le plus de pages, risque quasi nul.
2. **§3.C** nouvelles ressources lecture seule d'abord (appareils, rapports/synthèse, calendrier présence, utilisateurs/rôles).
3. **§3.C** ressources avec écriture (dépenses, journal des paiements, paramètres, documents/historique RH).
4. **openapi.yaml** + tests + `php database/migrate.php` sur base de test.
5. **§4 / §5** : session de décision avec le collègue → puis implémentation des points arbitrés.

> Côté **front** (hors de cette passe, **ne pas toucher au design**) : client API (`src/lib/api.js`),
> config `VITE_API_URL`, réécriture de l'auth, puis câblage page par page avec mappers de forme.
