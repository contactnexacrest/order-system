<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Repositories\ClientIntakeRepository;
use App\Repositories\PiIntakeRepository;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * QA-4 P2 (docs/QA/TEST_PLAN.md Section 6): CSRF, staff-side IDOR beyond
 * the client portal, and public unauthenticated endpoints.
 */
final class IdorAndInputValidationTest extends DbTestCase
{
    // ---------------------------------------------------------------
    // 1. Every state-changing POST route requires a valid CSRF token.
    //    CSRF is enforced by shared middleware (CsrfCheck::verify()), not
    //    per-route logic, so a middleware-level static check — every
    //    router registration actually wires the middleware in — is more
    //    valuable here than exercising all 177 POST routes individually.
    // ---------------------------------------------------------------

    public function testEveryPostRouteInTheRouterRequiresCsrfVerification(): void
    {
        $routesFile = __DIR__ . '/../../../public_html/index.php';
        $contents = (string) file_get_contents($routesFile);
        preg_match_all('/\$router->post\([^;]*?\);/s', $contents, $matches);

        self::assertGreaterThan(100, count($matches[0]), 'sanity check: the route file must actually contain the expected volume of POST routes');

        $missing = [];
        foreach ($matches[0] as $routeRegistration) {
            if (!str_contains($routeRegistration, 'CsrfCheck::verify()')) {
                preg_match("/\\\$router->post\\('([^']+)'/", $routeRegistration, $pathMatch);
                $missing[] = $pathMatch[1] ?? $routeRegistration;
            }
        }
        self::assertSame([], $missing, 'every POST route must include CsrfCheck::verify() in its middleware list — missing on: ' . implode(', ', $missing));
    }

    // ---------------------------------------------------------------
    // 2. Staff-side IDOR: does holding one permission (e.g. manage_orders)
    //    let a role reach a DIFFERENT permission's gated actions it was
    //    never granted? Cross-checks docs/QA/TEST_PLAN.md Section 2's role
    //    grant table directly against PermissionService::can() (the same
    //    check every PermissionCheck::requires() route middleware calls),
    //    rather than re-deriving the grants and comparing them to
    //    themselves.
    // ---------------------------------------------------------------

    public function testRolePermissionMatrixMatchesTheDocumentedGrantsExactly(): void
    {
        $cases = [
            // Logistics Executive holds manage_orders but has no CA/finance
            // access at all — the exact "editing CA figures via a crafted
            // request" scenario the test plan names.
            ['Logistics Executive', 'manage_orders', true],
            ['Logistics Executive', 'inr_actual_edit', false],
            ['Logistics Executive', 'ca_module_manage', false],
            ['Logistics Executive', 'ca_fy_lock_override', false],
            ['Logistics Executive', 'view_audit_log', false],
            // Accounts Executive is the one role that SHOULD reach CA
            // financial actions.
            ['Accounts Executive', 'inr_actual_edit', true],
            ['Accounts Executive', 'ca_fy_lock_override', true],
            ['Accounts Executive', 'view_audit_log', false],
            // Viewer / Auditor is read-only — must never reach any
            // order-mutating action.
            ['Viewer / Auditor', 'view_reports', true],
            ['Viewer / Auditor', 'view_audit_log', true],
            ['Viewer / Auditor', 'manage_orders', false],
            ['Viewer / Auditor', 'generate_documents', false],
            // CA / Chartered Accountant is explicitly "view-only... no
            // order-management access at all" per its own seed.sql
            // description — must never reach manage_orders or edit rights,
            // despite being the CA module's own named role.
            ['CA / Chartered Accountant', 'ca_module_view', true],
            ['CA / Chartered Accountant', 'inr_actual_view', true],
            ['CA / Chartered Accountant', 'manage_orders', false],
            ['CA / Chartered Accountant', 'inr_actual_edit', false],
            ['CA / Chartered Accountant', 'ca_module_manage', false],
            // Export Executive handles orders but has no CA access.
            ['Export Executive', 'manage_orders', true],
            ['Export Executive', 'ca_module_view', false],
            // QA-5 RBAC-03/04 (Owner Decision #3: "Must be role + permission
            // based"): manage_orders no longer covers payment clearance or
            // shipping/closure — Logistics must never reach the payment
            // actions, and Accounts must never reach shipping/closure.
            ['Logistics Executive', 'manage_payments', false],
            ['Logistics Executive', 'manage_shipping', true],
            ['Logistics Executive', 'close_orders', true],
            ['Accounts Executive', 'manage_payments', true],
            ['Accounts Executive', 'manage_shipping', false],
            ['Accounts Executive', 'close_orders', false],
            ['Export Executive', 'manage_payments', true],
            ['Export Executive', 'manage_shipping', true],
            ['Export Executive', 'close_orders', true],
        ];

        foreach ($cases as [$roleName, $permissionKey, $expected]) {
            $userId = $this->createTestUser($roleName);
            $roleId = $this->roleIdFor($roleName);
            $actual = PermissionService::can($userId, $roleId, $permissionKey);
            PermissionService::resetCache();
            self::assertSame(
                $expected,
                $actual,
                "{$roleName} " . ($expected ? 'must' : 'must NOT') . " hold '{$permissionKey}'"
            );
        }
    }

    // ---------------------------------------------------------------
    // 3. Public unauthenticated endpoints — token security for the
    //    client-facing intake correction links (ClientIntakeRepository)
    //    and the per-order PI-stage intake link (PiIntakeRepository).
    //    Both compare a SHA-256 hash of a 256-bit random token, never the
    //    raw value, and both additionally gate on status — a guessed
    //    token, an expired token, or the right token used after staff (or
    //    the client) has already moved the submission past the editable
    //    state must all be refused with no data returned.
    // ---------------------------------------------------------------

    private function createTestIntakeSubmission(): int
    {
        return ClientIntakeRepository::create([
            'company_legal_name'     => 'PHPUnit Test Buyer Ltd',
            'billing_address'        => '1 Test Street',
            'vat_eori_tax_no'        => 'VAT123',
            'contact_person'         => 'Test Contact',
            'email'                  => 'buyer@example.test',
            'phone'                  => '',
            'country_of_destination' => 'Testland',
            'port_of_discharge_text' => '',
            'coo_type'               => '',
            'incoterm_preference'    => '',
            'container_type_text'    => '',
            'buyer_own_reference'    => '',
            'notes'                  => '',
        ], '127.0.0.1');
    }

    public function testClientIntakeCorrectionLinkAcceptsTheGenuineTokenWhilePending(): void
    {
        $id = $this->createTestIntakeSubmission();
        $rawToken = bin2hex(random_bytes(32));
        ClientIntakeRepository::setAccessToken($id, hash('sha256', $rawToken), date('Y-m-d H:i:s', time() + 86400));

        $found = ClientIntakeRepository::findValidByToken($rawToken);

        self::assertNotNull($found);
        self::assertSame($id, (int) $found['id']);
    }

    public function testClientIntakeCorrectionLinkRejectsAWrongToken(): void
    {
        $id = $this->createTestIntakeSubmission();
        $rawToken = bin2hex(random_bytes(32));
        ClientIntakeRepository::setAccessToken($id, hash('sha256', $rawToken), date('Y-m-d H:i:s', time() + 86400));

        $guessedToken = bin2hex(random_bytes(32));
        self::assertNull(ClientIntakeRepository::findValidByToken($guessedToken));
    }

    public function testClientIntakeCorrectionLinkRejectsAnExpiredToken(): void
    {
        $id = $this->createTestIntakeSubmission();
        $rawToken = bin2hex(random_bytes(32));
        ClientIntakeRepository::setAccessToken($id, hash('sha256', $rawToken), date('Y-m-d H:i:s', time() - 3600));

        self::assertNull(ClientIntakeRepository::findValidByToken($rawToken));
    }

    public function testClientIntakeCorrectionLinkRejectsTheRealTokenOnceStaffHaveActedOnIt(): void
    {
        $id = $this->createTestIntakeSubmission();
        $rawToken = bin2hex(random_bytes(32));
        ClientIntakeRepository::setAccessToken($id, hash('sha256', $rawToken), date('Y-m-d H:i:s', time() + 86400));
        ClientIntakeRepository::markConverted($id, $this->createTestClient(), $this->createTestUser('Export Executive'));

        self::assertNull(ClientIntakeRepository::findValidByToken($rawToken), 'a converted submission must never be reachable via its old correction link again');
    }

    public function testPiIntakeLinkAcceptsTheGenuineTokenWhileAwaitingClient(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $rawToken = PiIntakeRepository::createLink($orderId, null);

        $found = PiIntakeRepository::findValidByToken($rawToken);

        self::assertNotNull($found);
        self::assertSame($orderId, (int) $found['order_id']);
    }

    public function testPiIntakeLinkRejectsAWrongToken(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        PiIntakeRepository::createLink($orderId, null);

        $guessedToken = bin2hex(random_bytes(32));
        self::assertNull(PiIntakeRepository::findValidByToken($guessedToken));
    }

    public function testPiIntakeLinkRejectsItsOwnTokenOnceTheClientHasSubmitted(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $rawToken = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($rawToken);

        PiIntakeRepository::submit((int) $submission['id'], [
            'company_legal_name' => 'Buyer', 'billing_address' => 'Addr',
            'consignee_name' => null, 'consignee_address' => null,
            'vat_eori_tax_no' => null, 'contact_person' => 'Contact', 'email' => 'b@example.test', 'phone' => null,
            'notify_party' => null, 'port_of_discharge_text' => 'Port', 'country_of_destination' => 'Country',
            'incoterm_confirmed' => 'FOB', 'container_type_text' => null,
            'payment_terms_confirmation' => 'Confirmed', 'quotation_acceptance_reference' => 'REF',
            'coo_type' => 'TBC', 'buyer_po_ref' => null, 'changes_from_quotation' => null,
            'special_document_requirements' => null,
        ], '127.0.0.1');

        // Now status = 'pending_review' — the SAME still-unexpired token
        // must stop working until staff either reject it (back to
        // resubmittable) or apply it.
        self::assertNull(PiIntakeRepository::findValidByToken($rawToken), 'a submission awaiting staff review must not be re-editable via the same link');
    }

    public function testPiIntakeLinkRejectsTheRealTokenOnceApplied(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $rawToken = PiIntakeRepository::createLink($orderId, null);
        $submission = PiIntakeRepository::findValidByToken($rawToken);
        $userId = $this->createTestUser('Export Executive');
        PiIntakeRepository::markApplied((int) $submission['id'], $userId);

        self::assertNull(PiIntakeRepository::findValidByToken($rawToken), 'an already-applied submission must never be reachable via its old link again');
    }

    private function roleIdFor(string $roleName): int
    {
        $pdo = \App\Config\Database::connection();
        $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :name');
        $stmt->execute(['name' => $roleName]);
        return (int) $stmt->fetchColumn();
    }
}
