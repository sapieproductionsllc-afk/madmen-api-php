# MadMen — Guide d'installation

> Système de pointage biométrique (K40 + lecteur d'empreintes Live20) avec tableau
> de bord en ligne. Ce guide explique l'installation **du début à la fin** sur le site
> du client. Comptez **~30 minutes** la première fois.

---

## 1. Comment ça marche (en 30 secondes)

- **1 PC au bureau** (le « PC serveur ») est branché au réseau du **K40** et au lecteur
  **Live20** (USB). Il fait le pont : il lit les pointages et les **envoie au cloud**.
- **Le cloud** garde toutes les données (employés, pointages, salaires…).
- **Tous les ordinateurs** (direction, RH, à distance…) ouvrent l'**application** et voient
  les données en temps réel, de n'importe où.

```
   📟 K40  ──┐                       ☁️  CLOUD (données)
   🔵 Live20 ┤   PC SERVEUR (bureau)  ──────►  ◄────── 💻 Autres PC (application)
            └►  envoie tout au cloud
```

> ⚠️ Le **K40** et le **Live20** sont du matériel : ils ne fonctionnent que par
> l'intermédiaire du **PC serveur** du bureau (sur le même réseau). Les autres PC,
> eux, marchent **partout** avec internet.

---

## 2. Ce qu'il vous faut

| Élément | Détail |
|---|---|
| **K40** | branché au réseau local (Ethernet/Wi-Fi), avec une **IP fixe** connue |
| **Live20** | branché en **USB** sur le PC serveur |
| **PC serveur** | Windows 10/11, **toujours allumé** aux heures de travail, sur le réseau du K40 |
| **Autres PC** | Windows 10/11 avec **internet** |
| **Les 2 installeurs** | `MadMen-Admin-LOCAL-setup.exe` et `MadMen-Admin-setup.exe` |

---

## 3. Installer le PC serveur (le PC du bureau)

### 3.1 Logiciels prérequis (une fois)
Sur le PC serveur, installer :
- **PHP 8.4** (avec `pdo_mysql`) — ajouté au PATH.
- **MySQL 8.4**.
- **Python 3** + la librairie **pyzk** (`pip install pyzk`).

### 3.2 Copier les fichiers
Copier les dossiers côte à côte, par exemple :
```
C:\MadMen\madmen-api-php     (le serveur + le dossier "serveur\")
C:\MadMen\madmen-agent       (l'agent Live20 : zkagent.exe)
```

### 3.3 Configurer
Dans `C:\MadMen\madmen-api-php\.env` :
```
APP_ENV=local
AUTH_ENABLED=false
DB_HOST=127.0.0.1
DB_NAME=madmen
DB_USER=root
DB_PASS=<mot de passe MySQL>
K40_IP=192.168.1.XX            ← l'IP du K40 sur le réseau
GATEWAY_TOKEN=<jeton fourni>   ← pour envoyer au cloud
GATEWAY_URL=https://api-madmen.ssmanager.uk
```
Puis, une fois, dans `C:\MadMen\madmen-api-php` :
```
php database\migrate.php
```

### 3.4 Activer le démarrage automatique
Aller dans `C:\MadMen\madmen-api-php\serveur\`, **clic droit** sur
**`installer-serveur.ps1`** → **Exécuter avec PowerShell**.

➡️ Le serveur démarre **et se relancera tout seul** à chaque allumage du PC.
Vous n'avez **plus jamais** à y toucher.

> Vérification : ouvrez le *Planificateur de tâches* Windows → la tâche
> **« MadMen Serveur »** doit être présente. 2-3 petites fenêtres minimisées
> tournent en bas (MySQL, API, Synchro K40) — c'est normal, laissez-les.

---

## 4. Installer l'application

### 4.1 Sur le PC serveur → version **LOCALE**
Installer **`MadMen-Admin-LOCAL-setup.exe`**.
*(C'est avec celle-ci qu'on enrôle les empreintes : elle parle directement au K40.)*

### 4.2 Sur tous les autres PC → version **CLOUD**
Installer **`MadMen-Admin-setup.exe`**.
*(Direction, RH, à distance — juste un navigateur d'app, rien d'autre à régler.)*

> 📌 Transférez les `.exe` par **clé USB ou Drive** (pas par WhatsApp, qui les abîme).

---

## 5. Première connexion
Ouvrir **MadMen Admin** → se connecter avec :
- **Matricule :** `ADMIN-001`
- **Code PIN :** `1234`

> 🔒 Changez ce code dès la première connexion (section Administration > Identifiants).

---

## 6. Enrôler un employé (empreinte)
**À faire sur le PC serveur** (application LOCALE) :
1. Menu → **Enrôlement** (ou « Ajouter un employé »).
2. Renseigner le **nom** (seul champ obligatoire), + infos RH si voulu.
3. Étape empreinte → poser le doigt sur le **Live20** (3 passages).
4. Terminer → l'empreinte est envoyée au **K40** et l'employé est créé.

L'employé peut alors **pointer sur le K40** avec son doigt.

---

## 7. Test de bout en bout (5 min)
1. Enrôler un employé test (PC serveur).
2. Le faire **pointer sur le K40**.
3. Attendre ~30 secondes.
4. Sur **un autre PC** (app cloud), ouvrir le **tableau de bord** → le pointage doit
   **apparaître**. ✅ Si oui, tout le système fonctionne.

---

## 8. Au quotidien (pour l'entreprise)
- Les employés **pointent sur le K40** (entrée/sortie au doigt).
- Tout remonte **automatiquement** au cloud (toutes les 30 s).
- La **direction / RH** consulte présences, retards, paie, salaires, jours fériés
  depuis **n'importe quel PC** (app cloud), de n'importe où.
- **Aucune manipulation** technique au quotidien.

---

## 9. Dépannage

| Problème | Solution |
|---|---|
| Les pointages n'arrivent pas au cloud | Le PC serveur est-il allumé ? Tâche « MadMen Serveur » active ? K40 allumé et bonne `K40_IP` ? |
| L'empreinte ne s'enregistre pas | Live20 bien branché en USB ? Utiliser l'app **LOCALE** sur le PC serveur. |
| « Identifiants invalides » | Vérifier matricule/PIN. Réinitialiser via le support. |
| Les icônes de l'app n'apparaissent pas | Le PC a-t-il internet ? (les icônes se chargent en ligne) |
| Relancer le serveur manuellement | Double-clic sur `serveur\start-serveur.bat` (sans risque). |

---

## 10. Récapitulatif des accès
| | Adresse / valeur |
|---|---|
| Tableau de bord en ligne | via l'app **MadMen Admin** (cloud) |
| API en ligne | `https://api-madmen.ssmanager.uk` |
| Connexion par défaut | `ADMIN-001` / `1234` *(à changer)* |

---

*MadMen — pointage biométrique. Support : <à compléter>.*
