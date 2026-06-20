# Pointeuse K40 — Arrivée / Départ par empreinte

Le **ZKTeco K40** est un **terminal de pointage autonome** (mural) connecté en **réseau
TCP/IP** (port **4370**). Les employés pointent leur **arrivée** et leur **départ** par
empreinte directement sur l'appareil ; le backend récupère ensuite les transactions.

```
Employé pointe sur le K40 (entrée/sortie)
   └─ le K40 stocke la transaction (user_id + heure)
         └─ l'API interroge le K40 par le réseau (sync)
               └─ remplit la table `pointage` (arrivée = 1er punch, départ = dernier)
```

> Différent du **Live20R** (lecteur USB sur PC, via l'agent local). Le K40 est **réseau**
> et **autonome** : il garde ses propres employés/empreintes/journaux.

## Deux modes de communication (au choix via `K40_MODE`)

| Mode | Qui contacte qui | Quand l'utiliser |
|---|---|---|
| **`pull`** (défaut) | L'API → le K40 (port 4370) | Serveur et K40 sur le **même réseau local** |
| **`push`** (ADMS) | Le K40 → l'API (HTTP `/iclock`) | K40 **distant** : il sort vers le serveur, traverse le NAT |
| **`both`** | Les deux | Souplesse maximale |

### Mode PULL (LAN local)
L'API interroge le terminal et récupère les pointages. Voir §4 ci-dessous
(`/api/k40/sync`). Nécessite que le serveur **atteigne** le K40 sur le réseau.

### Mode PUSH / ADMS (le K40 envoie)
Le terminal est configuré pour **pousser** vers l'API (Menu → Comm → **Cloud Server /
ADMS** → adresse + port du serveur). L'API expose le protocole `iclock` :

| Route | Rôle |
|---|---|
| `GET /iclock/cdata` | Handshake (options + Stamp) |
| `POST /iclock/cdata?table=ATTLOG` | Le terminal pousse les pointages → table `pointage` |
| `GET /iclock/getrequest` | Le terminal demande des commandes |
| `POST /iclock/devicecmd` | Résultat des commandes |

Ces routes sont **publiques** (le terminal ne peut pas envoyer de clé API) ; elles sont
identifiées par le **numéro de série (SN)** du terminal. La logique arrivée/départ est
la même que le mode Pull (service partagé `K40Pointage`).

## 1. Brancher et configurer le K40

1. Relier le K40 au réseau (câble Ethernet vers le switch/box).
2. Sur le menu du terminal : **Menu → Comm → Réseau (Ethernet)** :
   - Donner une **IP fixe** (ex. `192.168.1.201`), masque, passerelle.
   - **Port** : `4370` (par défaut).
   - **Clé de comm (Comm Key)** : `0` (sinon la renseigner dans `.env`).
3. Vérifier depuis le PC : `ping 192.168.1.201` doit répondre.

## 2. Configurer l'application (.env)

```ini
K40_ENABLED=true
K40_IP=192.168.1.201
K40_PORT=4370
K40_PASSWORD=0
K40_HEURE_LIMITE=08:15   # au-delà = retard
```

## 3. Associer les employés au terminal

Chaque employé doit avoir un **identifiant côté K40** (`user_id`). Deux options :

- **Pousser depuis l'app** : `POST /api/k40/push-user/{id}` crée l'utilisateur sur le K40
  (userid = `device_user_id` de l'employé, sinon son `id`). Le mapping est mémorisé dans
  la colonne `employe.device_user_id`.
- **Enrôler sur le terminal** : créer l'utilisateur sur le K40 avec un `user_id` égal à
  l'`id` (ou au `device_user_id`) de l'employé.

> ⚠️ Le K40 enrôle l'**empreinte sur l'appareil** (capteur intégré). Les gabarits du
> Live20R (USB) et du K40 ne sont pas forcément interchangeables.

## 4. Synchroniser les pointages

- **À la demande** : `POST /api/k40/sync`
- **Automatique** : planifier `php database/k40_sync.php` (Planificateur de tâches Windows)
  toutes les 5–10 min.

La synchro est **idempotente** : seul ce qui est postérieur à la dernière synchro
(`k40_state.last_sync_at`) est traité. Logique : **1er punch du jour = arrivée**
(`heure_entree`, retard si > `K40_HEURE_LIMITE`), **punch suivant = départ**
(`heure_sortie`). Les pointages sont rattachés à l'appareil `K40-POINTEUSE`.

## 5. Endpoints

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/k40/status` | Tester la connexion |
| POST | `/api/k40/sync` | Récupérer/enregistrer les pointages |
| GET | `/api/k40/users` | Lister les utilisateurs du terminal |
| POST | `/api/k40/push-user/{id}` | Envoyer un employé au terminal |

## Dépannage

- **connected:false / injoignable** : vérifier IP, câble, `ping`, et `K40_ENABLED=true`.
- **employes_inconnus** dans la synchro : des `user_id` du K40 n'ont pas d'employé
  correspondant → renseigner `device_user_id` ou pousser l'employé.
- **Clé de comm** : si le terminal a une Comm Key ≠ 0, la connexion peut échouer
  (la lib `rats/zkteco` ne la gère pas) → mettre la Comm Key du K40 à `0`.
