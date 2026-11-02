<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Config\Database;
use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Services\AuthService;
use App\Services\RemittanceDifference;

/**
 * Owner follow-up on point 18 (2 Nov 2026): Admin → Settings → Remittance
 * difference reasons. The list behind "Why is it different?" on the buyer
 * remittance forms — add, rename, reorder, switch off. Each reason is treated
 * as bank charges, buyer paid less or buyer paid more; once a reason has been
 * used on a receipt its treatment is fixed (switch it off and add a new one
 * instead), so past receipts keep meaning what they meant. Also lists the
 * reasons staff typed in by hand ("Other"), most used first, so the frequent
 * ones can be added to the list. Gated by manage_company_settings.
 */
final class RemittanceReasonController
{
    private const BACK = '/settings/remittance-reasons';

    public function index(array $params): void
    {
        $usage = [];
        foreach (Database::connection()->query('SELECT difference_reason_id AS id, COUNT(*) AS n FROM order_payment_receipts WHERE difference_reason_id IS NOT NULL GROUP BY difference_reason_id')->fetchAll() as $row) {
            $usage[(int) $row['id']] = (int) $row['n'];
        }
        View::render('settings/remittance_reasons', [
            'reasons' => RemittanceDifference::reasons(false),
            'usage' => $usage,
            'custom' => RemittanceDifference::customReasonsUsed(),
            'treatments' => RemittanceDifference::TREATMENTS,
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $label = self::label($_POST['label'] ?? '');
        $treatment = (string) ($_POST['treatment'] ?? '');
        if ($error = self::check($label, $treatment)) {
            self::fail($error);
            return;
        }
        $pdo = Database::connection();
        $sort = (int) ($_POST['sort_order'] ?? 0);
        if ($sort <= 0) {
            $sort = (int) $pdo->query('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM remittance_difference_reasons')->fetchColumn();
        }
        $userId = (int) AuthService::currentUserId();
        $pdo->prepare('INSERT INTO remittance_difference_reasons (label, treatment, sort_order, is_active, created_by) VALUES (:l, :t, :s, 1, :u)')
            ->execute(['l' => $label, 't' => $treatment, 's' => $sort, 'u' => $userId]);
        $id = (int) $pdo->lastInsertId();
        AuditLogRepository::log($userId, 'REMITTANCE_REASON_ADDED', 'remittance_difference_reasons', $id, 'label', null, $label . ' (' . RemittanceDifference::TREATMENTS[$treatment] . ')');
        Flash::set('success', 'Reason added: "' . $label . '".');
        header('Location: ' . self::BACK);
    }

    public function update(array $params): void
    {
        $id = (int) $params['id'];
        $row = self::find($id);
        if ($row === null) {
            self::fail('That reason was not found.');
            return;
        }
        $label = self::label($_POST['label'] ?? '');
        $treatment = (string) ($_POST['treatment'] ?? $row['treatment']);
        if ($error = self::check($label, $treatment, $id)) {
            self::fail($error);
            return;
        }
        if ($treatment !== $row['treatment'] && self::uses($id) > 0) {
            self::fail('"' . $row['label'] . '" is already on ' . self::uses($id) . ' receipt(s), so how it is treated cannot change. Switch it off and add a new reason instead.');
            return;
        }
        $sort = (int) ($_POST['sort_order'] ?? $row['sort_order']);
        $userId = (int) AuthService::currentUserId();
        Database::connection()->prepare('UPDATE remittance_difference_reasons SET label = :l, treatment = :t, sort_order = :s WHERE id = :id')
            ->execute(['l' => $label, 't' => $treatment, 's' => $sort, 'id' => $id]);
        foreach (['label' => [$row['label'], $label], 'treatment' => [$row['treatment'], $treatment], 'sort_order' => [(string) $row['sort_order'], (string) $sort]] as $field => [$old, $new]) {
            if ((string) $old !== (string) $new) {
                AuditLogRepository::log($userId, 'REMITTANCE_REASON_UPDATED', 'remittance_difference_reasons', $id, $field, (string) $old, (string) $new);
            }
        }
        Flash::set('success', 'Reason saved: "' . $label . '".');
        header('Location: ' . self::BACK);
    }

    public function toggle(array $params): void
    {
        $id = (int) $params['id'];
        $row = self::find($id);
        if ($row === null) {
            self::fail('That reason was not found.');
            return;
        }
        $active = (int) $row['is_active'] === 1 ? 0 : 1;
        Database::connection()->prepare('UPDATE remittance_difference_reasons SET is_active = :a WHERE id = :id')->execute(['a' => $active, 'id' => $id]);
        AuditLogRepository::log((int) AuthService::currentUserId(), $active ? 'REMITTANCE_REASON_REACTIVATED' : 'REMITTANCE_REASON_DEACTIVATED', 'remittance_difference_reasons', $id, 'is_active', (string) $row['is_active'], (string) $active, $row['label']);
        Flash::set('success', '"' . $row['label'] . '" ' . ($active ? 'is back in the list.' : 'is switched off — it no longer shows on the forms; receipts that used it keep it.'));
        header('Location: ' . self::BACK);
    }

    private static function label(mixed $raw): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) $raw));
    }

    private static function check(string $label, string $treatment, ?int $exceptId = null): ?string
    {
        if (mb_strlen($label) < 3 || mb_strlen($label) > 150) {
            return 'Give the reason a name of 3 to 150 characters.';
        }
        if (!isset(RemittanceDifference::TREATMENTS[$treatment])) {
            return 'Choose how the reason is treated: bank charges, buyer paid less, or buyer paid more.';
        }
        $stmt = Database::connection()->prepare('SELECT id FROM remittance_difference_reasons WHERE LOWER(label) = LOWER(:l) AND id <> :id LIMIT 1');
        $stmt->execute(['l' => $label, 'id' => $exceptId ?? 0]);
        if ($stmt->fetchColumn() !== false) {
            return 'A reason called "' . $label . '" is already in the list.';
        }
        return null;
    }

    private static function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM remittance_difference_reasons WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    private static function uses(int $id): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM order_payment_receipts WHERE difference_reason_id = :id');
        $stmt->execute(['id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    private static function fail(string $message): void
    {
        Flash::set('error', $message);
        header('Location: ' . self::BACK);
    }
}
