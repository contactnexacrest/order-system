<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AssociateBrandRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\PricingRepository;
use App\Services\AssociateBrands;
use App\Services\AssociateRules;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Services\PricingService;

/**
 * D-431 (owner 25–26 Nov 2026): Admin › Brands — the division names associates see (name only, no logo).
 *  - Details (name, line under it, login address code, products, website, e-mail, status): "Manage divisions" (Admin, MD).
 *  - Rules and ladder (empty = the company setting; "Copy all company settings" / "Clear all"): "Manage associate rules"
 *    (D-411: MD, ED), each change with a reason (Audit Log). Company-wide settings stay in Associates › Settings.
 * Port: brandController.js.
 */
final class BrandController
{
    private static function user(): array
    {
        return AuthService::currentUser() ?? [];
    }

    private static function can(string $permission): bool
    {
        $u = self::user();
        return !empty($u['id']) && PermissionService::can((int) $u['id'], isset($u['role_id']) ? (int) $u['role_id'] : null, $permission);
    }

    public function index(array $params): void
    {
        View::render('brands/index', ['brands' => AssociateBrandRepository::all(), 'canEdit' => self::can('manage_divisions') && AssociateBrands::userBrandId() === null], 'layout/base');
    }

    public function show(array $params): void
    {
        $brand = AssociateBrandRepository::find((int) $params['id']);
        if ($brand === null || !AssociateBrands::inScope(['brand_id' => $brand['id']])) { // D-432
            http_response_code(404);
            echo 'Brand not found.';
            return;
        }
        $id = (int) $brand['id'];
        View::render('brands/show', [
            'brand' => $brand,
            'rules' => AssociateBrandRepository::settings($id),
            'company' => AssociateBrands::companyValues(),
            'ladder' => AssociateBrandRepository::ladder($id),
            'companyLadder' => PricingRepository::ladder(),
            'levels' => PricingRepository::levels(),
            'canEdit' => self::can('manage_divisions') && AssociateBrands::userBrandId() === null, // D-432: a brand manager never edits brand details
            'canRules' => AssociateRules::canManage(self::user()),
        ], 'layout/base');
    }

    /** @return array{0: array<string,mixed>, 1: list<string>} */
    private static function readDetails(?int $id): array
    {
        $d = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'tagline' => trim((string) ($_POST['tagline'] ?? '')),
            'code' => strtolower(trim((string) ($_POST['code'] ?? ''))),
            'products' => trim((string) ($_POST['products'] ?? '')) ?: null,
            'website' => trim((string) ($_POST['website'] ?? '')) ?: null,
            'email' => strtolower(trim((string) ($_POST['email'] ?? ''))) ?: null,
            'status' => (string) ($_POST['status'] ?? 'active'),
        ];
        $errors = [];
        if (mb_strlen($d['name']) < 2 || mb_strlen($d['name']) > 80) {
            $errors[] = 'Enter the brand name (2 to 80 characters).';
        } elseif (AssociateBrandRepository::nameTaken($d['name'], $id)) {
            $errors[] = 'Another brand already has this name.';
        }
        if ($d['tagline'] === '') {
            $d['tagline'] = AssociateBrands::DEFAULT_TAGLINE;
        } elseif (mb_strlen($d['tagline']) > 200) {
            $errors[] = 'The line under the name can be at most 200 characters.';
        }
        if (!AssociateBrands::validCode($d['code'])) {
            $errors[] = 'Enter the login address code: 2 to 30 lower-case letters, digits or hyphens (e.g. stone).';
        } elseif (AssociateBrandRepository::codeTaken($d['code'], $id)) {
            $errors[] = 'Another brand already uses this login address code.';
        }
        if ($d['products'] !== null && mb_strlen($d['products']) > 200) {
            $errors[] = '"Products it covers" can be at most 200 characters.';
        }
        if ($d['website'] !== null && (mb_strlen($d['website']) > 190 || !preg_match('#^(https?://)?[a-z0-9.-]+\.[a-z]{2,}(/\S*)?$#i', $d['website']))) {
            $errors[] = 'Enter the website as an address, e.g. www.example.com.';
        }
        if ($d['email'] !== null && (mb_strlen($d['email']) > 190 || filter_var($d['email'], FILTER_VALIDATE_EMAIL) === false)) {
            $errors[] = 'Enter a valid e-mail address, e.g. info@email.com.';
        }
        if (!isset(AssociateBrands::STATUSES[$d['status']])) {
            $errors[] = 'Choose the status.';
        }
        return [$d, $errors];
    }

    public function create(array $params): void
    {
        [$d, $errors] = self::readDetails(null);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header('Location: /admin/brands');
            return;
        }
        $userId = (int) self::user()['id'];
        $id = AssociateBrandRepository::save(null, $d, $userId);
        AuditLogRepository::log($userId, 'ASSOCIATE_BRAND_CREATED', 'associate_brands', $id, 'name', null, $d['name']);
        Flash::set('success', "Brand {$d['name']} added. Its rules follow the company settings until you change them.");
        header("Location: /admin/brands/{$id}");
    }

    public function update(array $params): void
    {
        $brand = AssociateBrandRepository::find((int) $params['id']);
        if ($brand === null) {
            Flash::set('error', 'That brand no longer exists.');
            header('Location: /admin/brands');
            return;
        }
        $id = (int) $brand['id'];
        [$d, $errors] = self::readDetails($id);
        if ($errors !== []) {
            Flash::set('error', implode(' ', $errors));
            header("Location: /admin/brands/{$id}");
            return;
        }
        $userId = (int) self::user()['id'];
        $changed = [];
        foreach ($d as $k => $v) {
            if ((string) ($brand[$k] ?? '') !== (string) ($v ?? '')) {
                $changed[] = $k;
                AuditLogRepository::log($userId, 'ASSOCIATE_BRAND_UPDATED', 'associate_brands', $id, $k, (string) ($brand[$k] ?? ''), (string) ($v ?? ''));
            }
        }
        if ($changed !== []) {
            AssociateBrandRepository::save($id, $d, $userId);
        }
        Flash::set('success', $changed === [] ? 'Nothing changed.' : 'Brand saved.');
        header("Location: /admin/brands/{$id}");
    }

    public function saveRules(array $params): void
    {
        $brand = AssociateBrandRepository::find((int) $params['id']);
        if ($brand === null) {
            Flash::set('error', 'That brand no longer exists.');
            header('Location: /admin/brands');
            return;
        }
        $id = (int) $brand['id'];
        $action = (string) ($_POST['action'] ?? 'save');
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Give the reason for the change (10 to 300 characters) — it is kept in the Audit Log.');
            header("Location: /admin/brands/{$id}#rules");
            return;
        }
        $before = AssociateBrandRepository::settings($id);
        if ($action === 'copy_all') {
            $values = AssociateBrands::companyValues();
            if ($values['price_rule'] !== 'fixed') {
                $values['price_fixed_pct'] = '';
            }
        } elseif ($action === 'clear_all') {
            $values = array_fill_keys(array_keys(AssociateBrands::RULES), null);
        } else {
            [$values, $errors] = AssociateBrands::validateRules($_POST);
            if ($errors !== []) {
                Flash::set('error', implode(' ', $errors));
                header("Location: /admin/brands/{$id}#rules");
                return;
            }
        }
        $userId = (int) self::user()['id'];
        AssociateBrandRepository::saveSettings($id, $values, $userId);
        $after = AssociateBrandRepository::settings($id);
        foreach (array_keys(AssociateBrands::RULES) as $k) {
            $b = $before[$k] ?? '';
            $a = $after[$k] ?? '';
            if ($b !== $a) {
                AuditLogRepository::log($userId, 'ASSOCIATE_BRAND_RULE_CHANGED', 'associate_brands', $id, $k, $b === '' ? '(company setting)' : $b, $a === '' ? '(company setting)' : $a, $reason);
            }
        }
        AssociateBrands::reset();
        Flash::set('success', match ($action) {
            'copy_all' => 'Company settings copied to this brand. Change only what this brand needs. Applies to new orders.',
            'clear_all' => 'All rules of this brand now follow the company settings.',
            default => 'Brand rules saved. They apply to new orders; an order keeps the rules it had at its Proforma Invoice.',
        });
        header("Location: /admin/brands/{$id}#rules");
    }

    /**
     * D-432 (owner 26 Nov 2026): the brand a staff member looks after (Admin › Users › Brand). Empty = all brands, as before.
     * "Manage divisions" (MD, Admin), not for yourself, not for a protected account, and not by someone who looks after one brand.
     */
    public function setUserBrand(array $params): void
    {
        $userId = (int) $params['id'];
        $me = self::user();
        $target = \App\Repositories\UserRepository::findById($userId);
        if ($target === null) {
            http_response_code(404);
            echo 'User not found.';
            return;
        }
        $raw = trim((string) ($_POST['brand_id'] ?? ''));
        $brand = $raw !== '' ? AssociateBrandRepository::find((int) $raw) : null;
        $refusal = null;
        if ($raw !== '' && $brand === null) {
            $refusal = 'Choose a brand from the list.';
        } elseif ($userId === (int) $me['id'] && !\App\Services\SuperAdminService::isEffective((int) $me['id'])) {
            $refusal = 'You cannot change your own brand — ask another administrator.';
        } elseif ((int) ($target['is_protected_account'] ?? 0) === 1) {
            $refusal = 'This is a protected account: its brand cannot be changed here.';
        } elseif (AssociateBrands::userBrandId($me) !== null) {
            $refusal = 'Only staff who look after all brands can give people a brand.';
        }
        if ($refusal !== null) {
            Flash::set('error', $refusal);
            header("Location: /users/{$userId}/edit");
            return;
        }
        $old = !empty($target['brand_id']) ? AssociateBrandRepository::find((int) $target['brand_id']) : null;
        \App\Config\Database::connection()->prepare('UPDATE users SET brand_id = :b WHERE id = :id')->execute(['b' => $brand !== null ? (int) $brand['id'] : null, 'id' => $userId]);
        AuditLogRepository::log((int) $me['id'], 'USER_BRAND_SET', 'users', $userId, 'brand_id', (string) ($old['name'] ?? 'all brands'), (string) ($brand['name'] ?? 'all brands'));
        Flash::set('success', $brand !== null ? "Saved: this person now looks after {$brand['name']} only." : 'Saved: this person sees all brands.');
        header("Location: /users/{$userId}/edit");
    }

    public function saveLadder(array $params): void
    {
        $brand = AssociateBrandRepository::find((int) $params['id']);
        if ($brand === null) {
            Flash::set('error', 'That brand no longer exists.');
            header('Location: /admin/brands');
            return;
        }
        $id = (int) $brand['id'];
        $reason = trim((string) ($_POST['reason'] ?? ''));
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 300) {
            Flash::set('error', 'Give the reason for the change (10 to 300 characters) — it is kept in the Audit Log.');
            header("Location: /admin/brands/{$id}#ladder");
            return;
        }
        $levelsById = [];
        foreach (PricingRepository::levels() as $l) {
            $levelsById[(int) $l['id']] = $l;
        }
        $clear = ($_POST['action'] ?? '') === 'clear';
        if ($clear) {
            $steps = [];
        } else {
            [$steps, $errors] = PricingService::readLadderForm($_POST, $levelsById);
            if ($errors !== []) {
                Flash::set('error', implode(' ', $errors));
                header("Location: /admin/brands/{$id}#ladder");
                return;
            }
        }
        $describe = static fn (array $rows): string => $rows === [] ? '(company ladder)' : implode('; ', array_map(
            static fn (array $s): string => ($s['from'] ?? $s['from_order']) . '–' . ((($s['to'] ?? $s['to_order'] ?? null) === null) ? 'onward' : ($s['to'] ?? $s['to_order']))
                . ': ' . ($levelsById[(int) $s['level_id']]['name'] ?? '?'), $rows));
        $before = $describe(AssociateBrandRepository::ladder($id));
        $after = $describe($steps);
        if ($before !== $after) {
            $userId = (int) self::user()['id'];
            AssociateBrandRepository::replaceLadder($id, $steps, $userId);
            AuditLogRepository::log($userId, 'ASSOCIATE_BRAND_LADDER_CHANGED', 'associate_brands', $id, 'ladder', $before, $after, $reason);
        }
        Flash::set('success', $before === $after ? 'Nothing changed.' : ($clear ? 'This brand now uses the company ladder.' : 'Brand ladder saved. It applies to new Proforma Invoices.'));
        header("Location: /admin/brands/{$id}#ladder");
    }
}
