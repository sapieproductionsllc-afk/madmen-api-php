<?php
declare(strict_types=1);

namespace MadMen\Controllers;

use MadMen\Core\Auth;
use MadMen\Core\Database;
use MadMen\Core\Response;

/**
 * Notifications de l'employé connecté (basées sur la table `alerte`, filtrées sur
 * les alertes qui LUI sont adressées). Scopé au jeton (rang 1). Lecture + « marquer lu ».
 */
final class NotificationController
{
    private const TITRES = [
        'inactivite'        => 'Inactivité détectée',
        'connexion_refusee' => 'Connexion refusée',
        'absence'           => 'Absence',
        'retard'            => 'Retard',
        'demande'           => 'Demande',
        'message'           => 'Nouveau message',
    ];

    private const TONES = [
        'inactivite'        => 'warning',
        'connexion_refusee' => 'error',
        'absence'           => 'error',
        'retard'            => 'warning',
        'demande'           => 'success',
        'message'           => 'or',
    ];

    /** GET /api/me/notifications — mes notifications (récentes d'abord). */
    public function index(): void
    {
        $id = $this->employeId();
        $stmt = Database::connection()->prepare(
            'SELECT id, type, message, horodatage, lu FROM alerte
             WHERE destinataire_id = ? ORDER BY horodatage DESC, id DESC LIMIT 100'
        );
        $stmt->execute([$id]);

        $out = array_map(static function (array $a): array {
            $type = (string) $a['type'];

            return [
                'id'    => (int) $a['id'],
                'type'  => $type,
                'title' => self::TITRES[$type] ?? 'Notification',
                'text'  => $a['message'],
                'tone'  => self::TONES[$type] ?? 'or',
                'time'  => $a['horodatage'],
                'read'  => (bool) $a['lu'],
            ];
        }, $stmt->fetchAll());

        $nonLues = count(array_filter($out, static fn ($n) => !$n['read']));

        Response::json(['non_lues' => $nonLues, 'notifications' => $out]);
    }

    /** POST /api/me/notifications/{id}/lu — marquer UNE notification comme lue. */
    public function lire(array $params): void
    {
        $id = $this->employeId();
        $stmt = Database::connection()->prepare(
            'UPDATE alerte SET lu = 1 WHERE id = ? AND destinataire_id = ?'
        );
        $stmt->execute([(int) $params['id'], $id]);
        if ($stmt->rowCount() === 0) {
            Response::error('Notification introuvable', 404);
        }

        Response::json(['message' => 'Notification marquée lue']);
    }

    /** POST /api/me/notifications/tout-lire — tout marquer comme lu. */
    public function toutLire(): void
    {
        $id = $this->employeId();
        Database::connection()
            ->prepare('UPDATE alerte SET lu = 1 WHERE destinataire_id = ? AND lu = 0')
            ->execute([$id]);

        Response::json(['message' => 'Toutes les notifications marquées lues']);
    }

    private function employeId(): int
    {
        $user = Auth::currentUser();
        $id = $user['sub'] ?? null;
        if (!$id) {
            Response::error('Non authentifié', 401);
        }

        return (int) $id;
    }
}
