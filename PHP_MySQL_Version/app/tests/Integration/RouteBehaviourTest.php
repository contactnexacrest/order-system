<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Helpers\Csrf;
use App\Helpers\Flash;
use App\Helpers\Router;
use App\Services\AuthService;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA Round 6, Batch 5 (R5-14): QA found 124 routes that no PHPUnit, Jest or
 * e2e test exercised. RouteMatrixTest already proves every one of them
 * refuses a user without the permission; this drives each of them through
 * the real Router and middleware as a user who holds the permission and
 * checks what the route actually does — the record changed, the audit row
 * written, the page shown — and that a missing id is answered as not found
 * with nothing written. The Node suite has the same test
 * (routeBehaviour.test.js).
 */
final class RouteBehaviourTest extends DbTestCase
{
    private static ?Router $router = null;
    private int $holder;

    private static function router(): Router
    {
        if (self::$router === null) {
            $_SERVER['REQUEST_METHOD'] = 'GET';
            $_SERVER['REQUEST_URI'] = '/__route-behaviour-build__';
            ob_start();
            require dirname(__DIR__, 3) . '/public_html/index.php';
            ob_end_clean();
            restore_exception_handler();
            self::$router = $router;
        }
        return self::$router;
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::router();
        // A Super Admin holds every permission (PermissionService), so one
        // user reaches every route below.
        $this->holder = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1, name = 'Route Holder' WHERE id = {$this->holder}");
        $this->signIn($this->holder);
    }

    protected function tearDown(): void
    {
        Flash::pull();
        Flash::pullOld();
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['REQUEST_METHOD'] = 'GET';
        http_response_code(200);
        parent::tearDown();
    }

    private function signIn(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId, '_session_version' => AuthService::sessionVersion() ?? 0];
        PermissionService::resetCache();
    }

    /**
     * One request through the real Router.
     *
     * @return array{code: int, body: string, flash: ?string, type: ?string}
     */
    private function call(string $method, string $path, array $post = []): array
    {
        Flash::pull();
        $_SESSION['_auth_user_id'] ??= $this->holder;
        $_POST = $method === 'POST' ? $post + ['_csrf' => Csrf::token()] : [];
        $_GET = [];
        parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        http_response_code(200);
        PermissionService::resetCache();
        ob_start();
        try {
            self::router()->dispatch($method, (string) parse_url($path, PHP_URL_PATH));
        } finally {
            $body = (string) ob_get_clean();
        }
        $flash = Flash::pull()[0] ?? null;
        return ['code' => (int) http_response_code(), 'body' => $body, 'flash' => $flash['message'] ?? null, 'type' => $flash['type'] ?? null];
    }

    private function value(string $sql): mixed
    {
        $v = Database::connection()->query($sql)->fetchColumn();
        return $v === false ? null : $v;
    }

    private function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        Database::connection()->prepare("INSERT INTO {$table} (" . implode(', ', $cols) . ') VALUES (:' . implode(', :', $cols) . ')')->execute($row);
        return (int) Database::connection()->lastInsertId();
    }

    private function audits(string $action, ?int $entityId = null): int
    {
        return (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action_type = '{$action}'" . ($entityId === null ? '' : " AND entity_id = {$entityId}"));
    }

    private function assertPage(array $r, string $marker, string $route): void
    {
        self::assertSame(200, $r['code'], $route);
        self::assertStringContainsString($marker, $r['body'], $route);
    }

    private function assertDone(array $r, string $route, string $flashContains = ''): void
    {
        self::assertLessThan(400, $r['code'], "{$route}: {$r['body']}");
        self::assertSame('success', $r['type'], "{$route}: {$r['flash']}");
        if ($flashContains !== '') {
            self::assertStringContainsString($flashContains, (string) $r['flash'], $route);
        }
    }

    private function tmpFile(string $ext, string $bytes): string
    {
        $path = sys_get_temp_dir() . '/route-behaviour-' . bin2hex(random_bytes(4)) . '.' . $ext;
        file_put_contents($path, $bytes);
        return $path;
    }

    private const PNG = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    // ---------------------------------------------------------------- ports, routes, presets

    public function testPortsAndPortRoutes(): void
    {
        $port = $this->insert('ports', ['name' => 'Route Test Port', 'city' => 'Testcity', 'country' => 'India', 'port_role' => 'loading', 'is_active' => 1]);
        $this->assertPage($this->call('GET', '/ports/create'), 'name="port_role"', 'GET /ports/create');
        $this->assertPage($this->call('GET', "/ports/{$port}/edit"), 'Route Test Port', 'GET /ports/{id}/edit');

        $r = $this->call('POST', "/ports/{$port}/update", ['name' => 'Route Test Port B', 'city' => 'Testcity', 'country' => 'India', 'port_role' => 'loading']);
        $this->assertDone($r, 'POST /ports/{id}/update');
        self::assertSame('Route Test Port B', $this->value("SELECT name FROM ports WHERE id = {$port}"));
        self::assertSame(1, $this->audits('PORT_UPDATED', $port));

        $this->assertDone($this->call('POST', "/ports/{$port}/toggle"), 'POST /ports/{id}/toggle');
        self::assertSame(0, (int) $this->value("SELECT is_active FROM ports WHERE id = {$port}"));
        self::assertSame(1, $this->audits('PORT_DEACTIVATED', $port));
        $this->call('POST', "/ports/{$port}/toggle");

        $defaults = Database::connection()->query('SELECT id FROM ports WHERE is_default = 1')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertDone($this->call('POST', "/ports/{$port}/set-default"), 'POST /ports/{id}/set-default');
        self::assertSame(1, (int) $this->value("SELECT is_default FROM ports WHERE id = {$port}"));
        self::assertSame(1, $this->audits('PORT_DEFAULT_CHANGED', $port));
        // Later tests create orders with the seeded default ports.
        Database::connection()->exec('UPDATE ports SET is_default = 0');
        if ($defaults !== []) {
            Database::connection()->exec('UPDATE ports SET is_default = 1 WHERE id IN (' . implode(',', array_map('intval', $defaults)) . ')');
        }

        $discharge = $this->insert('ports', ['name' => 'Route Test Discharge', 'port_role' => 'discharge', 'is_active' => 1]);
        $route = $this->insert('port_routes', ['port_of_loading_id' => $port, 'port_of_discharge_id' => $discharge, 'sailing_days_min' => 10, 'sailing_days_max' => 14]);
        $this->assertPage($this->call('GET', '/port-routes/create'), 'name="port_of_loading_id"', 'GET /port-routes/create');
        $this->assertPage($this->call('GET', "/port-routes/{$route}/edit"), 'Route Test Discharge', 'GET /port-routes/{id}/edit');
        $r = $this->call('POST', "/port-routes/{$route}/update", ['port_of_loading_id' => $port, 'port_of_discharge_id' => $discharge, 'sailing_days_min' => '12', 'sailing_days_max' => '16']);
        $this->assertDone($r, 'POST /port-routes/{id}/update');
        self::assertSame(16, (int) $this->value("SELECT sailing_days_max FROM port_routes WHERE id = {$route}"));
        self::assertSame(1, $this->audits('PORT_ROUTE_UPDATED', $route));
    }

    public function testPaymentPresets(): void
    {
        $currency = (int) $this->value("SELECT id FROM currencies WHERE code = 'USD'");
        $form = static fn (string $name): array => [
            'preset_name' => $name, 'advance_trigger_text' => 'On order confirmation', 'payment_method' => 'CASH_ADVANCE',
            'balance_trigger_option' => 'A_BEFORE_SHIPMENT', 'balance_trigger_wording' => 'Balance {days} days before shipment',
            'advance_pct' => '30', 'balance_pct' => '70', 'balance_days' => '7', 'currency_id' => (string) $currency,
        ];
        $this->assertPage($this->call('GET', '/payment-presets'), 'Standard', 'GET /payment-presets');
        $this->assertPage($this->call('GET', '/payment-presets/create'), 'name="preset_name"', 'GET /payment-presets/create');

        $r = $this->call('POST', '/payment-presets', $form('Route Test Preset'));
        $this->assertDone($r, 'POST /payment-presets', '30% advance');
        $id = (int) $this->value("SELECT id FROM payment_presets WHERE preset_name = 'Route Test Preset'");
        self::assertGreaterThan(0, $id);
        self::assertSame(1, $this->audits('PAYMENT_PRESET_ADDED', $id));

        $this->assertPage($this->call('GET', "/payment-presets/{$id}/edit"), 'Route Test Preset', 'GET /payment-presets/{id}/edit');
        $this->assertDone($this->call('POST', "/payment-presets/{$id}/update", $form('Route Test Preset B')), 'POST /payment-presets/{id}/update');
        self::assertSame('Route Test Preset B', $this->value("SELECT preset_name FROM payment_presets WHERE id = {$id}"));
        self::assertSame(1, $this->audits('PAYMENT_PRESET_UPDATED', $id));

        $this->assertDone($this->call('POST', "/payment-presets/{$id}/toggle"), 'POST /payment-presets/{id}/toggle');
        self::assertSame(0, (int) $this->value("SELECT is_active FROM payment_presets WHERE id = {$id}"));
        self::assertSame(1, $this->audits('PAYMENT_PRESET_DEACTIVATED', $id));
    }

    // ---------------------------------------------------------------- directories and lists

    public function testLogisticsPartnersComplianceTaskTypesAndHolidays(): void
    {
        $partner = $this->insert('logistics_partners', ['partner_name' => 'Route Test Freight Co', 'service_type' => 'freight_forwarder', 'is_active' => 1]);
        $this->assertPage($this->call('GET', "/logistics-partners/{$partner}/edit"), 'Route Test Freight Co', 'GET /logistics-partners/{id}/edit');
        $this->assertDone($this->call('POST', "/logistics-partners/{$partner}/toggle"), 'POST /logistics-partners/{id}/toggle');
        self::assertSame(0, (int) $this->value("SELECT is_active FROM logistics_partners WHERE id = {$partner}"));
        self::assertSame(1, $this->audits('LOGISTICS_PARTNER_DEACTIVATED', $partner));
        $this->assertDone($this->call('POST', "/logistics-partners/{$partner}/delete"), 'POST /logistics-partners/{id}/delete');
        self::assertNull($this->value("SELECT id FROM logistics_partners WHERE id = {$partner}"));
        self::assertSame(1, $this->audits('LOGISTICS_PARTNER_DELETED', $partner));

        $task = $this->insert('compliance_task_types', ['name' => 'Route Test Task', 'is_active' => 1]);
        $this->assertPage($this->call('GET', '/compliance-task-types/create'), 'name="name"', 'GET /compliance-task-types/create');
        $this->assertPage($this->call('GET', "/compliance-task-types/{$task}/edit"), 'Route Test Task', 'GET /compliance-task-types/{id}/edit');
        $this->assertDone($this->call('POST', "/compliance-task-types/{$task}/update", ['name' => 'Route Test Task B']), 'POST /compliance-task-types/{id}/update');
        self::assertSame('Route Test Task B', $this->value("SELECT name FROM compliance_task_types WHERE id = {$task}"));
        self::assertSame(1, $this->audits('COMPLIANCE_TASK_TYPE_UPDATED', $task));
        $this->assertDone($this->call('POST', "/compliance-task-types/{$task}/delete"), 'POST /compliance-task-types/{id}/delete');
        self::assertNull($this->value("SELECT id FROM compliance_task_types WHERE id = {$task}"));
        self::assertSame(1, $this->audits('COMPLIANCE_TASK_TYPE_DELETED', $task));

        $holiday = $this->insert('company_holidays', ['holiday_date' => '2031-01-26', 'description' => 'Route Test Day']);
        $this->assertDone($this->call('POST', "/holidays/{$holiday}/update", ['holiday_date' => '2031-01-27', 'description' => 'Route Test Day B']), 'POST /holidays/{id}/update');
        self::assertSame('2031-01-27', $this->value("SELECT holiday_date FROM company_holidays WHERE id = {$holiday}"));
        $this->assertDone($this->call('POST', "/holidays/{$holiday}/delete"), 'POST /holidays/{id}/delete');
        self::assertNull($this->value("SELECT id FROM company_holidays WHERE id = {$holiday}"));
        self::assertSame(1, $this->audits('HOLIDAY_REMOVED', $holiday));
    }

    public function testHsCodes(): void
    {
        $code = $this->insert('hs_codes', ['code' => '99887766', 'description' => 'Route test goods', 'is_active' => 1]);
        $this->assertDone($this->call('POST', "/hs-codes/{$code}/toggle"), 'POST /hs-codes/{id}/toggle');
        self::assertSame(0, (int) $this->value("SELECT is_active FROM hs_codes WHERE id = {$code}"));
        self::assertSame(1, $this->audits('HS_CODE_TOGGLED', $code));
        $this->assertDone($this->call('POST', "/hs-codes/{$code}/delete"), 'POST /hs-codes/{id}/delete');
        self::assertNull($this->value("SELECT id FROM hs_codes WHERE id = {$code}"));
        self::assertSame(1, $this->audits('HS_CODE_DELETED', $code));

        $this->call('POST', '/hs-codes/bulk-import', ['bulk_codes' => "99887711, Route bulk one\n99887712, Route bulk two"]);
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM hs_codes WHERE code IN ('99887711', '99887712')"));
        self::assertSame(1, $this->audits('HS_CODE_BULK_IMPORTED'));

        $this->call('POST', '/hs-codes/product-guide', ['bulk_guide' => "Route guide product\t99887711\ta note"]);
        $guide = (int) $this->value("SELECT id FROM hs_code_product_examples WHERE product_description = 'Route guide product'");
        self::assertGreaterThan(0, $guide);
        self::assertSame(1, $this->audits('HS_CODE_GUIDE_IMPORTED'));
        $this->assertDone($this->call('POST', "/hs-codes/product-guide/{$guide}/delete"), 'POST /hs-codes/product-guide/{id}/delete');
        self::assertNull($this->value("SELECT id FROM hs_code_product_examples WHERE id = {$guide}"));
        self::assertSame(1, $this->audits('HS_CODE_GUIDE_DELETED', $guide));
    }

    public function testReferenceLibrary(): void
    {
        $category = $this->insert('reference_library_categories', ['name' => 'Route Test Category']);
        $this->assertDone($this->call('POST', "/reference-docs/categories/{$category}/update", ['name' => 'Route Test Category B', 'required_permission' => '']), 'POST /reference-docs/categories/{id}/update');
        self::assertSame('Route Test Category B', $this->value("SELECT name FROM reference_library_categories WHERE id = {$category}"));
        self::assertSame(1, $this->audits('REFERENCE_LIBRARY_CATEGORY_UPDATED', $category));
        $this->assertDone($this->call('POST', "/reference-docs/categories/{$category}/delete"), 'POST /reference-docs/categories/{id}/delete');
        self::assertNull($this->value("SELECT id FROM reference_library_categories WHERE id = {$category}"));
        self::assertSame(1, $this->audits('REFERENCE_LIBRARY_CATEGORY_DELETED', $category));

        $doc = $this->insert('reference_library_documents', ['title' => 'Route Test Reference', 'content' => 'Body text']);
        $this->assertPage($this->call('GET', '/reference-docs/custom/create'), 'name="title"', 'GET /reference-docs/custom/create');
        $this->assertPage($this->call('GET', "/reference-docs/custom/{$doc}/edit"), 'Route Test Reference', 'GET /reference-docs/custom/{id}/edit');
        $this->assertDone($this->call('POST', "/reference-docs/custom/{$doc}/delete"), 'POST /reference-docs/custom/{id}/delete');
        self::assertNull($this->value("SELECT id FROM reference_library_documents WHERE id = {$doc}"));
        self::assertSame(1, $this->audits('REFERENCE_LIBRARY_DOC_DELETED', $doc));
    }

    // ---------------------------------------------------------------- users, roles, permissions

    public function testUsers(): void
    {
        $role = (int) $this->value("SELECT id FROM roles WHERE name = 'Logistics Executive'");
        $r = $this->call('POST', '/users/create', ['name' => 'Route Test Person', 'email' => 'route-test-person@nexacrest.test', 'phone' => '', 'role_id' => (string) $role]);
        $this->assertDone($r, 'POST /users/create', 'temporary password');
        $user = (int) $this->value("SELECT id FROM users WHERE email = 'route-test-person@nexacrest.test'");
        self::assertSame(1, $this->audits('USER_CREATED', $user));

        $this->assertPage($this->call('GET', "/users/{$user}/edit"), 'route-test-person@nexacrest.test', 'GET /users/{id}/edit');
        $this->assertDone($this->call('POST', "/users/{$user}/update", ['name' => 'Route Test Person B', 'email' => 'route-test-person@nexacrest.test', 'phone' => '', 'role_id' => (string) $role]), 'POST /users/{id}/update');
        self::assertSame('Route Test Person B', $this->value("SELECT name FROM users WHERE id = {$user}"));

        $this->assertDone($this->call('POST', "/users/{$user}/toggle-active"), 'POST /users/{id}/toggle-active');
        self::assertSame(0, (int) $this->value("SELECT is_active FROM users WHERE id = {$user}"));

        Database::connection()->exec("UPDATE users SET is_archived = 1 WHERE id = {$user}");
        $this->assertDone($this->call('POST', "/users/{$user}/unarchive"), 'POST /users/{id}/unarchive');
        self::assertSame(0, (int) $this->value("SELECT is_archived FROM users WHERE id = {$user}"));
        self::assertSame(1, $this->audits('USER_UNARCHIVED', $user));

        $r = $this->call('POST', "/users/{$this->holder}/toggle-active");
        self::assertSame("You can't deactivate your own account.", $r['flash'], 'own account refused');
    }

    public function testRolesAndPermissions(): void
    {
        $pdo = Database::connection();
        $role = $this->insert('roles', ['name' => 'Route Test Role', 'description' => 'Test only', 'is_system_role' => 0]);
        $this->assertPage($this->call('GET', "/admin/roles/{$role}/edit"), 'Route Test Role', 'GET /admin/roles/{id}/edit');
        $this->assertDone($this->call('POST', "/admin/roles/{$role}/delete"), 'POST /admin/roles/{id}/delete');
        self::assertNull($this->value("SELECT id FROM roles WHERE id = {$role}"));
        self::assertSame(1, $this->audits('ROLE_DELETED', $role));

        $permission = $this->insert('permissions', ['permission_key' => 'route_test_permission', 'name' => 'Route Test Permission', 'is_system_permission' => 0]);
        $this->assertPage($this->call('GET', "/admin/permission-definitions/{$permission}/edit"), 'Route Test Permission', 'GET /admin/permission-definitions/{id}/edit');
        $this->assertDone($this->call('POST', "/admin/permission-definitions/{$permission}/delete"), 'POST /admin/permission-definitions/{id}/delete');
        self::assertNull($this->value("SELECT id FROM permissions WHERE id = {$permission}"));
        self::assertSame(1, $this->audits('PERMISSION_DELETED', $permission));

        $other = $this->createTestUser('Logistics Executive');
        $permId = (int) $this->value("SELECT id FROM permissions WHERE permission_key = 'view_reports'");
        $pdo->prepare('INSERT INTO user_permissions (user_id, permission_id, is_enabled) VALUES (:u, :p, 1)')->execute(['u' => $other, 'p' => $permId]);
        $override = (int) $pdo->lastInsertId();
        $this->assertDone($this->call('POST', "/admin/permissions/{$override}/remove"), 'POST /admin/permissions/{id}/remove');
        self::assertNull($this->value("SELECT id FROM user_permissions WHERE id = {$override}"));
        self::assertSame(1, $this->audits('USER_PERMISSION_REVOKED', $override));
    }

    public function testFieldProtectionAndOverrides(): void
    {
        $preset = $this->insert('payment_presets', [
            'preset_name' => 'Route Protected Preset', 'advance_pct' => 30, 'advance_trigger_text' => 'On order confirmation', 'payment_method' => 'CASH_ADVANCE',
            'balance_pct' => 70, 'balance_trigger_option' => 'A_BEFORE_SHIPMENT', 'balance_days' => 7, 'balance_trigger_wording' => 'Balance {days} days before shipment',
            'currency_id' => (int) $this->value("SELECT id FROM currencies WHERE code = 'USD'"), 'is_active' => 1, 'is_protected' => 1,
        ]);
        $request = function () use ($preset): int {
            $before = (int) $this->value('SELECT COALESCE(MAX(id), 0) FROM field_protection_requests');
            $r = $this->call('POST', '/admin/field-protection/request', ['table_name' => 'payment_presets', 'record_id' => (string) $preset, 'record_label' => 'Route test preset', 'action' => 'unlock', 'reason' => 'Route behaviour test of the protection request']);
            $this->assertDone($r, 'POST /admin/field-protection/request');
            return (int) $this->value("SELECT id FROM field_protection_requests WHERE id > {$before}");
        };
        $first = $request();
        self::assertSame('pending', $this->value("SELECT status FROM field_protection_requests WHERE id = {$first}"));
        self::assertSame(1, $this->audits('PROTECTION_FLAG_REQUESTED', $preset));

        // A different privileged user resolves it (maker-checker).
        $checker = $this->createTestUser('Admin');
        Database::connection()->exec("UPDATE users SET is_super_admin = 1 WHERE id = {$checker}");
        $this->signIn($checker);
        $this->assertDone($this->call('POST', "/admin/field-protection/{$first}/reject", ['resolved_reason' => 'Not needed for this route test']), 'POST /admin/field-protection/{id}/reject');
        self::assertSame('rejected', $this->value("SELECT status FROM field_protection_requests WHERE id = {$first}"));

        $this->signIn($this->holder);
        $second = $request();
        $this->signIn($checker);
        $this->assertDone($this->call('POST', "/admin/field-protection/{$second}/approve", ['resolved_reason' => 'Approved for this route test']), 'POST /admin/field-protection/{id}/approve');
        self::assertSame('approved', $this->value("SELECT status FROM field_protection_requests WHERE id = {$second}"));
        self::assertSame(0, (int) $this->value("SELECT is_protected FROM payment_presets WHERE id = {$preset}"), 'the approved unlock is applied');
        $this->signIn($this->holder);

        // Overrides: one document type's minimum reviewers, the now-unprotected preset's advance text.
        $type = Database::connection()->query("SELECT id, ref_format, min_reviewers_default FROM document_types WHERE code = 'ANNEXA'")->fetch(\PDO::FETCH_ASSOC);
        $newMin = (int) $type['min_reviewers_default'] + 1;
        $r = $this->call('POST', '/admin/overrides/document-types', ['reason' => 'Route behaviour test override', 'ref_format' => [$type['id'] => $type['ref_format']], 'min_reviewers' => [$type['id'] => (string) $newMin]]);
        $this->assertDone($r, 'POST /admin/overrides/document-types', '1 document type');
        self::assertSame($newMin, (int) $this->value("SELECT min_reviewers_default FROM document_types WHERE id = {$type['id']}"));
        self::assertSame(1, (int) $this->value("SELECT COUNT(*) FROM audit_log WHERE action_type = 'FIELD_EDIT' AND entity_type = 'document_types' AND entity_id = {$type['id']}"));
        Database::connection()->exec("UPDATE document_types SET min_reviewers_default = {$type['min_reviewers_default']} WHERE id = {$type['id']}");

        $open = Database::connection()->query("SELECT * FROM payment_presets WHERE id = {$preset}")->fetch(\PDO::FETCH_ASSOC);
        $id = (int) $open['id'];
        $r = $this->call('POST', '/admin/overrides/payment-presets', [
            'reason' => 'Route behaviour test override',
            'advance_pct' => [$id => (string) $open['advance_pct']], 'balance_pct' => [$id => (string) $open['balance_pct']],
            'advance_trigger_text' => [$id => 'Route test advance wording'], 'balance_trigger_option' => [$id => $open['balance_trigger_option']],
            'balance_days' => [$id => (string) $open['balance_days']],
        ]);
        $this->assertDone($r, 'POST /admin/overrides/payment-presets', '1 payment preset');
        self::assertSame('Route test advance wording', $this->value("SELECT advance_trigger_text FROM payment_presets WHERE id = {$id}"));
    }

    // ---------------------------------------------------------------- company settings pages

    public function testSettingsPagesAndSignatories(): void
    {
        $this->assertPage($this->call('GET', '/ui-templates'), '<form', 'GET /ui-templates');
        $this->assertPage($this->call('GET', '/watermarks'), '<form', 'GET /watermarks');
        $template = (int) $this->value('SELECT id FROM email_templates ORDER BY id LIMIT 1');
        $this->assertPage($this->call('GET', "/email-templates/{$template}/edit"), 'name="subject"', 'GET /email-templates/{id}/edit');

        $designation = (int) $this->value('SELECT id FROM designations ORDER BY id LIMIT 1');
        $person = $this->createTestUser('Admin');
        $this->assertDone($this->call('POST', "/signatories/users/{$person}/eligibility", ['eligible' => '1', 'designation_id' => (string) $designation]), 'POST /signatories/users/{id}/eligibility');
        self::assertSame(1, (int) $this->value("SELECT is_signatory_eligible FROM users WHERE id = {$person}"));
        $previousDefault = $this->value('SELECT user_id FROM company_default_signatory WHERE id = 1');
        $this->assertDone($this->call('POST', '/signatories/global-default', ['user_id' => (string) $person]), 'POST /signatories/global-default');
        self::assertSame($person, (int) $this->value('SELECT user_id FROM company_default_signatory WHERE id = 1'));
        if ($previousDefault !== null) {
            // Later tests generate documents signed by the seeded default.
            Database::connection()->exec('UPDATE company_default_signatory SET user_id = ' . (int) $previousDefault . ' WHERE id = 1');
        }

        $asset = $this->insert('user_signature_assets', ['user_id' => $person, 'asset_kind' => 'signature', 'label' => 'Route test signature', 'server_path' => $this->tmpFile('png', self::PNG), 'mime_type' => 'image/png', 'is_active' => 1]);
        $r = $this->call('GET', "/signatories/user-assets/{$asset}/preview");
        self::assertSame(200, $r['code']);
        self::assertSame(self::PNG, $r['body'], 'the stored image is served');

        $old = $this->insert('assets', ['asset_type' => 'logo', 'name' => 'Route test old logo', 'server_path' => $this->tmpFile('png', self::PNG), 'mime_type' => 'image/png', 'is_active' => 0]);
        $this->assertDone($this->call('POST', "/company-assets/{$old}/delete"), 'POST /company-assets/{id}/delete');
        self::assertNull($this->value("SELECT id FROM assets WHERE id = {$old}"));
        self::assertSame(1, $this->audits('ASSET_DELETED', $old));
    }

    public function testSampleDataTestModeAndSuperAdmin(): void
    {
        $this->assertPage($this->call('GET', '/sample-data'), 'name="_csrf"', 'GET /sample-data');
        $this->assertDone($this->call('POST', '/sample-data/load'), 'POST /sample-data/load', 'Sample data loaded');
        self::assertSame(10, (int) $this->value('SELECT COUNT(*) FROM orders WHERE is_sample_data = 1'));
        $this->assertDone($this->call('POST', '/sample-data/clear'), 'POST /sample-data/clear', 'Sample data cleared');
        self::assertSame(0, (int) $this->value('SELECT COUNT(*) FROM orders WHERE is_sample_data = 1'));

        $this->assertPage($this->call('GET', '/test-mode'), 'Test Mode', 'GET /test-mode');

        $target = $this->createTestUser('Admin');
        $r = $this->call('POST', '/super-admin/set-permanent', ['target_id' => (string) $target, 'is_super_admin' => '1', 'reason' => 'Route behaviour test promotion']);
        $this->assertDone($r, 'POST /super-admin/set-permanent', 'promoted');
        self::assertSame(1, (int) $this->value("SELECT is_super_admin FROM users WHERE id = {$target}"));
    }

    // ---------------------------------------------------------------- product catalogue

    public function testProductCatalogue(): void
    {
        $hs = (string) $this->value('SELECT code FROM hs_codes WHERE is_active = 1 ORDER BY id LIMIT 1');
        $form = static fn (string $name): array => ['name' => $name, 'specifications' => '1200 x 600 mm', 'hs_code' => $hs, 'origin' => 'India', 'is_active' => '1'];
        $this->assertDone($this->call('POST', '/products/create', $form('Route Test Granite')), 'POST /products/create');
        $product = (int) $this->value("SELECT id FROM catalog_products WHERE name = 'Route Test Granite'");
        self::assertSame(1, $this->audits('PRODUCT_CREATED', $product));

        $this->assertPage($this->call('GET', "/products/{$product}"), 'Route Test Granite', 'GET /products/{id}');
        $this->assertPage($this->call('GET', "/products/{$product}/edit"), 'Route Test Granite', 'GET /products/{id}/edit');
        $this->assertDone($this->call('POST', "/products/{$product}/update", $form('Route Test Granite B')), 'POST /products/{id}/update');
        self::assertSame('Route Test Granite B', $this->value("SELECT name FROM catalog_products WHERE id = {$product}"));

        $this->assertDone($this->call('POST', "/products/{$product}/misc-charges/add", ['label' => 'Route crating', 'amount' => '250']), 'POST /products/{id}/misc-charges/add');
        $charge = (int) $this->value("SELECT id FROM catalog_product_misc_charges WHERE product_id = {$product}");
        $this->assertDone($this->call('POST', "/products/misc-charges/{$charge}/delete"), 'POST /products/misc-charges/{id}/delete');
        self::assertNull($this->value("SELECT id FROM catalog_product_misc_charges WHERE id = {$charge}"));
        self::assertSame(1, $this->audits('PRODUCT_MISC_CHARGE_DELETED', $charge));

        $supplier = ['supplier_name' => 'Route Quarry One', 'fob_source' => 'direct', 'fob_value' => '40'];
        $this->assertDone($this->call('POST', "/products/{$product}/suppliers/add", $supplier), 'POST /products/{id}/suppliers/add');
        $this->assertDone($this->call('POST', "/products/{$product}/suppliers/add", ['supplier_name' => 'Route Quarry Two'] + $supplier), 'second supplier');
        [$one, $two] = array_map('intval', Database::connection()->query("SELECT id FROM catalog_product_suppliers WHERE product_id = {$product} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->assertDone($this->call('POST', "/products/suppliers/{$two}/update", ['supplier_name' => 'Route Quarry Two B'] + $supplier), 'POST /products/suppliers/{id}/update');
        self::assertSame('Route Quarry Two B', $this->value("SELECT supplier_name FROM catalog_product_suppliers WHERE id = {$two}"));
        $this->assertDone($this->call('POST', "/products/suppliers/{$two}/set-primary"), 'POST /products/suppliers/{id}/set-primary');
        self::assertSame(1, (int) $this->value("SELECT is_primary FROM catalog_product_suppliers WHERE id = {$two}"));
        self::assertSame(0, (int) $this->value("SELECT is_primary FROM catalog_product_suppliers WHERE id = {$one}"));
        $this->assertDone($this->call('POST', "/products/suppliers/{$one}/delete"), 'POST /products/suppliers/{id}/delete');
        self::assertNull($this->value("SELECT id FROM catalog_product_suppliers WHERE id = {$one}"));

        // An image upload needs a real HTTP upload; without one it is refused and nothing is stored.
        $r = $this->call('POST', "/products/{$product}/images/upload");
        self::assertSame('No file was uploaded, or the upload failed.', $r['flash']);
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM catalog_product_images WHERE product_id = {$product}"));
        $image = $this->insert('catalog_product_images', ['product_id' => $product, 'server_path' => $this->tmpFile('png', self::PNG), 'mime_type' => 'image/png']);
        $r = $this->call('GET', "/products/images/{$image}/view");
        self::assertSame(self::PNG, $r['body'], 'GET /products/images/{id}/view serves the image');
        $this->assertDone($this->call('POST', "/products/images/{$image}/delete"), 'POST /products/images/{id}/delete');
        self::assertNull($this->value("SELECT id FROM catalog_product_images WHERE id = {$image}"));

        $this->assertDone($this->call('POST', "/products/{$product}/delete"), 'POST /products/{id}/delete');
        self::assertSame(1, $this->audits('PRODUCT_DELETED', $product));
    }

    // ---------------------------------------------------------------- clients, suppliers, associates

    public function testClientsSuppliersAndAssociates(): void
    {
        $client = $this->createTestClient();
        $this->assertPage($this->call('GET', "/clients/{$client}/edit"), 'PHPUnit Test Buyer Ltd', 'GET /clients/{id}/edit');
        $r = $this->call('POST', "/clients/{$client}/override-unique-number", ['client_unique_number' => 'RT-OVERRIDE-1', 'reason' => 'Route behaviour test override']);
        $this->assertDone($r, 'POST /clients/{id}/override-unique-number', 'RT-OVERRIDE-1');
        self::assertSame('RT-OVERRIDE-1', $this->value("SELECT client_unique_number FROM clients WHERE id = {$client}"));

        $inactive = $this->createTestClient();
        Database::connection()->exec("UPDATE clients SET company_legal_name = 'Route Dormant Buyer', is_active = 0 WHERE id = {$inactive}");
        $this->assertPage($this->call('GET', '/clients/inactive'), 'Route Dormant Buyer', 'GET /clients/inactive');

        $supplier = $this->insert('suppliers', ['supplier_legal_name' => 'Route Test Supplier']);
        $r = $this->call('POST', "/suppliers/{$supplier}/details-link");
        $this->assertDone($r, 'POST /suppliers/{id}/details-link', 'Vendor Details Link');
        self::assertSame(1, $this->audits('VENDOR_DETAILS_LINK_GENERATED', $supplier));

        $associate = $this->insert('associates', ['name' => 'Route Test Associate', 'status' => 'active']);
        $this->assertDone($this->call('POST', "/associates/{$associate}/clients", ['client_id' => (string) $client, 'registered_on' => date('Y-m-d')]), 'POST /associates/{id}/clients');
        self::assertSame($associate, (int) $this->value("SELECT associate_id FROM clients WHERE id = {$client}"));
        $this->assertDone($this->call('POST', "/associates/{$associate}/clients/{$client}/unlink"), 'POST /associates/{id}/clients/{clientId}/unlink');
        self::assertNull($this->value("SELECT associate_id FROM clients WHERE id = {$client}"));
        self::assertSame(1, $this->audits('ASSOCIATE_CLIENT_UNLINKED', $client));
    }

    // ---------------------------------------------------------------- orders

    public function testOrderPaymentRecordsAndSettings(): void
    {
        $pdo = Database::connection();
        $order = $this->createTestOrder($this->createTestClient());

        $this->assertDone($this->call('POST', "/orders/{$order}/dispute-visibility", ['dispute_button_visible_to_client' => '1']), 'POST /orders/{id}/dispute-visibility');
        self::assertSame(1, (int) $this->value("SELECT dispute_button_visible_to_client FROM orders WHERE id = {$order}"));

        $pdo->exec("INSERT IGNORE INTO order_payment_status (order_id) VALUES ({$order})");
        $pdo->exec("UPDATE order_payment_status SET advance_amount = 1000, advance_remittance_received_at = NOW(), advance_inr_actual = 83000,
                    balance_amount = 2000, balance_remittance_received_at = NOW(), balance_inr_actual = 166000,
                    freight_amount = 300, freight_remittance_received_at = NOW() WHERE order_id = {$order}");

        $this->assertDone($this->call('POST', "/orders/{$order}/payment/balance/firc", ['balance_firc_reference' => 'RT-FIRC-B', 'balance_firc_received_at' => date('Y-m-d')]), 'POST /orders/{id}/payment/balance/firc');
        self::assertSame('RT-FIRC-B', $this->value("SELECT balance_firc_reference FROM order_payment_status WHERE order_id = {$order}"));
        $this->assertDone($this->call('POST', "/orders/{$order}/payment/freight/firc", ['freight_firc_reference' => 'RT-FIRC-F', 'freight_firc_received_at' => date('Y-m-d')]), 'POST /orders/{id}/payment/freight/firc');
        self::assertSame('RT-FIRC-F', $this->value("SELECT freight_firc_reference FROM order_payment_status WHERE order_id = {$order}"));

        $this->assertDone($this->call('POST', "/orders/{$order}/payment/freight/inr-actual", ['freight_inr_actual' => '24900']), 'POST /orders/{id}/payment/freight/inr-actual');
        self::assertSame(24900.0, (float) $this->value("SELECT freight_inr_actual FROM order_payment_status WHERE order_id = {$order}"));

        foreach (['advance', 'balance', 'freight'] as $leg) {
            $this->assertDone($this->call('POST', "/orders/{$order}/payment/{$leg}/inr-actual/delete"), "POST /orders/{id}/payment/{$leg}/inr-actual/delete");
            self::assertNull($this->value("SELECT {$leg}_inr_actual FROM order_payment_status WHERE order_id = {$order}"), $leg);
        }
        self::assertSame(3, $this->audits('CA_INR_ACTUAL_DELETED', $order));

        $this->assertPage($this->call('GET', "/orders/{$order}/zoho-check"), 'Zoho', 'GET /orders/{id}/zoho-check');

        $archived = $this->createTestOrder($this->createTestClient());
        $pdo->exec("UPDATE orders SET is_archived = 1, order_reference = 'RT-ARCHIVED-1' WHERE id = {$archived}");
        $this->assertPage($this->call('GET', '/orders/archived'), 'RT-ARCHIVED-1', 'GET /orders/archived');
    }

    public function testOrderProductLinesAndPiSkip(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $hs = (string) $this->value('SELECT code FROM hs_codes WHERE is_active = 1 ORDER BY id LIMIT 1');
        $line = $this->insert('order_products', ['order_id' => $order, 'line_no' => 1, 'description' => 'Route line', 'quantity' => 10, 'unit' => 'pcs', 'unit_price' => 12, 'hs_code' => $hs]);

        $r = $this->call('POST', "/orders/{$order}/products/{$line}", ['description' => 'Route edited line', 'hs_code' => $hs, 'quantity' => '12', 'unit' => 'pcs', 'unit_price' => '15', 'reason' => 'Route behaviour test edit']);
        $this->assertDone($r, 'POST /orders/{id}/products/{productId}');
        self::assertSame('Route edited line', $this->value("SELECT description FROM order_products WHERE id = {$line}"));

        $this->assertDone($this->call('POST', "/orders/{$order}/products/{$line}/duplicate", ['reason' => 'Route behaviour test copy']), 'POST /orders/{id}/products/{productId}/duplicate');
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM order_products WHERE order_id = {$order} AND description LIKE 'Route edited line%'"));

        $this->assertDone($this->call('POST', "/orders/{$order}/products/{$line}/delete", ['reason' => 'Route behaviour test removal']), 'POST /orders/{id}/products/{productId}/delete');
        self::assertSame(0, (int) $this->value("SELECT is_active FROM order_products WHERE id = {$line}"), 'removed from the order (kept for history)');

        $r = $this->call('POST', "/orders/{$order}/pi-form/skip", ['skip_reason' => '']);
        self::assertSame('error', $r['type'], 'a skip needs its reason');
        self::assertNull($this->value("SELECT pi_form_skipped_at FROM orders WHERE id = {$order}"));
        $this->assertDone($this->call('POST', "/orders/{$order}/pi-form/skip", ['skip_reason' => 'Buyer sent all PI details by e-mail already']), 'POST /orders/{id}/pi-form/skip');
        self::assertNotNull($this->value("SELECT pi_form_skipped_at FROM orders WHERE id = {$order}"));
        self::assertSame(1, $this->audits('PI_FORM_SKIPPED', $order));
    }

    public function testOrderCommentAttachmentVendorBillAndReorder(): void
    {
        $pdo = Database::connection();
        $client = $this->createTestClient();
        $order = $this->createTestOrder($client);

        $comment = $this->insert('order_comments', ['order_id' => $order, 'author_type' => 'client', 'author_client_id' => $client, 'body' => 'See attached']);
        $file = $this->createTestFile($order, $client);
        $path = $this->tmpFile('pdf', '%PDF-1.4 route test');
        $pdo->exec("UPDATE file_store SET server_path = " . $pdo->quote($path) . ", file_size_bytes = 19 WHERE id = {$file}");
        $this->insert('order_comment_attachments', ['comment_id' => $comment, 'file_store_id' => $file]);
        $r = $this->call('GET', "/orders/{$order}/comment-attachments/{$file}/download");
        self::assertSame(200, $r['code']);
        self::assertSame('%PDF-1.4 route test', $r['body'], 'GET /orders/{id}/comment-attachments/{fileId}/download');

        $bill = $this->insert('order_vendor_bills', ['order_id' => $order, 'vendor_kind' => 'logistics', 'bill_for' => 'freight', 'bill_no' => 'RT-BILL-1', 'bill_date' => date('Y-m-d'), 'taxable_inr' => 1000, 'total_inr' => 1000, 'created_by' => $this->holder]);
        $r = $this->call('POST', "/orders/{$order}/vendor-bills/{$bill}/delete");
        self::assertLessThan(400, $r['code'], 'POST /orders/{id}/vendor-bills/{billId}/delete');
        self::assertNull($this->value("SELECT id FROM order_vendor_bills WHERE id = {$bill}"));
        self::assertSame(1, $this->audits('VENDOR_BILL_REMOVED', $bill));

        $request = $this->insert('order_reorder_requests', ['client_id' => $client, 'source_order_id' => $order, 'status' => 'pending']);
        $hs = (string) $this->value('SELECT code FROM hs_codes WHERE is_active = 1 ORDER BY id LIMIT 1');
        $r = $this->call('POST', "/reorder-requests/{$request}/approve", ['description' => ['Route reorder line'], 'hs_code' => [$hs], 'quantity' => ['5'], 'unit' => ['pcs'], 'unit_price' => ['20']]);
        $this->assertDone($r, 'POST /reorder-requests/{id}/approve', 'new order created');
        $newOrder = (int) $this->value("SELECT new_order_id FROM order_reorder_requests WHERE id = {$request}");
        self::assertGreaterThan(0, $newOrder);
        self::assertSame('Route reorder line', $this->value("SELECT description FROM order_products WHERE order_id = {$newOrder}"));
    }

    public function testSupplierPoDownloadByLink(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $pdo = Database::connection();
        $token = bin2hex(random_bytes(16));
        $file = $this->createTestFile($order);
        $pdo->exec("UPDATE file_store SET server_path = " . $pdo->quote($this->tmpFile('pdf', '%PDF-1.4 supplier po')) . ", file_size_bytes = 20 WHERE id = {$file}");
        $supplier = $this->insert('suppliers', ['supplier_legal_name' => 'Route PO Supplier']);
        $po = $this->insert('order_supplier_po', ['order_id' => $order, 'supplier_id' => $supplier, 'supplier_legal_name' => 'Route PO Supplier', 'supplier_po_reference' => 'RT-SPO-1', 'supplier_action_token_hash' => hash('sha256', $token), 'supplier_action_token_expires_at' => date('Y-m-d H:i:s', time() + 86400)]);
        $this->createTestDocument($order, 'SUPPO', 'sent', $file);

        $_SESSION = [];
        $r = $this->call('GET', "/supplier-action/{$token}/po-document");
        self::assertSame(200, $r['code']);
        self::assertSame('%PDF-1.4 supplier po', $r['body'], 'GET /supplier-action/{token}/po-document');
        $r = $this->call('GET', '/supplier-action/' . str_repeat('0', 32) . '/po-document');
        self::assertStringNotContainsString('%PDF', $r['body'], 'an unknown link serves no file');
        self::assertStringContainsString('expired', strtolower($r['body']));
    }

    // ---------------------------------------------------------------- documents, reviews, amendments, e-mail

    public function testReviewsAndCrossVerification(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $doc = $this->createTestDocument($order, 'QT', 'draft');
        $reviewer = $this->createTestUser('Managing Director');
        $r = $this->call('POST', "/documents/{$doc}/reviewers", ['reviewer_ids' => [(string) $reviewer]]);
        $this->assertDone($r, 'POST /documents/{id}/reviewers', 'in review');
        $review = (int) $this->value("SELECT id FROM document_reviews WHERE document_id = {$doc} AND reviewer_id = {$reviewer}");
        self::assertGreaterThan(0, $review);

        $this->signIn($reviewer);
        $this->assertDone($this->call('POST', "/reviews/{$review}/approve", ['comments' => 'Looks right']), 'POST /reviews/{id}/approve');
        self::assertSame('approved', $this->value("SELECT status FROM document_reviews WHERE id = {$review}"));

        $this->signIn($this->holder);
        $this->assertDone($this->call('POST', "/documents/{$doc}/cross-verify", ['result' => 'pass', 'comments' => 'Figures match']), 'POST /documents/{id}/cross-verify');
        self::assertSame('pass', $this->value("SELECT result FROM document_cross_verifications WHERE document_id = {$doc}"));
    }

    public function testAmendmentOverrideAndSignedCopy(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $amendment = $this->insert('amendments', ['amendment_reference' => 'RT-AMD-1', 'order_id' => $order, 'reason' => 'Route test', 'requested_by' => 'exporter', 'original_terms_snapshot' => '{}', 'status' => 'md_approved']);
        $r = $this->call('POST', "/amendments/{$amendment}/override-reference", ['amendment_reference' => 'RT-AMD-2', 'reason' => 'Route behaviour test override']);
        $this->assertDone($r, 'POST /amendments/{id}/override-reference', 'RT-AMD-2');
        self::assertSame('RT-AMD-2', $this->value("SELECT amendment_reference FROM amendments WHERE id = {$amendment}"));

        // The signed copy needs a real HTTP upload; without one it is refused and the amendment stays as it was.
        $r = $this->call('POST', "/amendments/{$amendment}/signed-copy");
        self::assertSame('error', $r['type'], 'POST /amendments/{id}/signed-copy without a file');
        self::assertSame('md_approved', $this->value("SELECT status FROM amendments WHERE id = {$amendment}"));
        self::assertNull($this->value("SELECT signed_copy_file_id FROM amendments WHERE id = {$amendment}"));
    }

    public function testQueuedEmails(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $requester = $this->createTestUser('Export Executive');
        $queue = fn (string $status, int $by): int => $this->insert('email_log', ['order_id' => $order, 'recipient_email' => 'buyer@route.test', 'subject' => 'Route test', 'body_snapshot' => 'Hello', 'status' => $status, 'requested_by' => $by]);

        $toReject = $queue('pending_approval', $requester);
        $this->assertDone($this->call('POST', "/email-log/{$toReject}/reject", ['reason' => 'Wrong attachment for this buyer']), 'POST /email-log/{id}/reject');
        self::assertSame('rejected', $this->value("SELECT status FROM email_log WHERE id = {$toReject}"));
        self::assertSame(1, $this->audits('EMAIL_SEND_REJECTED', $toReject));

        $toCancel = $queue('pending_approval', $this->holder);
        $this->assertDone($this->call('POST', "/email-log/{$toCancel}/cancel", ['order_id' => (string) $order, 'reason' => 'Buyer asked us to wait a day']), 'POST /email-log/{id}/cancel');
        self::assertSame('cancelled', $this->value("SELECT status FROM email_log WHERE id = {$toCancel}"));

        // Send now: mail sending is off in the test database, so the attempt is made and reported as failed.
        $toSend = $queue('approved', $requester);
        $r = $this->call('POST', "/email-log/{$toSend}/send-now");
        self::assertNotNull($r['flash'], 'POST /email-log/{id}/send-now');
        self::assertSame(1, $this->audits('EMAIL_SEND_FORCED_NOW', $toSend));
        self::assertNotSame('approved', $this->value("SELECT status FROM email_log WHERE id = {$toSend}"));
    }

    // ---------------------------------------------------------------- CA module and incentives

    public function testCaBankLinesExpensesAndExportBenefits(): void
    {
        $expense = $this->insert('ca_expenses', ['zoho_expense_id' => 'RT-EXP-1', 'category' => 'Freight', 'amount' => 1180, 'expense_date' => date('Y-m-d')]);
        $line = $this->insert('ca_bank_statement_lines', ['line_hash' => bin2hex(random_bytes(16)), 'transaction_date' => date('Y-m-d'), 'description' => 'Route test debit', 'debit_amount' => 1180]);

        $this->assertDone($this->call('POST', "/ca/bank-statement/{$line}/match-expense", ['expense_id' => (string) $expense]), 'POST /ca/bank-statement/{id}/match-expense');
        self::assertSame($expense, (int) $this->value("SELECT matched_expense_id FROM ca_bank_statement_lines WHERE id = {$line}"));
        $this->assertDone($this->call('POST', "/ca/bank-statement/{$line}/unmatch"), 'POST /ca/bank-statement/{id}/unmatch');
        self::assertNull($this->value("SELECT matched_expense_id FROM ca_bank_statement_lines WHERE id = {$line}"));

        $this->assertDone($this->call('POST', "/ca/expenses/{$expense}/tds", ['is_tds_applicable' => '1', 'tds_amount' => '18']), 'POST /ca/expenses/{id}/tds');
        self::assertSame(18.0, (float) $this->value("SELECT tds_amount FROM ca_expenses WHERE id = {$expense}"));

        $claim = $this->insert('ca_export_benefits', ['scheme_name' => 'RoDTEP', 'claimed_amount' => 5000, 'claimed_at' => date('Y-m-d'), 'recorded_by' => $this->holder]);
        $this->assertDone($this->call('POST', "/ca/export-benefits/{$claim}/mark-received", ['received_amount' => '4900', 'received_at' => date('Y-m-d'), 'received_reference' => 'ESCRIP-0002']), 'POST /ca/export-benefits/{id}/mark-received');
        self::assertSame(4900.0, (float) $this->value("SELECT received_amount FROM ca_export_benefits WHERE id = {$claim}"));
        self::assertSame(1, $this->audits('CA_EXPORT_BENEFIT_RECEIVED', $claim));
    }

    /** Owner decision 2026-10-31 (option A): CA → GST refunds (LUT) — the page, a claim, a bank credit, removing a credit not yet in Zoho. */
    public function testGstRefundsLut(): void
    {
        $arn = 'RT' . strtoupper(bin2hex(random_bytes(5)));
        $this->assertDone($this->call('POST', '/ca/gst-refunds', ['period_from' => '2026-10-01', 'period_to' => '2026-12-31', 'claim_arn' => $arn, 'filed_on' => date('Y-m-d'), 'amount_claimed' => '206000']), 'POST /ca/gst-refunds');
        $claim = (int) $this->value("SELECT id FROM gst_refund_claims WHERE claim_arn = '{$arn}'");
        self::assertSame(1, $this->audits('GST_REFUND_CLAIM_RECORDED', $claim));
        $this->assertPage($this->call('GET', '/ca/gst-refunds'), $arn, 'GET /ca/gst-refunds');

        $this->assertDone($this->call('POST', "/ca/gst-refunds/{$claim}/credits", ['amount' => '185400', 'credited_on' => date('Y-m-d'), 'bank_reference' => 'RFD05-RT-0001', 'part' => 'provisional']), 'POST /ca/gst-refunds/{id}/credits');
        $credit = (int) $this->value("SELECT id FROM gst_refund_credits WHERE claim_id = {$claim}");
        self::assertSame(1, $this->audits('GST_REFUND_CREDITED', $credit));
        $this->assertDone($this->call('POST', "/ca/gst-refunds/credits/{$credit}/delete", []), 'POST /ca/gst-refunds/credits/{creditId}/delete');
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM gst_refund_credits WHERE claim_id = {$claim}"));
    }

    public function testIncentivePayoutsPaid(): void
    {
        $order = $this->createTestOrder($this->createTestClient());
        $month = date('Y-m');
        $payout = fn (): int => $this->insert('incentive_payouts', ['order_id' => $order, 'payee_kind' => 'staff', 'user_id' => $this->holder, 'role' => 'sales', 'amount_inr' => 5000, 'incentive_month' => $month, 'status' => 'due']);

        $one = $payout();
        $this->assertDone($this->call('POST', "/incentives/payouts/{$one}/paid", ['month' => $month, 'paid_on' => date('Y-m-d'), 'paid_inr' => '5000', 'tds_inr' => '500', 'paid_via' => 'salary', 'paid_reference' => 'Payroll TEST']), 'POST /incentives/payouts/{id}/paid');
        self::assertSame('paid', $this->value("SELECT status FROM incentive_payouts WHERE id = {$one}"));
        self::assertSame(500.0, (float) $this->value("SELECT tds_inr FROM incentive_payouts WHERE id = {$one}"));

        [$a, $b] = [$payout(), $payout()];
        $r = $this->call('POST', '/incentives/payouts/paid-all', ['month' => $month, 'paid_on' => date('Y-m-d'), 'payout_ids' => [(string) $a, (string) $b], 'paid_via' => 'salary', 'paid_reference' => 'Payroll TEST']);
        $this->assertDone($r, 'POST /incentives/payouts/paid-all', '2 payments recorded');
        self::assertSame(2, (int) $this->value("SELECT COUNT(*) FROM incentive_payouts WHERE id IN ({$a}, {$b}) AND status = 'paid'"));
    }

    // ---------------------------------------------------------------- reports

    public function testReportAndCaPagesOpen(): void
    {
        $this->createTestOrder($this->createTestClient());
        $pages = [
            '/reports/ageing', '/reports/aggregate', '/reports/amendments', '/reports/conversion', '/reports/disputes',
            '/reports/order-profitability', '/reports/payments', '/reports/performance', '/reports/products', '/reports/queues',
            '/reports/staff', '/reports/suppliers', '/reports/trends',
            '/ca/bank-statement', '/ca/export-benefits', '/ca/fy-locks', '/ca/reconciliation', '/ca/reports', '/ca/tds-summary',
            '/email-approvals', '/admin/permissions',
        ];
        foreach ($pages as $page) {
            $r = $this->call('GET', $page);
            self::assertSame(200, $r['code'], "GET {$page}");
            self::assertStringContainsString('<h1', $r['body'], "GET {$page} shows its page");
            self::assertStringNotContainsString('Warning:', $r['body'], "GET {$page}");
        }

        $scheme = (string) $this->value("SELECT option_value FROM dropdown_options WHERE list_key = 'export_benefit_scheme' ORDER BY id LIMIT 1");
        $r = $this->call('POST', '/ca/export-benefits', ['scheme_name' => $scheme, 'claimed_amount' => '1250', 'claimed_at' => date('Y-m-d'), 'reference_number' => 'RT-CLAIM-1']);
        $this->assertDone($r, 'POST /ca/export-benefits', 'claim recorded');
        $claim = (int) $this->value("SELECT id FROM ca_export_benefits WHERE reference_number = 'RT-CLAIM-1'");
        self::assertSame(1, $this->audits('CA_EXPORT_BENEFIT_RECORDED', $claim));
    }

    public function testAssociateAndPartnerEditsExchangeRateAndReviewRejection(): void
    {
        $associate = $this->insert('associates', ['name' => 'Route Test Associate', 'based_in' => 'india', 'currency_code' => 'INR', 'status' => 'active']);
        $rates = ['rate_1_5' => '5', 'rate_6_10' => '5', 'rate_11_20' => '4', 'rate_21_40' => '3', 'rate_41_plus' => '2'];
        $r = $this->call('POST', "/associates/{$associate}/update", ['name' => 'Route Test Associate B', 'based_in' => 'india', 'currency_code' => 'INR', 'status' => 'active'] + $rates);
        $this->assertDone($r, 'POST /associates/{id}/update');
        self::assertSame('Route Test Associate B', $this->value("SELECT name FROM associates WHERE id = {$associate}"));
        self::assertSame(1, $this->audits('ASSOCIATE_UPDATED', $associate));

        $partner = $this->insert('logistics_partners', ['partner_name' => 'Route Test CHA', 'service_type' => 'cha', 'is_active' => 1]);
        $this->assertDone($this->call('POST', "/logistics-partners/{$partner}/update", ['partner_name' => 'Route Test CHA B', 'service_type' => 'cha']), 'POST /logistics-partners/{id}/update');
        self::assertSame('Route Test CHA B', $this->value("SELECT partner_name FROM logistics_partners WHERE id = {$partner}"));
        self::assertSame(1, $this->audits('LOGISTICS_PARTNER_UPDATED', $partner));
        $this->assertDone($this->call('POST', "/logistics-partners/{$partner}/details-link"), 'POST /logistics-partners/{id}/details-link', 'Vendor Details Link');
        self::assertSame(1, $this->audits('VENDOR_DETAILS_LINK_GENERATED', $partner));

        $order = $this->createTestOrder($this->createTestClient());
        Database::connection()->exec("INSERT IGNORE INTO order_payment_status (order_id) VALUES ({$order})");
        Database::connection()->exec("UPDATE order_payment_status SET balance_amount = 2000, balance_remittance_received_at = NOW() WHERE order_id = {$order}");
        $this->assertDone($this->call('POST', "/orders/{$order}/payment/balance/exchange-rate", ['balance_exchange_rate' => '83.25']), 'POST /orders/{id}/payment/balance/exchange-rate');
        self::assertSame(83.25, (float) $this->value("SELECT balance_exchange_rate FROM order_payment_status WHERE order_id = {$order}"));
        self::assertSame(1, $this->audits('CA_EXCHANGE_RATE_RECORDED', $order));

        $doc = $this->createTestDocument($order, 'QT', 'draft');
        $reviewer = $this->createTestUser('Managing Director');
        $this->assertDone($this->call('POST', "/documents/{$doc}/reviewers", ['reviewer_ids' => [(string) $reviewer]]), 'assign a reviewer');
        $review = (int) $this->value("SELECT id FROM document_reviews WHERE document_id = {$doc} AND reviewer_id = {$reviewer}");
        $this->signIn($reviewer);
        $this->assertDone($this->call('POST', "/reviews/{$review}/reject", ['comments' => 'The freight figure is wrong']), 'POST /reviews/{id}/reject', 'sent back');
        self::assertSame('rejected', $this->value("SELECT status FROM document_reviews WHERE id = {$review}"));
    }

    // ---------------------------------------------------------------- routes Jest tested and PHPUnit did not

    /**
     * QA's route search also showed routes that Jest tests and PHPUnit did
     * not (R5-14: "keep PHPUnit and Jest suites in step route by route").
     * PHP cannot complete a real file upload inside PHPUnit (it needs
     * move_uploaded_file on an HTTP upload), so for the upload routes this
     * checks the refusal and that nothing is stored; Jest covers the upload
     * itself.
     */
    public function testRoutesJestCoveredAndPhpunitDidNot(): void
    {
        $pdo = Database::connection();
        $holderEmail = (string) $this->value("SELECT email FROM users WHERE id = {$this->holder}");
        $this->assertPage($this->call('GET', '/account'), $holderEmail, 'GET /account');
        $role = (int) $this->value("SELECT id FROM roles WHERE name = 'Viewer / Auditor'");
        $this->assertPage($this->call('GET', "/admin/roles/{$role}/permissions"), 'Viewer / Auditor', 'GET /admin/roles/{id}/permissions');
        $this->assertPage($this->call('GET', '/reference-docs/categories'), 'name="name"', 'GET /reference-docs/categories');

        $client = $this->createTestClient('route-parity-buyer@example.test');
        $order = $this->createTestOrder($client);
        $this->assertPage($this->call('GET', "/orders/{$order}/email/compose"), 'name="template_key"', 'GET /orders/{id}/email/compose');

        $type = (int) $this->value("SELECT id FROM document_types WHERE code = 'PI'");
        $previous = $pdo->query("SELECT document_type_id, user_id, use_designation_seal FROM document_type_signatories WHERE document_type_id = {$type}")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertDone($this->call('POST', "/signatories/document-types/{$type}", ['user_id' => (string) $this->holder, 'use_designation_seal' => '0']), 'POST /signatories/document-types/{id}');
        self::assertSame($this->holder, (int) $this->value("SELECT user_id FROM document_type_signatories WHERE document_type_id = {$type}"));
        // Later tests generate PIs signed by the seeded signatory.
        $pdo->exec("DELETE FROM document_type_signatories WHERE document_type_id = {$type}");
        foreach ($previous as $row) {
            $this->insert('document_type_signatories', $row);
        }

        $person = $this->createTestUser('Export Executive');
        $r = $this->call('POST', "/users/{$person}/force-reset-password", ['reason' => 'They are locked out before a buyer call']);
        $this->assertDone($r, 'POST /users/{id}/force-reset-password', 'temporary password');
        self::assertSame(1, $this->audits('PASSWORD_ADMIN_RESET', $person));
        self::assertSame(1, (int) $this->value("SELECT force_password_change FROM users WHERE id = {$person}"));

        $requester = $this->createTestUser('Export Executive');
        $pending = $this->insert('email_log', ['order_id' => $order, 'recipient_email' => 'buyer@route.test', 'subject' => 'Route test', 'body_snapshot' => 'Hello', 'status' => 'pending_approval', 'requested_by' => $requester]);
        $this->assertDone($this->call('POST', "/email-log/{$pending}/approve"), 'POST /email-log/{id}/approve');
        self::assertSame('approved', $this->value("SELECT status FROM email_log WHERE id = {$pending}"));
        self::assertSame(1, $this->audits('EMAIL_SEND_APPROVED', $pending));

        // Upload routes: refused without a file, nothing stored.
        $dispute = $this->insert('disputes', ['order_id' => $order, 'notice_date' => date('Y-m-d'), 'description' => 'Route test dispute', 'status' => 'open']);
        $product = $this->insert('order_annexure_products', ['order_id' => $order, 'name' => 'Route annexure line']);
        $supplier = $this->insert('suppliers', ['supplier_legal_name' => 'Route Upload Supplier']);
        $this->insert('order_supplier_po', ['order_id' => $order, 'supplier_id' => $supplier, 'supplier_legal_name' => 'Route Upload Supplier', 'supplier_po_reference' => 'RT-SPO-UP']);
        $filesBefore = (int) $this->value('SELECT COUNT(*) FROM file_store');
        $uploads = [
            ['/company-assets/replace', ['asset_type' => 'logo', 'name' => 'Route logo']],
            ["/disputes/{$dispute}/documents", []],
            ["/orders/{$order}/annexure/products/{$product}/images", []],
            ["/orders/{$order}/bl-copy", []],
            ["/orders/{$order}/buyer-po/documents", []],
            ["/orders/{$order}/supplier-po/documents", []],
            ["/signatories/users/{$this->holder}/upload", ['asset_kind' => 'signature', 'label' => 'Route']],
        ];
        foreach ($uploads as [$path, $fields]) {
            $r = $this->call('POST', $path, $fields);
            self::assertLessThan(500, $r['code'], "POST {$path}");
            self::assertSame('error', $r['type'], "POST {$path}");
            self::assertStringContainsString('No file was uploaded', (string) $r['flash'], "POST {$path}");
        }
        self::assertSame($filesBefore, (int) $this->value('SELECT COUNT(*) FROM file_store'), 'nothing stored');
    }

    public function testLetterOfCreditLeg(): void
    {
        $order = $this->createTestOrder($this->createTestClient('route-lc-buyer@example.test'), 'FOB', 'Irrevocable Letter of Credit at Sight');
        \App\Repositories\OrderPaymentStatusRepository::initializeForOrder($order);
        foreach ([1, 2] as $stage) {
            \App\Services\StageGateService::passAndUnlockNext($order, $stage, null);
        }
        $r = $this->call('POST', "/orders/{$order}/payment/lc/confirm-usable");
        self::assertSame('error', $r['type'], 'POST /orders/{id}/payment/lc/confirm-usable before the LC details');

        $this->assertDone($this->call('POST', "/orders/{$order}/payment/lc", ['lc_bank_name' => 'Route Test Bank', 'lc_number' => 'LC-ROUTE-001', 'lc_amount' => '10000', 'lc_charges_amount' => '150']), 'POST /orders/{id}/payment/lc');
        self::assertSame(10000.0, (float) $this->value("SELECT lc_amount FROM order_lc_details WHERE order_id = {$order}"));
        self::assertNull($this->value("SELECT lc_confirmed_at FROM order_lc_details WHERE order_id = {$order}"));

        $this->createTestDocument($order, 'PI', 'sent');
        $this->assertDone($this->call('POST', "/orders/{$order}/payment/lc/confirm-usable"), 'POST /orders/{id}/payment/lc/confirm-usable');
        self::assertNotNull($this->value("SELECT lc_confirmed_at FROM order_lc_details WHERE order_id = {$order}"));
        self::assertTrue(\App\Services\StageGateService::isUnlocked($order, 4), 'a usable LC opens Stage 4');
    }

    public function testBuyerSupplierAndClientPortalFiles(): void
    {
        $pdo = Database::connection();
        $client = $this->createTestClient();
        $order = $this->createTestOrder($client);
        $pdf = function (string $text) use ($order, $client, $pdo): int {
            $file = $this->createTestFile($order, $client);
            $pdo->exec("UPDATE file_store SET server_path = " . $pdo->quote($this->tmpFile('pdf', $text)) . ', file_size_bytes = ' . strlen($text) . " WHERE id = {$file}");
            return $file;
        };

        // The buyer's no-login link serves the issued Proforma Invoice.
        $token = \App\Repositories\OrderRepository::generateBuyerActionToken($order);
        $doc = $this->createTestDocument($order, 'PI', 'sent', $pdf('%PDF-1.4 route PI'));
        $_SESSION = [];
        $r = $this->call('GET', "/buyer-action/{$token}/proforma-invoice");
        self::assertSame('%PDF-1.4 route PI', $r['body'], 'GET /buyer-action/{token}/proforma-invoice');

        // The supplier's no-login link refuses an acknowledgment without a file.
        $supplierToken = bin2hex(random_bytes(16));
        $supplier = $this->insert('suppliers', ['supplier_legal_name' => 'Route Ack Supplier']);
        $po = $this->insert('order_supplier_po', ['order_id' => $order, 'supplier_id' => $supplier, 'supplier_legal_name' => 'Route Ack Supplier', 'supplier_po_reference' => 'RT-SPO-ACK', 'supplier_action_token_hash' => hash('sha256', $supplierToken), 'supplier_action_token_expires_at' => date('Y-m-d H:i:s', time() + 86400)]);
        $r = $this->call('POST', "/supplier-action/{$supplierToken}/acknowledgment");
        self::assertLessThan(500, $r['code'], 'POST /supplier-action/{token}/acknowledgment');
        self::assertSame(0, (int) $this->value("SELECT COUNT(*) FROM order_supplier_po_documents WHERE order_supplier_po_id = {$po}"), 'nothing stored without a file');

        // The client portal: account page, a message attachment and a payment screenshot.
        $this->insert('client_logins', ['client_id' => $client, 'password_hash' => 'x', 'is_active' => 1, 'force_password_change' => 0]);
        $_SESSION = ['_client_portal_client_id' => $client];
        $this->assertPage($this->call('GET', '/client/account'), 'PHPUnit Test Buyer Ltd', 'GET /client/account');
        $comment = $this->insert('order_comments', ['order_id' => $order, 'author_type' => 'staff', 'author_user_id' => $this->holder, 'body' => 'Attached']);
        $file = $pdf('%PDF-1.4 route attachment');
        $this->insert('order_comment_attachments', ['comment_id' => $comment, 'file_store_id' => $file]);
        $r = $this->call('GET', "/client/orders/{$order}/comment-attachments/{$file}/download");
        self::assertSame('%PDF-1.4 route attachment', $r['body'], 'GET /client/orders/{id}/comment-attachments/{fileId}/download');
        $shot = $pdf('%PDF-1.4 route screenshot');
        $report = $this->insert('client_payment_reports', ['order_id' => $order, 'payment_type' => 'advance', 'transaction_ref' => 'UTR-ROUTE', 'amount' => 100, 'payment_date' => date('Y-m-d'), 'screenshot_file_id' => $shot, 'status' => 'new']);
        $r = $this->call('GET', "/client/orders/{$order}/payment-reports/{$report}/screenshot");
        self::assertSame('%PDF-1.4 route screenshot', $r['body'], 'GET /client/orders/{id}/payment-reports/{reportId}/screenshot');
        unset($doc);
    }

    // ---------------------------------------------------------------- missing ids

    /** The routes QA listed (plus five found since) whose path carries a record id. */
    private const ID_ROUTES = [
        ['POST', '/admin/field-protection/{id}/approve'], ['POST', '/admin/field-protection/{id}/reject'],
        ['POST', '/admin/permission-definitions/{id}/delete'], ['GET', '/admin/permission-definitions/{id}/edit'],
        ['POST', '/admin/permissions/{id}/remove'], ['POST', '/admin/roles/{id}/delete'], ['GET', '/admin/roles/{id}/edit'],
        ['POST', '/amendments/{id}/override-reference'], ['POST', '/amendments/{id}/signed-copy'],
        ['POST', '/associates/{id}/clients'], ['POST', '/associates/{id}/clients/{id}/unlink'], ['POST', '/associates/{id}/update', self::VALID_ASSOCIATE],
        ['POST', '/ca/bank-statement/{id}/match-expense'], ['POST', '/ca/bank-statement/{id}/unmatch'],
        ['POST', '/ca/expenses/{id}/tds'], ['POST', '/ca/export-benefits/{id}/mark-received'],
        ['POST', '/ca/gst-refunds/{id}/credits'], ['POST', '/ca/gst-refunds/credits/{id}/delete'],
        ['GET', '/clients/{id}/edit'], ['POST', '/clients/{id}/override-unique-number'],
        ['POST', '/company-assets/{id}/delete'],
        ['POST', '/compliance-task-types/{id}/delete'], ['GET', '/compliance-task-types/{id}/edit'], ['POST', '/compliance-task-types/{id}/update'],
        ['POST', '/documents/{id}/cross-verify'], ['POST', '/documents/{id}/reviewers'],
        ['POST', '/email-log/{id}/cancel'], ['POST', '/email-log/{id}/reject'], ['POST', '/email-log/{id}/send-now'],
        ['GET', '/email-templates/{id}/edit'],
        ['POST', '/holidays/{id}/delete'], ['POST', '/holidays/{id}/update'],
        ['POST', '/hs-codes/{id}/delete'], ['POST', '/hs-codes/{id}/toggle'], ['POST', '/hs-codes/product-guide/{id}/delete'],
        ['POST', '/incentives/payouts/{id}/paid'],
        ['POST', '/logistics-partners/{id}/delete'], ['GET', '/logistics-partners/{id}/edit'], ['POST', '/logistics-partners/{id}/toggle'],
        ['POST', '/logistics-partners/{id}/update'], ['POST', '/logistics-partners/{id}/details-link'],
        ['GET', '/orders/{id}/comment-attachments/{id}/download'], ['POST', '/orders/{id}/dispute-visibility'],
        ['POST', '/orders/{id}/payment/advance/inr-actual/delete'], ['POST', '/orders/{id}/payment/balance/firc'], ['POST', '/orders/{id}/payment/balance/exchange-rate'],
        ['POST', '/orders/{id}/payment/balance/inr-actual/delete'], ['POST', '/orders/{id}/payment/freight/firc'],
        ['POST', '/orders/{id}/payment/freight/inr-actual'], ['POST', '/orders/{id}/payment/freight/inr-actual/delete'],
        ['POST', '/orders/{id}/pi-form/skip'], ['POST', '/orders/{id}/products/{id}'], ['POST', '/orders/{id}/products/{id}/delete'],
        ['POST', '/orders/{id}/products/{id}/duplicate'], ['POST', '/orders/{id}/vendor-bills/{id}/delete'], ['GET', '/orders/{id}/zoho-check'],
        ['GET', '/payment-presets/{id}/edit'], ['POST', '/payment-presets/{id}/toggle'], ['POST', '/payment-presets/{id}/update'],
        ['GET', '/port-routes/{id}/edit'], ['POST', '/port-routes/{id}/update'],
        ['GET', '/ports/{id}/edit'], ['POST', '/ports/{id}/set-default'], ['POST', '/ports/{id}/toggle'], ['POST', '/ports/{id}/update'],
        ['GET', '/products/{id}'], ['POST', '/products/{id}/delete'], ['GET', '/products/{id}/edit'], ['POST', '/products/{id}/images/upload'],
        ['POST', '/products/{id}/misc-charges/add'], ['POST', '/products/{id}/suppliers/add'], ['POST', '/products/{id}/update'],
        ['POST', '/products/images/{id}/delete'], ['GET', '/products/images/{id}/view'], ['POST', '/products/misc-charges/{id}/delete'],
        ['POST', '/products/suppliers/{id}/delete'], ['POST', '/products/suppliers/{id}/set-primary'], ['POST', '/products/suppliers/{id}/update'],
        ['POST', '/reference-docs/categories/{id}/delete'], ['POST', '/reference-docs/categories/{id}/update'],
        ['POST', '/reference-docs/custom/{id}/delete'], ['GET', '/reference-docs/custom/{id}/edit'],
        ['POST', '/reorder-requests/{id}/approve'], ['POST', '/reviews/{id}/approve'], ['POST', '/reviews/{id}/reject'],
        ['GET', '/signatories/user-assets/{id}/preview'], ['POST', '/signatories/users/{id}/eligibility'],
        ['POST', '/suppliers/{id}/details-link'],
        ['GET', '/users/{id}/edit'], ['POST', '/users/{id}/toggle-active'], ['POST', '/users/{id}/unarchive'], ['POST', '/users/{id}/update'],
    ];

    /** A complete associate form, so the missing record is what gets refused, not a blank field. */
    private const VALID_ASSOCIATE = ['name' => 'Route Ghost Associate', 'based_in' => 'india', 'currency_code' => 'INR', 'status' => 'active', 'rate_1_5' => '5', 'rate_6_10' => '5', 'rate_11_20' => '4', 'rate_21_40' => '3', 'rate_41_plus' => '2'];

    public function testEveryRouteAnswersAMissingIdAsNotFoundAndWritesNothing(): void
    {
        $auditBefore = (int) $this->value('SELECT COUNT(*) FROM audit_log');
        $wrong = [];
        foreach (self::ID_ROUTES as $route) {
            [$method, $pattern] = $route;
            $path = str_replace('{id}', '999999999', $pattern);
            try {
                $r = $this->call($method, $path, ($route[2] ?? []) + ['reason' => 'Route behaviour test of a missing record']);
            } catch (\Throwable $e) {
                $wrong[] = "{$method} {$pattern} → " . get_class($e) . ': ' . $e->getMessage();
                continue;
            }
            $notFound = $r['code'] === 404 || in_array($r['type'], ['error', 'warning'], true);
            if (!$notFound || $r['code'] >= 500) {
                $wrong[] = "{$method} {$pattern} → {$r['code']} " . ($r['type'] ?? '') . ' ' . ($r['flash'] ?? substr(strip_tags($r['body']), 0, 80));
            }
        }
        self::assertSame([], $wrong);
        self::assertSame(92, count(self::ID_ROUTES));
        self::assertSame($auditBefore, (int) $this->value('SELECT COUNT(*) FROM audit_log'), 'no audit row for a record that does not exist');
    }
}
