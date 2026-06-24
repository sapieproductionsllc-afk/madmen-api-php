# MadMen Serveur (PC du bureau) — pont K40 + Live20 → cloud

Ce dossier transforme **un** PC du bureau en **serveur silencieux** : il parle au **K40**
et au lecteur **Live20**, et **pousse tout au cloud** automatiquement. Il démarre **tout
seul** au boot, en arrière-plan — l'entreprise n'a **jamais** à y toucher.

> 🧠 **Le principe**
> - **1 PC du bureau** (celui qui voit le K40 sur le réseau + le Live20 en USB) = ce serveur.
> - **Tous les autres PC** = l'application **MadMen Admin** (`.exe`) qui lit le cloud.
> - Le matériel (K40, Live20) reste forcément sur ce PC : c'est physique.

---

## 1) À installer UNE FOIS sur le PC du bureau (par toi, à la livraison)

**Prérequis** (à installer une fois) :
- **PHP 8.4** (avec `pdo_mysql`) accessible dans le PATH (ou ajuste `PHP=` dans `start-serveur.bat`).
- **MySQL 8.4** (ajuste les chemins `MYSQLD` / `MYSQL_BASEDIR` dans `start-serveur.bat` si besoin).
- **Python + pyzk** (pour parler au K40).
- Les dossiers copiés côte à côte, par ex. :
  ```
  C:\MadMen\madmen-api-php   (l'API + ce dossier serveur\)
  C:\MadMen\madmen-agent     (l'agent Live20 : zkagent.exe)
  ```

**Config** — dans `C:\MadMen\madmen-api-php\.env` :
```
APP_ENV=local
AUTH_ENABLED=false
DB_HOST=127.0.0.1
DB_NAME=madmen
DB_USER=root
DB_PASS=...
K40_IP=192.168.1.xx          # l'IP du K40 sur le réseau du bureau
GATEWAY_TOKEN=...            # le jeton du relais (même que le cloud)
GATEWAY_URL=https://api-madmen.ssmanager.uk
```
Puis applique les migrations une fois : `php database\migrate.php`.

## 2) Activer le démarrage automatique
Clic droit sur **`installer-serveur.ps1`** → **Exécuter avec PowerShell**
*(ou : `powershell -ExecutionPolicy Bypass -File installer-serveur.ps1`)*

→ Crée la tâche **« MadMen Serveur »** qui démarre à chaque ouverture de session,
en arrière-plan, et la lance tout de suite.

## 3) C'est fini
Le PC du bureau :
- lit le **K40** et **pousse les pointages au cloud** toutes les 30 s,
- fait tourner l'**agent Live20** (8080) pour l'enrôlement,
- fait tourner l'**API locale** (8000).

**L'enrôlement des doigts se fait sur CE PC** (l'app y pointe vers l'API locale
`http://127.0.0.1:8000` → capture Live20 + envoi au K40 + remontée cloud).
Les autres PC utilisent l'app en mode **cloud** (consultation/gestion).

---

## Vérifier / dépanner
- Tâche présente ? `Planificateur de tâches` → « MadMen Serveur ».
- Logs de synchro : `serveur\sync.log` (si activé) ou la fenêtre minimisée « MadMen - Synchro K40 ».
- Test manuel : double-clic sur `start-serveur.bat` (idempotent, ne double rien).
- Le cloud reçoit bien ? Ouvre le dashboard en ligne → les pointages doivent apparaître.
