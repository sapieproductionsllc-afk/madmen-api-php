# Configuration biométrique — ZKTeco (navigateur web)

Ce guide explique comment connecter un **lecteur d'empreintes ZKTeco** à l'application,
depuis une **page web**.

## Principe

La capture d'empreinte se fait **côté poste (navigateur)** via un **service local**
fourni par ZKTeco. Le navigateur ne parle jamais directement au lecteur USB : il appelle
ce service sur `localhost`, qui pilote le matériel et renvoie un **gabarit** (template).

```
Page web  ──HTTP──►  Service ZKTeco (localhost:8080)  ──USB──►  Lecteur d'empreintes
   │
   └─► POST /api/employes/{id}/biometrie  (l'API chiffre le gabarit et le stocke)
```

## Modèle détecté sur ce poste : **ZKTeco Live20R (SLK20R)**

`USB\VID_1B55&PID_0120` — lecteur d'empreintes USB.

## 1. À installer sur chaque poste

| # | Élément | Rôle |
|---|---|---|
| 1 | **ZKFinger SDK 5.x** | Installe le **pilote** du Live20R + la bibliothèque `libzkfp` |
| 2 | **ZKBioOnline** | **Service web local** : capture depuis le navigateur (WebSocket) |

> À télécharger depuis le **site officiel ZKTeco** (Support / Downloads) ou via ton
> revendeur. Ne pas installer depuis des sources non officielles.

### Vérifications après installation
1. **Pilote** : Gestionnaire de périphériques → le Live20R ne doit plus être en « Error ».
2. **Service** : ZKBioOnline écoute en général sur **`wss://127.0.0.1:8081`**
   (certificat auto-signé).
3. **Certificat** : ouvrir une fois **`https://127.0.0.1:8081`** dans le navigateur et
   accepter l'exception de sécurité — sinon la page web ne pourra pas s'y connecter.

> ZKBioOnline communique en **WebSocket** (pas en simple HTTP). Le module
> `public/assets/biometric.js` (méthode `capture()`) devra utiliser une connexion
> WebSocket vers ce service — adapter une fois le port/certificat confirmés.

## 2. Configuration (.env)

```ini
BIO_DEVICE=zkteco
BIO_BRIDGE_URL=http://127.0.0.1:8080   # URL du service local
BIO_SAMPLES=3                          # captures du même doigt pour enrôler
BIO_THRESHOLD=55                       # seuil de correspondance (0-100)
BIO_TEMPLATE_FORMAT=ansi               # ansi | iso | zk
BIO_SIMULATION=true                    # mettre false quand le lecteur est branché
```

Le front récupère ces valeurs via **`GET /api/config/biometrie`**.

## 3. Mode simulation

Tant que `BIO_SIMULATION=true`, l'application **fonctionne sans lecteur** : la capture
génère un faux gabarit. Cela permet de tester tout le flux d'enrôlement
(page `/enrolement.html`) et de vérifier que l'API stocke bien le gabarit chiffré.

Quand le vrai lecteur est en place :
1. Installer le pilote + le service ZKFinger WebSDK.
2. Passer `BIO_SIMULATION=false` dans le `.env`.
3. Vérifier l'URL `BIO_BRIDGE_URL`.

## 4. Adapter le module front

Le code d'appel au service ZKTeco est isolé dans **`public/assets/biometric.js`**
(méthode `capture()`). Selon la version du service installé, ajuster :
- l'URL des routes (`/capture`, `/status`),
- le nom du champ renvoyé contenant le gabarit base64.

## 5. Sécurité

- Le navigateur n'envoie que le **gabarit** (jamais l'image du doigt).
- L'API **chiffre** le gabarit (AES-256-GCM) avant stockage (`employe_biometrie.template`).
- En HTTPS, le service local doit aussi être en HTTPS (ou exception navigateur) — ZKTeco
  fournit généralement un certificat localhost pour le WebSDK.

## Vérification du matériel (où / qui fait quoi)

| Action | Où |
|---|---|
| Capture du doigt | **Front** (service ZKTeco local) |
| Construction du gabarit | **Front** (SDK ZKTeco) |
| Chiffrement + stockage | **Backend** (`/api/employes/{id}/biometrie`) |
| Comparaison au login | **Backend** (ou terminal autonome ZKTeco) |
