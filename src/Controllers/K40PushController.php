<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Database;
use MadMen\Core\Env;
use MadMen\Core\K40;
use MadMen\Core\K40Pointage;
use MadMen\Core\Response;

/**
 * Mode PUSH / ADMS : c'est le terminal K40 qui se connecte à l'API (protocole
 * « iclock »). Utile quand le serveur ne peut pas atteindre le K40 (réseau
 * distant), car le terminal sort en HTTP vers le serveur.
 *
 * Le K40 doit être configuré : Menu → Comm → Cloud Server (ADMS) → adresse du
 * serveur + port. Les réponses sont en text/plain (format attendu par ZKTeco).
 */
final class K40PushController
{
    /**
     * Vérifie le numéro de série (?SN=) du terminal contre la liste blanche K40.
     *
     * Les routes /iclock/* sont publiques (le terminal ADMS ne peut pas envoyer
     * de clé API), donc le SN est le seul garde-fou contre des pointages forgés.
     *
     * @return string|null Le SN validé, ou null si la requête doit être refusée
     *                     (dans ce cas la réponse de refus a déjà été émise).
     */
    private function verifierSn(): ?string
    {
        $sn = isset($_GET['SN']) ? trim((string) $_GET['SN']) : '';

        $cfg = require dirname(__DIR__, 2) . '/config/k40.php';
        $allowed = $cfg['push_sn'] ?? [];
        if (!is_array($allowed)) {
            $allowed = [];
        }

        if ($allowed === []) {
            // En PRODUCTION : refuser si la liste blanche n'est pas configurée
            // (sinon n'importe quel terminal pourrait forger des pointages).
            if (Env::get('APP_ENV') === 'production') {
                Response::text("ERROR: SN non configuré (K40_PUSH_SN requis)\n", 403);
                return null;
            }
            // Dev : liste blanche non configurée, on accepte mais on trace.
            error_log('AVERTISSEMENT iclock: SN non vérifié (K40_PUSH_SN vide)');
            return $sn;
        }

        // Liste blanche active : le SN doit être présent et autorisé.
        if ($sn === '' || !in_array($sn, $allowed, true)) {
            // Pas de 200 OK et on n'enregistre rien.
            Response::text("ERROR: SN non autorisé\n", 403);
            return null;
        }

        return $sn;
    }

    /** GET /iclock/cdata — handshake initial du terminal. */
    public function handshake(): void
    {
        $sn = $this->verifierSn();
        if ($sn === null) {
            return;
        }
        // Stamp=0 => le terminal renvoie tous les pointages non transmis.
        $body = "GET OPTION FROM: {$sn}\n"
            . "Stamp=0\n"
            . "OpStamp=0\n"
            . "ErrorDelay=30\n"
            . "Delay=10\n"
            . "TransTimes=00:00;14:05\n"
            . "TransInterval=1\n"
            . "TransFlag=1111000000\n"
            . "TimeZone=0\n"
            . "Realtime=1\n"
            . "Encrypt=0\n";
        Response::text($body);
    }

    /**
     * POST /iclock/cdata — le terminal pousse ses données.
     * table=ATTLOG : pointages. Corps = lignes « userid\ttimestamp\tstatus\t... ».
     */
    public function receive(): void
    {
        if ($this->verifierSn() === null) {
            return;
        }

        $table = isset($_GET['table']) ? strtoupper((string) $_GET['table']) : '';

        if ($table !== 'ATTLOG') {
            // OPERLOG / autres tables : acquittées sans traitement.
            Response::text("OK\n");
        }

        $raw = file_get_contents('php://input') ?: '';
        // Borne anti-DoS LARGE : 8 Mo couvrent un buffer K40 plein (cap 80 000 lignes
        // ~40 octets = ~3 Mo). Un lot légitime, même après une longue coupure, passe
        // donc toujours sous la limite -> plus de 413 bloquant qui ferait re-boucler le
        // terminal à l'infini et risquerait un débordement du buffer. (Backstop : le PULL
        // relit le device — jamais purgé — et récupère tout de toute façon.)
        if (strlen($raw) > 8388608) { // 8 Mo
            error_log('iclock: lot ATTLOG > 8 Mo refusé (SN=' . ($_GET['SN'] ?? '?')
                . ', octets=' . strlen($raw) . ') — déclencher une synchro PULL');
            Response::text("ERROR: corps trop volumineux\n", 413);
            return;
        }
        $cfg = K40::config();
        $db = Database::connection();

        $n = 0;
        $rejetes = 0;
        foreach (preg_split('/\r\n|\n|\r/', trim($raw)) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode("\t", $line);
            $userId = trim($parts[0] ?? '');
            $timestamp = trim($parts[1] ?? '');
            if ($userId === '' || $timestamp === '') {
                continue;
            }
            // GARDE anti-malformé : /iclock est PUBLIC et reçoit parfois du binaire (scan/bot,
            // handshake ZKTeco mal formé...). On REJETTE les lignes dont l'identifiant contient
            // des caractères non imprimables (ou est anormalement long) OU dont l'horodatage
            // n'est pas une date plausible — au lieu de polluer k40_punch_brut avec des entrées
            // 'inconnu' parasites (ex. id "\x01y2", horodatage par défaut 2000-01-01).
            if (
                strlen($userId) > 64
                || preg_match('/[^\x20-\x7E]/', $userId) === 1
                || !self::horodatageValide($timestamp)
            ) {
                $rejetes++;
                continue;
            }
            K40Pointage::record($db, $userId, $timestamp, $cfg['heure_limite']);
            $n++;
        }
        if ($rejetes > 0) {
            error_log('iclock: ' . $rejetes . ' ligne(s) ATTLOG malformée(s) rejetée(s) (SN='
                . ($_GET['SN'] ?? '?') . ')');
        }

        // ZKTeco attend « OK: <nombre de lignes traitées> ».
        Response::text("OK: {$n}\n");
    }

    /**
     * L'horodatage ATTLOG est-il une vraie date « Y-m-d H:i:s » plausible ?
     * Rejette le binaire, les formats inattendus et la date par défaut 2000-01-01
     * (ce que produit un parse raté), en bornant l'année à une plage raisonnable.
     */
    private static function horodatageValide(string $ts): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d H:i:s', $ts);
        if (!$d || $d->format('Y-m-d H:i:s') !== $ts) {
            return false;
        }
        $annee = (int) $d->format('Y');
        return $annee >= 2020 && $annee <= 2100;
    }

    /** GET /iclock/getrequest — le terminal demande s'il y a des commandes. */
    public function getrequest(): void
    {
        if ($this->verifierSn() === null) {
            return;
        }
        // Aucune commande à envoyer pour l'instant.
        Response::text("OK\n");
    }

    /** POST /iclock/devicecmd — le terminal renvoie le résultat des commandes. */
    public function devicecmd(): void
    {
        if ($this->verifierSn() === null) {
            return;
        }
        Response::text("OK\n");
    }
}
