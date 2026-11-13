<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Config\Env;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;
use App\Repositories\TestModeRepository;

/**
 * Owner decision D-312 (point 4 B, 11 Nov 2026): Admin › Setup checklist —
 * what a fresh installation still needs before real use, each item Done /
 * Needed (or Optional) with a button to the page where it is set; and the
 * settings that differ from the recommended values, with "Apply recommended"
 * (reason needed, audit-logged). Node twin: services/setupChecklist.js.
 */
final class SetupChecklist
{
    /** The starting values in seed.sql that must be replaced by the company's own (D-313). */
    public const PLACEHOLDERS = [
        'legal_name' => [''],
        'registered_office' => ['Registered office address, City – 000000, India'],
        'corporate_office' => ['Corporate office address, City – 000000, India'],
        'md_name' => ['Managing Director'],
        'director_name' => ['Managing Director', 'Director'],
        'phone' => ['+91-0000000000'],
        'email' => ['info@email.com'],
        'gstin' => ['29AAAAA0000A1ZY'],
        'iec_pan' => ['AAAAA0000A'],
        'bank_name' => ['Bank name (to be entered)'],
        'bank_branch' => ['Branch (to be entered)'],
        'bank_account_no' => ['000000000000'],
        'swift_bic' => ['XXXXINXXXXX'],
        'ifsc' => ['XXXX0000000'],
        'bank_address' => ['Bank branch address, City – 000000, India'],
        'lut_number' => ['AD000000000000X'],
        'rcmc_number' => ['RCMC/CAPEXIL/00000/2026-2027'],
    ];

    public const IDENTITY_KEYS = ['legal_name' => 'Legal name', 'registered_office' => 'Registered office', 'corporate_office' => 'Corporate office',
        'md_name' => 'MD name', 'director_name' => 'Director name', 'phone' => 'Phone', 'email' => 'E-mail'];
    public const BANK_KEYS = ['bank_name' => 'Bank', 'bank_branch' => 'Branch', 'bank_account_no' => 'Account number', 'ifsc' => 'IFSC', 'swift_bic' => 'SWIFT / BIC', 'bank_address' => 'Bank address'];

    /** The seeded e-mail addresses of the starting users (README › first login). */
    public const PLACEHOLDER_USER_EMAILS = ['md@email.com', 'ed@email.com', 'admin@email.com'];

    /** Indian national holidays seeded for the current and next year (D-313); state and festival holidays are added by the company. */
    public const NATIONAL_HOLIDAYS = ['01-26' => 'Republic Day', '08-15' => 'Independence Day', '10-02' => 'Gandhi Jayanti'];

    /** Recommended values (D-312): key => [label, value]. 'test_email' is the Test Mode e-mail (recommended: the company e-mail). */
    public const RECOMMENDED = [
        'doc_cleanup_draft_days' => ['Document clean-up: remove DRAFT copies after (days)', '90'],
        'doc_cleanup_superseded_days' => ['Document clean-up: remove never-sent superseded revisions after (days)', '365'],
        'doc_cleanup_cafin_mode' => ['Document clean-up: old CAFIN copies', 'latest_only'],
        'password_expiry_days' => ['Password expiry (days, 0 = off)', '0'],
        'login_remember_browser_days' => ['"This is my own office computer" lasts (days)', '7'],
        'session_timeout_staff_minutes' => ['Staff signed out after idle (minutes)', '240'],
        'session_timeout_client_minutes' => ['Buyers signed out after idle (minutes)', '120'],
        'session_timeout_associate_minutes' => ['Associates signed out after idle (minutes)', '120'],
        'session_absolute_staff_hours' => ['Staff log in again at least every (hours)', '12'],
        'test_email' => ['Test Mode e-mail', 'the company e-mail'],
    ];

    /** Ticks a person gives on the page (stored in setup_checklist_ticks). key => [label, permission]. */
    public const TICKS = [
        'pricing_confirmed' => ['The MD has checked the global pricing settings (exchange rate and margins)', 'manage_pricing'],
        'commission_levels_confirmed' => ['The MD has checked the commission levels', 'manage_pricing'],
        'tds_benefits_confirmed' => ['TDS settings and export-benefit deadlines confirmed with the CA', 'manage_company_settings'],
    ];

    public static function isPlaceholder(string $key, ?string $value): bool
    {
        $v = trim((string) $value);
        if ($v === '' || stripos($v, 'PLACEHOLDER') !== false || stripos($v, 'to be entered') !== false) {
            return true;
        }
        return in_array($v, self::PLACEHOLDERS[$key] ?? [], true);
    }

    /**
     * @return list<array{key: string, title: string, status: string, detail: string, url: string, button: string, tick?: string, tick_label?: string, may_tick?: bool, ticked?: ?array, action?: string}>
     *   status: done / needed / optional
     */
    public static function items(int $userId, ?int $roleId, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $year = (int) substr($today, 0, 4);
        $s = static fn (string $k): string => trim((string) CompanySettingsRepository::get($k));
        $items = [];

        $missing = array_values(array_map(static fn (string $k): string => self::IDENTITY_KEYS[$k], array_filter(array_keys(self::IDENTITY_KEYS), static fn (string $k): bool => self::isPlaceholder($k, $s($k)))));
        $items[] = self::item('company', 'Company identity — name, offices, MD and director names, phone, e-mail', $missing === [],
            $missing === [] ? 'Entered.' : 'Still the starting text: ' . implode(', ', $missing) . '.', '/settings', 'Open Company Settings');

        $missing = array_values(array_map(static fn (string $k): string => self::BANK_KEYS[$k], array_filter(array_keys(self::BANK_KEYS), static fn (string $k): bool => self::isPlaceholder($k, $s($k)))));
        $gstNeeded = self::isPlaceholder('gstin', $s('gstin')) || self::isPlaceholder('iec_pan', $s('iec_pan'));
        $items[] = self::item('bank', 'Bank details, GSTIN and IEC / PAN (printed on the Proforma Invoice and other documents)', $missing === [] && !$gstNeeded,
            $missing === [] && !$gstNeeded ? 'Entered.' : 'Still the starting text: ' . implode(', ', array_merge($missing, $gstNeeded ? ['GSTIN / IEC-PAN'] : [])) . '.', '/settings', 'Open Company Settings');

        $problems = [];
        if (self::isPlaceholder('lut_number', $s('lut_number'))) {
            $problems[] = 'LUT number';
        }
        if (self::isPlaceholder('rcmc_number', $s('rcmc_number'))) {
            $problems[] = 'RCMC number';
        }
        foreach (['lut_expiry_date' => 'LUT expiry date', 'rcmc_valid_until' => 'RCMC valid-until date'] as $k => $label) {
            if ($s($k) === '' || $s($k) < $today) {
                $problems[] = $label . ($s($k) !== '' ? ' (passed)' : '');
            }
        }
        $items[] = self::item('lut_rcmc', 'LUT and RCMC — numbers and dates', $problems === [],
            $problems === [] ? 'LUT ' . $s('lut_number') . ' valid to ' . $s('lut_expiry_date') . '; RCMC valid to ' . $s('rcmc_valid_until') . '.' : 'Needed: ' . implode(', ', $problems) . '.', '/settings', 'Open Company Settings');

        $ecgcNo = $s('ecgc_policy_no');
        $ecgcTo = $s('ecgc_policy_valid_to');
        $items[] = $ecgcNo === ''
            ? self::item('ecgc', 'ECGC policy (optional)', false, 'No ECGC policy entered — leave it empty if the company has none.', '/settings', 'Open Company Settings', 'optional')
            : self::item('ecgc', 'ECGC policy (optional)', $ecgcTo !== '' && $ecgcTo >= $today, $ecgcTo !== '' && $ecgcTo >= $today ? "Policy {$ecgcNo}, valid to {$ecgcTo}." : "Policy {$ecgcNo}: enter a valid-until date that has not passed.", '/settings', 'Open Company Settings');

        $smtpMissing = array_values(array_filter(['SMTP_HOST', 'SMTP_PORT', 'SMTP_USERNAME', 'SMTP_PASSWORD', 'SMTP_FROM_ADDRESS'], static fn (string $k): bool => trim((string) Env::get($k, '')) === ''));
        $items[] = self::item('smtp', 'E-mail server (SMTP in app/.env) — then send a test e-mail', $smtpMissing === [],
            $smtpMissing === [] ? 'Set in .env (' . Env::get('SMTP_HOST') . '). Send a test e-mail to be sure it works.' : 'Missing in app/.env: ' . implode(', ', $smtpMissing) . '. Set them on the server, then send a test e-mail.',
            '/settings#smtp-test', 'Send a test e-mail');

        $zohoOn = $s('zoho_books_enabled') === '1' && $s('zoho_books_organization_id') !== '';
        $items[] = self::item('zoho', 'Zoho Books and Zoho Mail (optional)', $zohoOn, $zohoOn ? 'Zoho Books is switched on.' : 'Not switched on — the app works without Zoho; switch it on when the CA is ready.', '/settings', 'Open Company Settings', $zohoOn ? 'done' : 'optional');

        $b = BackupStatusService::settings();
        $backupMissing = [];
        if ($b['backup_dir'] === '') {
            $backupMissing[] = 'BACKUP_DIR';
        }
        if ($b['encryption_key_file'] === '') {
            $backupMissing[] = 'encryption (BACKUP_ENCRYPTION_KEY_FILE)';
        }
        if ($b['offsite_target'] === '') {
            $backupMissing[] = 'off-site copy (BACKUP_OFFSITE_TARGET)';
        }
        $items[] = self::item('backups', 'Backups — folder, encryption and an off-site copy', $backupMissing === [],
            $backupMissing === [] ? 'Set; see the Backups page for the last runs.' : 'Missing in app/.env: ' . implode(', ', $backupMissing) . '.', '/admin/backups', 'Open Backups');

        $items[] = self::tickItem('pricing_confirmed', 'Pricing confirmed by the MD — exchange rate and margins', '/products', 'Open Products › Global pricing settings', $userId, $roleId);

        $activeLevels = (int) Database::connection()->query('SELECT COUNT(*) FROM commission_levels WHERE is_active = 1')->fetchColumn();
        $levels = self::tickItem('commission_levels_confirmed', 'Commission levels', '/pricing/commission-levels', 'Open Commission levels', $userId, $roleId);
        if ($activeLevels === 0) {
            $levels['status'] = 'needed';
            $levels['detail'] = 'No active commission level — add at least one. ' . $levels['detail'];
        }
        $items[] = $levels;

        $items[] = self::tickItem('tds_benefits_confirmed', 'TDS and export-benefit deadlines confirmed with the CA', '/settings/associate-tds', 'Open TDS settings', $userId, $roleId);

        $holidays = self::holidayCount($year);
        $holidayTick = 'holidays_' . $year;
        $ticked = self::tick($holidayTick);
        $nationalMissing = self::nationalHolidaysMissing($year) + self::nationalHolidaysMissing($year + 1);
        $item = self::item('holidays', "This year's holidays ({$year})", $ticked !== null,
            "{$holidays} holiday(s) in {$year}. Reminder: add the state and festival holidays for {$year}, then tick." . ($nationalMissing > 0 ? " {$nationalMissing} national holiday(s) for {$year}–" . ($year + 1) . ' are not in the calendar yet.' : ''),
            '/holidays', 'Open Holiday Calendar');
        $item += ['tick' => $holidayTick, 'tick_label' => "State and festival holidays for {$year} added", 'may_tick' => true, 'ticked' => $ticked];
        if ($nationalMissing > 0) {
            $item['action'] = 'national_holidays';
        }
        $items[] = $item;

        $placeholderUsers = self::placeholderUserEmails();
        $items[] = self::item('md_email', "The MD's real e-mail (and the other starting users')", $placeholderUsers === [],
            $placeholderUsers === [] ? 'No user still has a starting e-mail address.' : 'Still a starting address: ' . implode(', ', $placeholderUsers) . ' — login codes and approval e-mails cannot reach them.', '/users', 'Open Users');

        $dir = rtrim(Env::storageBasePath(), '/') . '/sessions';
        $writable = $dir !== '/sessions' && is_dir($dir) && is_writable($dir);
        $items[] = self::item('sessions', 'Session folder storage/sessions is writable (PHP)', $writable,
            $writable ? 'Writable.' : 'The web server cannot write to ' . $dir . ' — staff would be signed out after about 24 minutes. Create it and give the web server write access.', '/admin/backups', 'Open Backups');

        $differs = count(self::differences());
        $items[] = self::item('recommended', 'Settings at the recommended values', $differs === 0,
            $differs === 0 ? 'Every listed setting is at its recommended value.' : "{$differs} setting(s) differ from the recommended value — see the list below.", '/admin/setup-checklist#recommended', 'See the list', $differs === 0 ? 'done' : 'review');
        return $items;
    }

    /** How many items are Needed (the menu badge). */
    public static function neededCount(): int
    {
        $user = AuthService::currentUser();
        if ($user === null) {
            return 0;
        }
        try {
            return count(array_filter(self::items((int) $user['id'], $user['role_id'] !== null ? (int) $user['role_id'] : null), static fn (array $i): bool => $i['status'] === 'needed'));
        } catch (\Throwable $e) {
            return 0; // never let the menu badge break a page (e.g. a table not migrated yet)
        }
    }

    /** @return list<array{key: string, label: string, current: string, recommended: string}> the settings that differ */
    public static function differences(): array
    {
        $out = [];
        foreach (self::RECOMMENDED as $key => [$label, $value]) {
            if ($key === 'test_email') {
                $current = (string) (TestModeRepository::getSettings()['test_email'] ?? '');
                $value = trim((string) CompanySettingsRepository::get('email'));
            } else {
                $current = (string) CompanySettingsRepository::get($key);
            }
            if (trim($current) !== $value) {
                $out[] = ['key' => $key, 'label' => $label, 'current' => $current, 'recommended' => $value];
            }
        }
        return $out;
    }

    /**
     * "Apply recommended" for the chosen keys (all differing when empty). A reason is required; each change
     * is audit-logged with it. Returns the refusal, or null.
     *
     * @param list<string> $keys
     */
    public static function applyRecommended(array $keys, string $reason, int $userId): ?string
    {
        if ($error = \App\Helpers\ReasonValidator::check($reason)) {
            return $error;
        }
        $differs = array_column(self::differences(), null, 'key');
        $keys = $keys === [] ? array_keys($differs) : array_values(array_intersect($keys, array_keys($differs)));
        if ($keys === []) {
            return 'Nothing to change — these settings are already at the recommended values.';
        }
        foreach ($keys as $key) {
            $d = $differs[$key];
            if ($key === 'test_email') {
                if (!filter_var($d['recommended'], FILTER_VALIDATE_EMAIL)) {
                    return 'Enter the company e-mail in Company Settings first.';
                }
                TestModeRepository::setTestEmail($d['recommended']);
                AuditLogRepository::log($userId, 'TEST_MODE_TEST_EMAIL_UPDATED', 'test_mode_settings', 1, 'test_email', $d['current'], $d['recommended'], $reason);
                continue;
            }
            CompanySettingsRepository::set($key, $d['recommended'], $userId);
            AuditLogRepository::log($userId, 'FIELD_EDIT', 'company_settings', null, $key, $d['current'], $d['recommended'], $reason);
        }
        AuditLogRepository::log($userId, 'SETUP_RECOMMENDED_APPLIED', 'company_settings', null, null, null, implode(', ', $keys), $reason);
        return null;
    }

    /** @return array{by: string, at: string, note: ?string}|null */
    public static function tick(string $key): ?array
    {
        $stmt = Database::connection()->prepare('SELECT t.ticked_at, t.note, u.name FROM setup_checklist_ticks t LEFT JOIN users u ON u.id = t.ticked_by WHERE t.item_key = :k');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ? ['by' => (string) ($row['name'] ?? ''), 'at' => (string) $row['ticked_at'], 'note' => $row['note']] : null;
    }

    /** Ticks or unticks an item; refusal text or null. */
    public static function setTick(string $key, bool $on, ?string $note, int $userId, ?int $roleId): ?string
    {
        if (!self::mayTick($key, $userId, $roleId)) {
            return isset(self::TICKS[$key]) ? 'Only someone with the "' . self::TICKS[$key][1] . '" permission may tick this (the MD for pricing).' : 'Unknown checklist item.';
        }
        $note = $note !== null ? trim($note) : '';
        if (mb_strlen($note) > 300) {
            return 'Keep the note under 300 characters.';
        }
        $pdo = Database::connection();
        if ($on) {
            $pdo->prepare('INSERT INTO setup_checklist_ticks (item_key, ticked_by, ticked_at, note) VALUES (:k, :u, NOW(), :n)
                           ON DUPLICATE KEY UPDATE ticked_by = VALUES(ticked_by), ticked_at = VALUES(ticked_at), note = VALUES(note)')
                ->execute(['k' => $key, 'u' => $userId, 'n' => $note !== '' ? $note : null]);
        } else {
            $pdo->prepare('DELETE FROM setup_checklist_ticks WHERE item_key = :k')->execute(['k' => $key]);
        }
        AuditLogRepository::log($userId, $on ? 'SETUP_CHECKLIST_TICKED' : 'SETUP_CHECKLIST_UNTICKED', 'setup_checklist_ticks', null, $key, null, $on ? '1' : '0', $note !== '' ? $note : null);
        return null;
    }

    public static function mayTick(string $key, int $userId, ?int $roleId): bool
    {
        if (preg_match('/^holidays_\d{4}$/', $key) === 1) {
            return PermissionService::can($userId, $roleId, 'manage_company_settings');
        }
        return isset(self::TICKS[$key]) && PermissionService::can($userId, $roleId, self::TICKS[$key][1]);
    }

    /** Adds the national holidays missing for this year and next; returns how many were added. */
    public static function addNationalHolidays(int $year, int $userId): int
    {
        $added = 0;
        $stmt = Database::connection()->prepare('INSERT INTO company_holidays (holiday_date, description, created_by) SELECT :d, :n, :u FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM company_holidays WHERE holiday_date = :d2)');
        foreach ([$year, $year + 1] as $y) {
            foreach (self::NATIONAL_HOLIDAYS as $md => $name) {
                $stmt->execute(['d' => "{$y}-{$md}", 'n' => $name, 'u' => $userId, 'd2' => "{$y}-{$md}"]);
                $added += $stmt->rowCount();
            }
        }
        if ($added > 0) {
            AuditLogRepository::log($userId, 'NATIONAL_HOLIDAYS_ADDED', 'company_holidays', null, null, null, (string) $added, "National holidays for {$year} and " . ($year + 1));
        }
        return $added;
    }

    public static function nationalHolidaysMissing(int $year): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM company_holidays WHERE holiday_date IN (:a, :b, :c)');
        $dates = array_map(static fn (string $md): string => "{$year}-{$md}", array_keys(self::NATIONAL_HOLIDAYS));
        $stmt->execute(['a' => $dates[0], 'b' => $dates[1], 'c' => $dates[2]]);
        return count($dates) - (int) $stmt->fetchColumn();
    }

    private static function holidayCount(int $year): int
    {
        $stmt = Database::connection()->prepare('SELECT COUNT(*) FROM company_holidays WHERE holiday_date BETWEEN :a AND :b');
        $stmt->execute(['a' => "{$year}-01-01", 'b' => "{$year}-12-31"]);
        return (int) $stmt->fetchColumn();
    }

    /** @return list<string> */
    private static function placeholderUserEmails(): array
    {
        $in = implode(',', array_fill(0, count(self::PLACEHOLDER_USER_EMAILS), '?'));
        $stmt = Database::connection()->prepare("SELECT email FROM users WHERE is_active = 1 AND LOWER(email) IN ({$in}) ORDER BY email");
        $stmt->execute(self::PLACEHOLDER_USER_EMAILS);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return array<string,mixed> */
    private static function item(string $key, string $title, bool $done, string $detail, string $url, string $button, ?string $status = null): array
    {
        return ['key' => $key, 'title' => $title, 'status' => $status ?? ($done ? 'done' : 'needed'), 'detail' => $detail, 'url' => $url, 'button' => $button];
    }

    /** @return array<string,mixed> */
    private static function tickItem(string $key, string $title, string $url, string $button, int $userId, ?int $roleId): array
    {
        $ticked = self::tick($key);
        $detail = $ticked !== null
            ? 'Ticked by ' . ($ticked['by'] !== '' ? $ticked['by'] : 'a user') . ' on ' . substr($ticked['at'], 0, 10) . ($ticked['note'] ? ' — ' . $ticked['note'] : '') . '.'
            : 'Not ticked yet — ' . lcfirst(self::TICKS[$key][0]) . '.';
        return self::item($key, $title, $ticked !== null, $detail, $url, $button)
            + ['tick' => $key, 'tick_label' => self::TICKS[$key][0], 'may_tick' => self::mayTick($key, $userId, $roleId), 'ticked' => $ticked];
    }
}
