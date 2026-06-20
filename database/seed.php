<?php
declare(strict_types=1);

/**
 * Seeder de données de démonstration — PHP natif (PDO).
 *
 * Génère ~6 mois de données ouvrées pour 17 employés :
 * départements, postes, employés (hiérarchie + PIN haché + biométrie),
 * postes de travail, lecteurs biométriques, puis pour chaque jour ouvré :
 * pointages, sessions, activité, incidents d'inactivité, alertes, productivité.
 *
 * Usage :  php database/seed.php
 * (idempotent : vide les tables de données avant de regénérer)
 */

$cfg = require __DIR__ . '/../config/database.php';

$pdo = new PDO(
    "mysql:host={$cfg['host']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']}",
    $cfg['username'],
    $cfg['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

mt_srand(20260619); // reproductible

// ---------------------------------------------------------------------------
// 0) Nettoyage
// ---------------------------------------------------------------------------
$tables = [
    'activite_echantillon', 'alerte', 'incident_inactivite', 'tentative_connexion',
    'productivite_jour', 'session_travail', 'pointage', 'autorisation_poste',
    'demande_conge', 'solde_conge', 'employe_biometrie', 'poste_travail',
    'appareil_biometrique', 'motif_absence', 'type_conge', 'employe', 'poste',
    'departement', 'jour_ferie',
];
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($tables as $t) {
    $pdo->exec("TRUNCATE TABLE `$t`");
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
echo "Tables vidées.\n";

// ---------------------------------------------------------------------------
// 1) Départements
// ---------------------------------------------------------------------------
$departements = [
    'Direction'           => 'DIR',
    'Comptabilité'        => 'COMP',
    'Informatique'        => 'INFO',
    'Ressources Humaines' => 'RH',
    'Commercial'          => 'COM',
];
$depId = [];
$ins = $pdo->prepare('INSERT INTO departement (nom, code) VALUES (?, ?)');
foreach ($departements as $nom => $code) {
    $ins->execute([$nom, $code]);
    $depId[$nom] = (int) $pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// 2) Postes
// ---------------------------------------------------------------------------
$postesCatalog = [
    'Directeur Général'    => 'Direction',
    'Chef Comptable'       => 'Comptabilité',
    'Comptable'            => 'Comptabilité',
    'Assistant Comptable'  => 'Comptabilité',
    'Chef Informatique'    => 'Informatique',
    'Développeur'          => 'Informatique',
    'Technicien Support'   => 'Informatique',
    'Responsable RH'       => 'Ressources Humaines',
    'Chargé RH'            => 'Ressources Humaines',
    'Chef Commercial'      => 'Commercial',
    'Commercial'           => 'Commercial',
    'Assistant Commercial' => 'Commercial',
];
$posteId = [];
$ins = $pdo->prepare('INSERT INTO poste (intitule, departement_id) VALUES (?, ?)');
foreach ($postesCatalog as $intitule => $dep) {
    $ins->execute([$intitule, $depId[$dep]]);
    $posteId[$intitule] = (int) $pdo->lastInsertId();
}

// ---------------------------------------------------------------------------
// 3) Employés (17) avec hiérarchie
// ---------------------------------------------------------------------------
// [nom, prenom, departement, poste, role(dg|chef|emp), salaire]
$employes = [
    ['Bernard',   'Alain',    'Direction',           'Directeur Général',    'dg',   12000],
    ['Moreau',    'Sylvie',   'Comptabilité',        'Chef Comptable',       'chef', 7500],
    ['Petit',     'Julien',   'Comptabilité',        'Comptable',            'emp',  3800],
    ['Durand',    'Camille',  'Comptabilité',        'Assistant Comptable',  'emp',  3000],
    ['Lefebvre',  'Marc',     'Informatique',        'Chef Informatique',    'chef', 8000],
    ['Rousseau',  'Thomas',   'Informatique',        'Développeur',          'emp',  4500],
    ['Girard',    'Léa',      'Informatique',        'Développeur',          'emp',  4500],
    ['Mercier',   'Hugo',     'Informatique',        'Technicien Support',   'emp',  3500],
    ['Blanc',     'Nathalie', 'Ressources Humaines', 'Responsable RH',       'chef', 7000],
    ['Faure',     'Sophie',   'Ressources Humaines', 'Chargé RH',            'emp',  3600],
    ['Garnier',   'Paul',     'Ressources Humaines', 'Chargé RH',            'emp',  3600],
    ['Chevalier', 'Isabelle', 'Commercial',          'Chef Commercial',      'chef', 7800],
    ['Robin',     'Lucas',    'Commercial',          'Commercial',           'emp',  3400],
    ['Masson',    'Emma',     'Commercial',          'Commercial',           'emp',  3400],
    ['Henry',     'Nicolas',  'Commercial',          'Commercial',           'emp',  3400],
    ['Gauthier',  'Chloé',    'Commercial',          'Assistant Commercial', 'emp',  3000],
    ['Roux',      'David',    'Comptabilité',        'Comptable',            'emp',  3800],
];

$insEmp = $pdo->prepare(
    'INSERT INTO employe
        (matricule, nom, prenom, poste_id, departement_id, superieur_id, telephone,
         contact_urgence_nom, contact_urgence_tel, salaire, code_pin_hash, statut, role)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);

$empIds = [];
$dgId = null;
$chefByDep = [];

foreach ($employes as $i => $e) {
    [$nom, $prenom, $dep, $poste, $role, $salaire] = $e;

    $superieur = null;
    if ($role === 'chef') {
        $superieur = $dgId;
    } elseif ($role === 'emp') {
        $superieur = $chefByDep[$dep] ?? $dgId;
    }

    $matricule = sprintf('EMP-%04d', $i + 1);
    $pinHash = password_hash((string) (1000 + $i), PASSWORD_BCRYPT);
    $tel = '06' . mt_rand(10000000, 99999999);
    $roleDb = ['dg' => 'super_admin', 'chef' => 'superviseur', 'emp' => 'employe'][$role] ?? 'employe';

    $insEmp->execute([
        $matricule, $nom, $prenom, $posteId[$poste], $depId[$dep], $superieur, $tel,
        'Contact ' . $prenom, '07' . mt_rand(10000000, 99999999), $salaire, $pinHash, 'actif', $roleDb,
    ]);
    $id = (int) $pdo->lastInsertId();
    $empIds[] = ['id' => $id, 'dep' => $dep, 'role' => $role];

    if ($role === 'dg') {
        $dgId = $id;
    } elseif ($role === 'chef') {
        $chefByDep[$dep] = $id;
    }
}
echo count($empIds) . " employés créés.\n";

// ---------------------------------------------------------------------------
// 4) Lecteurs biométriques + biométrie employés
// ---------------------------------------------------------------------------
$insApp = $pdo->prepare('INSERT INTO appareil_biometrique (nom, type, emplacement, numero_serie, statut) VALUES (?,?,?,?,?)');
$insApp->execute(['Lecteur Empreinte Entrée', 'empreinte', 'Entrée principale', 'SN-EMP-001', 'en_ligne']);
$appEmpreinte = (int) $pdo->lastInsertId();
$insApp->execute(['Lecteur RFID', 'rfid', 'Hall', 'SN-RFID-001', 'en_ligne']);
$appRfid = (int) $pdo->lastInsertId();
$insApp->execute(['Caméra Faciale', 'facial', 'Accueil', 'SN-FAC-001', 'en_ligne']);
$appFacial = (int) $pdo->lastInsertId();
$appareils = [$appEmpreinte, $appRfid, $appFacial];

$insBio = $pdo->prepare('INSERT INTO employe_biometrie (employe_id, type, doigt, template, badge_rfid, actif) VALUES (?,?,?,?,?,?)');
foreach ($empIds as $k => $emp) {
    $insBio->execute([$emp['id'], 'empreinte', 'index_droit', random_bytes(32), null, 1]);
    $insBio->execute([$emp['id'], 'rfid', null, null, sprintf('RFID-%05d', 1000 + $k), 1]);
}

// ---------------------------------------------------------------------------
// 5) Postes de travail + autorisations
// ---------------------------------------------------------------------------
$insPT = $pdo->prepare('INSERT INTO poste_travail (code, nom, departement_id, adresse_ip, statut) VALUES (?,?,?,?,?)');
$insAuth = $pdo->prepare('INSERT INTO autorisation_poste (employe_id, poste_travail_id) VALUES (?,?)');
$posteTravailByEmp = [];
$counterByDep = [];
foreach ($empIds as $emp) {
    $code = $departements[$emp['dep']];
    $n = ($counterByDep[$code] ?? 0) + 1;
    $counterByDep[$code] = $n;
    $ptCode = sprintf('PC-%s-%02d', $code, $n);
    $insPT->execute([$ptCode, $ptCode, $depId[$emp['dep']], '192.168.1.' . (10 + $emp['id']), 'libre']);
    $ptId = (int) $pdo->lastInsertId();
    $posteTravailByEmp[$emp['id']] = $ptId;
    $insAuth->execute([$emp['id'], $ptId]);
}

// ---------------------------------------------------------------------------
// 6) Référentiels : motifs d'absence + types de congé
// ---------------------------------------------------------------------------
$motifs = ['Pause toilette', 'Réunion', 'Appel professionnel', 'Pause café', 'Intervention technique', 'Autre'];
$insMotif = $pdo->prepare('INSERT INTO motif_absence (libelle) VALUES (?)');
$motifIds = [];
foreach ($motifs as $m) {
    $insMotif->execute([$m]);
    $motifIds[] = (int) $pdo->lastInsertId();
}

$insTypeConge = $pdo->prepare('INSERT INTO type_conge (libelle, paye) VALUES (?,?)');
$insTypeConge->execute(['Congé annuel', 1]);
$typeCongeAnnuel = (int) $pdo->lastInsertId();
$insTypeConge->execute(['Maladie', 1]);
$typeMaladie = (int) $pdo->lastInsertId();
$insTypeConge->execute(['Sans solde', 0]);

// Soldes de congé (année courante)
$annee = (int) date('Y');
$insSolde = $pdo->prepare('INSERT INTO solde_conge (employe_id, type_conge_id, annee, jours_acquis, jours_pris) VALUES (?,?,?,?,?)');
foreach ($empIds as $emp) {
    $insSolde->execute([$emp['id'], $typeCongeAnnuel, $annee, 18.00, 0.00]);
}

// Jours fériés (à exclure)
$joursFeries = ['2026-01-01' => "Jour de l'an", '2026-05-01' => 'Fête du travail'];
$insFerie = $pdo->prepare('INSERT INTO jour_ferie (date, libelle) VALUES (?,?)');
foreach ($joursFeries as $d => $lib) {
    $insFerie->execute([$d, $lib]);
}

// ---------------------------------------------------------------------------
// 7) Boucle 6 mois ouvrés
// ---------------------------------------------------------------------------
$insPointage = $pdo->prepare(
    'INSERT INTO pointage (employe_id, appareil_id, date, heure_entree, heure_sortie, methode, retard_minutes, statut)
     VALUES (?,?,?,?,?,?,?,?)'
);
$insSession = $pdo->prepare(
    'INSERT INTO session_travail (employe_id, poste_travail_id, heure_debut, heure_fin, methode_auth, autorisation_ok, statut, duree_active_sec, duree_inactive_sec)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$insActivite = $pdo->prepare(
    'INSERT INTO activite_echantillon (session_id, horodatage, mouvements_souris, frappes_clavier, app_active, niveau_activite)
     VALUES (?,?,?,?,?,?)'
);
$insIncident = $pdo->prepare(
    'INSERT INTO incident_inactivite (session_id, employe_id, poste_travail_id, heure_verrouillage, heure_reprise, duree_minutes, motif_id, justification, statut)
     VALUES (?,?,?,?,?,?,?,?,?)'
);
$insAlerte = $pdo->prepare(
    'INSERT INTO alerte (type, employe_id, poste_travail_id, destinataire_id, message, horodatage, lu)
     VALUES (?,?,?,?,?,?,?)'
);
$insProd = $pdo->prepare(
    'INSERT INTO productivite_jour (employe_id, date, temps_presence_min, temps_travaille_min, temps_inactivite_min, nb_arrets, retard_minutes, taux_productivite)
     VALUES (?,?,?,?,?,?,?,?)'
);

$apps = ['Excel', 'Outlook', 'Chrome', 'Word', 'SAP', 'VS Code', 'Teams'];

// Hiérarchie : retrouver le supérieur de chaque employé
$superieurByEmp = [];
foreach ($empIds as $emp) {
    if ($emp['role'] === 'dg') {
        $superieurByEmp[$emp['id']] = null;
    } elseif ($emp['role'] === 'chef') {
        $superieurByEmp[$emp['id']] = $dgId;
    } else {
        $superieurByEmp[$emp['id']] = $chefByDep[$emp['dep']] ?? $dgId;
    }
}

$start = (new DateTime('today'))->modify('-6 months');
$end = new DateTime('today');

$stats = ['pointages' => 0, 'sessions' => 0, 'incidents' => 0, 'alertes' => 0, 'jours' => 0];

$pdo->beginTransaction();

for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
    $weekday = (int) $d->format('N');
    if ($weekday >= 6) {
        continue; // week-end
    }
    $date = $d->format('Y-m-d');
    if (isset($joursFeries[$date])) {
        continue; // férié
    }
    $stats['jours']++;

    foreach ($empIds as $emp) {
        $eid = $emp['id'];
        $ptId = $posteTravailByEmp[$eid];
        $roll = mt_rand(1, 100);

        // ~3% congé, ~2% absent, sinon présent
        if ($roll <= 3) {
            $insPointage->execute([$eid, null, $date, null, null, null, 0, 'conge']);
            $stats['pointages']++;
            continue;
        }
        if ($roll <= 5) {
            $insPointage->execute([$eid, null, $date, null, null, null, 0, 'absent']);
            $stats['pointages']++;
            // alerte absence au supérieur
            if ($superieurByEmp[$eid] !== null) {
                $insAlerte->execute(['absence', $eid, $ptId, $superieurByEmp[$eid],
                    'Absence non justifiée', $date . ' 09:00:00', mt_rand(0, 1)]);
                $stats['alertes']++;
            }
            continue;
        }

        // --- Présent ---
        $entryOffset = mt_rand(-5, 50);            // minutes autour de 08:00
        $entryMin = 8 * 60 + $entryOffset;
        $retard = max(0, $entryMin - (8 * 60 + 15)); // tolérance 08:15
        $exitMin = 17 * 60 + mt_rand(-20, 40);
        $presenceMin = $exitMin - $entryMin;

        $heureEntree = sprintf('%s %02d:%02d:00', $date, intdiv($entryMin, 60), $entryMin % 60);
        $heureSortie = sprintf('%s %02d:%02d:00', $date, intdiv($exitMin, 60), $exitMin % 60);

        $methode = ['empreinte', 'rfid', 'facial'][mt_rand(0, 2)];
        $appareil = $appareils[mt_rand(0, 2)];
        $statutPt = $retard > 0 ? 'retard' : 'present';

        $insPointage->execute([$eid, $appareil, $date, $heureEntree, $heureSortie, $methode, $retard, $statutPt]);
        $stats['pointages']++;

        // Arrêts / inactivité
        $nbArrets = mt_rand(0, 4);
        $inactiviteMin = 0;
        $pauses = [];
        for ($k = 0; $k < $nbArrets; $k++) {
            $dur = mt_rand(5, 20);
            $inactiviteMin += $dur;
            $pauses[] = $dur;
        }
        $inactiviteMin = min($inactiviteMin, (int) ($presenceMin * 0.4));
        $travailleMin = max(0, $presenceMin - $inactiviteMin);
        $taux = $presenceMin > 0 ? round($travailleMin / $presenceMin * 100, 2) : 0;

        // Session de travail
        $sessDebut = sprintf('%s %02d:%02d:00', $date, intdiv($entryMin + 3, 60), ($entryMin + 3) % 60);
        $insSession->execute([
            $eid, $ptId, $sessDebut, $heureSortie, 'pin+empreinte', 1, 'fermee',
            $travailleMin * 60, $inactiviteMin * 60,
        ]);
        $sessionId = (int) $pdo->lastInsertId();
        $stats['sessions']++;

        // Échantillons d'activité (3 répartis)
        for ($s = 0; $s < 3; $s++) {
            $tMin = $entryMin + (int) (($presenceMin / 4) * ($s + 1));
            $hor = sprintf('%s %02d:%02d:00', $date, intdiv($tMin, 60), $tMin % 60);
            $insActivite->execute([
                $sessionId, $hor, mt_rand(50, 800), mt_rand(100, 2500),
                $apps[mt_rand(0, count($apps) - 1)], mt_rand(1, 10) > 1 ? 'actif' : 'inactif',
            ]);
        }

        // Incidents d'inactivité (à partir des pauses)
        $curMin = $entryMin + 90;
        foreach ($pauses as $dur) {
            $verrou = sprintf('%s %02d:%02d:00', $date, intdiv($curMin, 60), $curMin % 60);
            $reprise = sprintf('%s %02d:%02d:00', $date, intdiv($curMin + $dur, 60), ($curMin + $dur) % 60);
            $motifIdx = mt_rand(0, count($motifIds) - 1);
            $insIncident->execute([
                $sessionId, $eid, $ptId, $verrou, $reprise, $dur, $motifIds[$motifIdx],
                $motifs[$motifIdx], 'clos',
            ]);
            $stats['incidents']++;
            $curMin += $dur + mt_rand(60, 120);

            // Alerte si pause longue
            if ($dur >= 15 && $superieurByEmp[$eid] !== null) {
                $insAlerte->execute([
                    'inactivite', $eid, $ptId, $superieurByEmp[$eid],
                    sprintf('Inactivité détectée : %d min (%s)', $dur, $motifs[$motifIdx]),
                    $verrou, mt_rand(0, 1),
                ]);
                $stats['alertes']++;
            }
        }

        // Alerte retard
        if ($retard >= 20 && $superieurByEmp[$eid] !== null) {
            $insAlerte->execute([
                'retard', $eid, $ptId, $superieurByEmp[$eid],
                sprintf('Retard de %d min', $retard), $heureEntree, mt_rand(0, 1),
            ]);
            $stats['alertes']++;
        }

        // Productivité du jour
        $insProd->execute([
            $eid, $date, $presenceMin, $travailleMin, $inactiviteMin, $nbArrets, $retard, $taux,
        ]);
    }
}

$pdo->commit();

echo "Jours ouvrés générés : {$stats['jours']}\n";
echo "Pointages   : {$stats['pointages']}\n";
echo "Sessions    : {$stats['sessions']}\n";
echo "Incidents   : {$stats['incidents']}\n";
echo "Alertes     : {$stats['alertes']}\n";
echo "Seed terminé.\n";
