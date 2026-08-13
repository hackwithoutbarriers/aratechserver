<?php
declare(strict_types=1);

/**
 * ARA Tech WiFi
 * cron/daily_maintenance.php
 *
 * Usage CLI :
 *   php cron/daily_maintenance.php
 *
 * Usage HTTP :
 *   /cron/daily_maintenance.php?token=VOTRE_CRON_TOKEN
 *
 * Variable d'environnement :
 *   CRON_TOKEN
 */

require_once __DIR__ . '/../db.php';

const WHATSAPP_BUSINESS_PHONE = '+22892709708';

function cron_log(string $message, string $level = 'INFO'): void
{
    error_log(
        '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' .
        '[daily-maintenance] ' . $message
    );
}

function cron_authorized(): bool
{
    // CLI : autorisé directement.
    if (PHP_SAPI === 'cli') {
        return true;
    }

    // HTTP : token obligatoire.
    $expected = trim((string)(getenv('CRON_TOKEN') ?: ''));
    $provided = trim((string)($_GET['token'] ?? ''));

    return $expected !== ''
        && $provided !== ''
        && hash_equals($expected, $provided);
}

function queue_notification(
    PDO $pdo,
    string $message,
    string $phone = WHATSAPP_BUSINESS_PHONE
): void {
    $stmt = $pdo->prepare(
        'INSERT INTO notification_queue
            (phone_number, message, status, created_at)
         VALUES
            (:phone_number, :message, :status, now())'
    );

    $stmt->execute([
        ':phone_number' => $phone,
        ':message'      => $message,
        ':status'       => 'pending',
    ]);
}

if (!cron_authorized()) {
    http_response_code(403);
    echo "Forbidden\n";
    exit(1);
}

try {
    $pdo = ara_db_supabase();

    /*
     * =====================================================================
     * TÂCHE A — Synthèse financière de la veille
     * =====================================================================
     */

    $targetDate = (new DateTimeImmutable('yesterday'))->format('Y-m-d');

    // Ventes
    $salesStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM sales_log
         WHERE sale_date = :target_date'
    );

    $salesStmt->execute([
        ':target_date' => $targetDate,
    ]);

    $totalSales = (int)$salesStmt->fetchColumn();

    // Dépenses
    $expensesStmt = $pdo->prepare(
        'SELECT COALESCE(SUM(amount), 0)
         FROM expenses
         WHERE expense_date = :target_date'
    );

    $expensesStmt->execute([
        ':target_date' => $targetDate,
    ]);

    $totalExpenses = (int)$expensesStmt->fetchColumn();

    $netProfit = $totalSales - $totalExpenses;

    /*
     * UPSERT :
     * si le cron est relancé, la synthèse du jour est recalculée proprement.
     */
    $summaryStmt = $pdo->prepare(
        'INSERT INTO daily_financial_summary
            (
                summary_date,
                total_sales,
                total_expenses,
                net_profit,
                created_at,
                updated_at
            )
         VALUES
            (
                :summary_date,
                :total_sales,
                :total_expenses,
                :net_profit,
                now(),
                now()
            )
         ON CONFLICT (summary_date)
         DO UPDATE SET
            total_sales = EXCLUDED.total_sales,
            total_expenses = EXCLUDED.total_expenses,
            net_profit = EXCLUDED.net_profit,
            updated_at = now()'
    );

    $summaryStmt->execute([
        ':summary_date'   => $targetDate,
        ':total_sales'    => $totalSales,
        ':total_expenses' => $totalExpenses,
        ':net_profit'     => $netProfit,
    ]);

    cron_log(
        sprintf(
            'Financial summary %s => sales=%d, expenses=%d, net=%d',
            $targetDate,
            $totalSales,
            $totalExpenses,
            $netProfit
        )
    );

    /*
     * =====================================================================
     * TÂCHE B — Contrôle des expirations Hotspot
     * =====================================================================
     *
     * Sources :
     *   hotspot_expiry        = expiration
     *   hotspot_users         = miroir actif
     *   hotspot_removed_users = historique "Remove & record"
     *
     * Le cron NE SUPPRIME PAS directement l'utilisateur.
     * Il détecte et archive les incohérences.
     */

    /*
     * hotspot_expiry.expiry est TEXT dans le schéma actuel.
     * On accepte le format ISO/TIMESTAMP déjà produit par l'application.
     */
    $expiredStmt = $pdo->query(
        "SELECT
            u.username,
            u.profile,
            u.mac_address,
            u.bytes_in,
            u.bytes_out,
            u.uptime,
            u.comment,
            e.expiry
         FROM hotspot_users u
         INNER JOIN hotspot_expiry e
             ON e.user_id = u.username
         WHERE NULLIF(TRIM(e.expiry), '') IS NOT NULL
           AND NULLIF(TRIM(e.expiry), '')::timestamptz <= now()
         ORDER BY NULLIF(TRIM(e.expiry), '')::timestamptz ASC"
    );

    $expiredStillPresent = $expiredStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * Archive "préventive" pour les expirés toujours présents.
     *
     * Cela garantit qu'une trace existe déjà dans l'historique avant
     * une future suppression effectuée par le mécanisme Remove & record.
     */
    $archiveStmt = $pdo->prepare(
        "INSERT INTO hotspot_removed_users
            (
                username,
                profile,
                mac_address,
                bytes_in,
                bytes_out,
                uptime_total,
                expired_at,
                removed_at,
                removal_reason,
                original_comment
            )
         SELECT
            :username,
            :profile,
            :mac_address,
            :bytes_in,
            :bytes_out,
            :uptime_total,
            CAST(:expired_at AS TIMESTAMPTZ),
            now(),
            'expired',
            :original_comment
         WHERE NOT EXISTS (
            SELECT 1
            FROM hotspot_removed_users hru
            WHERE hru.username = :username_check
              AND hru.removal_reason = 'expired'
              AND hru.expired_at IS NOT DISTINCT FROM
                  CAST(:expired_at_check AS TIMESTAMPTZ)
         )"
    );

    foreach ($expiredStillPresent as $user) {
        $archiveStmt->execute([
            ':username'       => (string)$user['username'],
            ':profile'        => $user['profile'],
            ':mac_address'    => $user['mac_address'],
            ':bytes_in'       => $user['bytes_in'],
            ':bytes_out'      => $user['bytes_out'],
            ':uptime_total'   => $user['uptime'],
            ':expired_at'     => $user['expiry'],
            ':original_comment' => $user['comment'],
            ':username_check' => (string)$user['username'],
            ':expired_at_check' => $user['expiry'],
        ]);
    }

    /*
     * Utilisateurs expirés absents de hotspot_users ET absents de
     * hotspot_removed_users.
     *
     * On ne peut plus reconstruire leur snapshot complet :
     * c'est donc une vraie anomalie à notifier.
     */
    $missingArchiveStmt = $pdo->query(
        "SELECT
            e.user_id AS username,
            e.expiry
         FROM hotspot_expiry e
         LEFT JOIN hotspot_users u
            ON u.username = e.user_id
         LEFT JOIN hotspot_removed_users hru
            ON hru.username = e.user_id
         WHERE NULLIF(TRIM(e.expiry), '') IS NOT NULL
           AND NULLIF(TRIM(e.expiry), '')::timestamptz <= now()
           AND u.username IS NULL
           AND hru.username IS NULL
         ORDER BY NULLIF(TRIM(e.expiry), '')::timestamptz ASC"
    );

    $missingArchives = $missingArchiveStmt->fetchAll(PDO::FETCH_ASSOC);

    /*
     * ---------------------------------------------------------------------
     * Notification #1 : expirés encore présents
     * ---------------------------------------------------------------------
     */
    if (!empty($expiredStillPresent)) {
        $names = array_map(
            static fn(array $row): string => (string)$row['username'],
            $expiredStillPresent
        );

        $message = sprintf(
            'Alerte WiFi Zone : %d utilisateur(s) expiré(s) sont encore présents dans le miroir hotspot_users. Utilisateurs : %s',
            count($names),
            implode(', ', array_slice($names, 0, 20))
        );

        if (count($names) > 20) {
            $message .= '…';
        }

        queue_notification($pdo, $message);
        cron_log($message, 'WARNING');
    }

    /*
     * ---------------------------------------------------------------------
     * Notification #2 : archive manquante
     * ---------------------------------------------------------------------
     */
    if (!empty($missingArchives)) {
        $names = array_map(
            static fn(array $row): string => (string)$row['username'],
            $missingArchives
        );

        $message = sprintf(
            'Alerte historique WiFi Zone : %d utilisateur(s) expiré(s) sont absents de hotspot_users sans archive dans hotspot_removed_users. Utilisateurs : %s',
            count($names),
            implode(', ', array_slice($names, 0, 20))
        );

        if (count($names) > 20) {
            $message .= '…';
        }

        queue_notification($pdo, $message);
        cron_log($message, 'WARNING');
    }

    cron_log(
        sprintf(
            'Expiration audit => still_present=%d, missing_archive=%d',
            count($expiredStillPresent),
            count($missingArchives)
        )
    );

    /*
     * Réponse HTTP utile pour la supervision Render.
     */
    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => true,
                'target_date' => $targetDate,
                'total_sales' => $totalSales,
                'total_expenses' => $totalExpenses,
                'net_profit' => $netProfit,
                'expired_still_present' => count($expiredStillPresent),
                'missing_archives' => count($missingArchives),
            ],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        );
    }
} catch (Throwable $e) {
    cron_log($e->getMessage(), 'ERROR');

    if (PHP_SAPI !== 'cli') {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');

        echo json_encode(
            [
                'success' => false,
                'message' => 'Daily maintenance failed.',
            ],
            JSON_UNESCAPED_UNICODE
        );
    }

    exit(1);
}
