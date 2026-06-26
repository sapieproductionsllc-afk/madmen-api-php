<?php
declare(strict_types=1);

/**
 * Diagnostic LECTURE SEULE de l'état live du dashboard (En pause / Parti / Absent).
 * À lancer dans le conteneur API :
 *   docker compose -f deploy/docker-compose.yml exec -T api php scripts/diag_presence.php
 * Montre, par agent : statut pointage, heure d'entrée, dernier passage du jour,
 * "présent maintenant ?", fenêtre de pause, et l'état calculé par Presence::etatLive.
 */

require __DIR__ . '/../vendor/autoload.php';

use MadMen\Core\Database;
use MadMen\Core\Env;
use MadMen\Core\Presence;

// Mirror public/index.php : run in the app's configured timezone (sinon ce script
// CLI tournerait en UTC et ne refléterait PAS le comportement réel du dashboard web).
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Europe/Paris'));

$db = Database::connection();

$nowPhp = date('Y-m-d H:i:s');
$dbNow  = (string) $db->query('SELECT NOW()')->fetchColumn();
$g = Presence::defaultHoraire();

echo "=== CONTEXTE ===\n";
echo "PHP date_default_timezone_get : " . date_default_timezone_get() . "\n";
echo "PHP now   : {$nowPhp}\n";
echo "MySQL NOW : {$dbNow}\n";
echo "Fenetre pause globale (config) : {$g['dejeuner_debut']} - {$g['dejeuner_fin']}\n";
echo "Lignes pointage aujourd'hui   : " . (int) $db->query('SELECT COUNT(*) FROM pointage WHERE date = CURDATE()')->fetchColumn() . "\n";
echo "Passages aujourd'hui          : " . (int) $db->query('SELECT COUNT(*) FROM pointage_passage WHERE date = CURDATE()')->fetchColumn() . "\n\n";

// Dernier passage du jour par employé.
$last = [];
foreach ($db->query(
    "SELECT employe_id, type, horodatage FROM (
        SELECT employe_id, type, horodatage,
               ROW_NUMBER() OVER (PARTITION BY employe_id ORDER BY horodatage DESC, id DESC) rn
        FROM pointage_passage WHERE date = CURDATE()
     ) t WHERE rn = 1"
)->fetchAll() as $r) {
    $last[(int) $r['employe_id']] = ['type' => $r['type'], 'ts' => (string) $r['horodatage']];
}

$agents = $db->query(
    "SELECT e.id, TRIM(CONCAT(e.prenom, ' ', e.nom)) AS name,
            COALESCE(pt.statut, IF(e.statut = 'conge', 'conge', 'absent')) AS statut,
            pt.heure_entree AS arrivee, he.heure_arrivee, he.heure_depart, he.pause_debut, he.pause_fin
     FROM employe e
     LEFT JOIN pointage pt        ON pt.employe_id = e.id AND pt.date = CURDATE()
     LEFT JOIN horaire_employe he ON he.employe_id = e.id
     WHERE e.statut <> 'suspendu'
     ORDER BY e.nom, e.prenom"
)->fetchAll();

$now = date('Y-m-d H:i:s');
$def = Presence::defaultHoraire();
$counts = [];

printf("%-22s | %-8s | %-9s | %-6s | %-8s | %s\n", 'NOM', 'raw', 'arrivee', 'lastPP', 'present?', '-> etatLive');
echo str_repeat('-', 78) . "\n";
foreach ($agents as $a) {
    $dp = $last[(int) $a['id']] ?? null;
    $type = $dp['type'] ?? null;
    $aPointe = $a['arrivee'] !== null;
    $present = $type !== null ? ($type === 'entree') : ((string) $a['statut'] !== 'parti');
    $sortieTs = $type === 'sortie' ? ($dp['ts'] ?? null) : null;
    $h = [
        'debut'          => $a['heure_arrivee'] !== null ? substr((string) $a['heure_arrivee'], 0, 5) : $def['debut'],
        'fin'            => $a['heure_depart'] !== null ? substr((string) $a['heure_depart'], 0, 5) : $def['fin'],
        'dejeuner_debut' => $a['pause_debut'] !== null ? substr((string) $a['pause_debut'], 0, 5) : $def['dejeuner_debut'],
        'dejeuner_fin'   => $a['pause_fin'] !== null ? substr((string) $a['pause_fin'], 0, 5) : $def['dejeuner_fin'],
    ];
    $etat = Presence::etatLive((string) $a['statut'], $aPointe, $present, $now, $h, $sortieTs);
    $counts[$etat] = ($counts[$etat] ?? 0) + 1;
    printf(
        "%-22s | %-8s | %-9s | %-6s | %-8s | %s\n",
        mb_substr((string) $a['name'], 0, 22),
        (string) $a['statut'],
        $a['arrivee'] !== null ? (string) $a['arrivee'] : 'NULL',
        $type ?? 'none',
        $present ? 'yes' : 'no',
        $etat
    );
}
echo str_repeat('-', 78) . "\n";
echo "TOTAUX : ";
foreach ($counts as $k => $v) {
    echo "$k=$v  ";
}
echo "\n";
