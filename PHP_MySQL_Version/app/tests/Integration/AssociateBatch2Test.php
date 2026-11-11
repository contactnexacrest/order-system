<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AssociateAdminController;
use App\Controllers\AssociatePortalController;
use App\Controllers\AssociateRegistrationController;
use App\Helpers\Flash;
use App\Repositories\AssociateDocumentRepository;
use App\Repositories\AssociateRegistrationRepository;
use App\Repositories\AssociateRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociateCommission;
use App\Services\AssociateIntakeService;
use App\Services\AssociateKyc;
use App\Services\PermissionService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decisions 4 Nov 2026 (Batch 2):
 * D-251 (A4) document number / reference per kind, Aadhaar last 4 digits only;
 * D-252 (A3) the KYC checklist, accept / reject, no commission paid until complete;
 * D-253 (A1) associate self-registration through a one-use link;
 * D-254 (A5) the associate changes their code; old codes keep working.
 * Node twin: associateBatch2.test.js.
 */
final class AssociateBatch2Test extends DbTestCase
{
    private int $admin;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        $_SESSION = [];
        $this->tag = strtoupper(bin2hex(random_bytes(3)));
        $this->admin = $this->createTestUser('Admin');
    }

    private function actAs(int $userId): void
    {
        $_SESSION = ['_auth_user_id' => $userId];
        PermissionService::resetCache();
    }

    private function asAssociate(int $associateId): void
    {
        $_SESSION = ['_associate_portal_id' => $associateId];
    }

    private function flash(): array
    {
        return Flash::pull()[0] ?? ['type' => '', 'message' => ''];
    }

    private function render(callable $call): string
    {
        ob_start();
        try {
            $call();
        } finally {
            $out = (string) ob_get_clean();
        }
        return $out;
    }

    private function associate(array $fields = []): array
    {
        $id = AssociateRepository::create($fields + ['name' => 'B2 Assoc ' . $this->tag . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'b2-' . bin2hex(random_bytes(4)) . '@assoc.test', 'status' => 'active'], $this->admin);
        AssociateCodeService::issue($id, 'K' . $this->tag);
        return AssociateRepository::find($id);
    }

    private function doc(int $associateId, string $kind, ?string $reference = null, string $status = 'received'): int
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO file_store (file_origin, server_path, uuid_filename, original_filename, file_size_bytes, mime_type) VALUES ('RECEIVED', '/tmp/none.pdf', :s, 'kyc.pdf', 10, 'application/pdf')")
            ->execute(['s' => 'kyc-' . bin2hex(random_bytes(6)) . '.pdf']);
        $docId = AssociateDocumentRepository::add($associateId, $kind, (int) $pdo->lastInsertId(), null, $reference, true, null);
        if ($status !== 'received') {
            $pdo->prepare('UPDATE associate_documents SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $docId]);
        }
        return $docId;
    }

    public function testReferencesAreCheckedPerKindAndAadhaarKeepsOnlyTheLastFourDigits(): void
    {
        self::assertSame(['ABCDE1234F', null], AssociateKyc::normaliseReference('pan', 'abcde1234f'));
        self::assertNotNull(AssociateKyc::normaliseReference('pan', 'ABC123')[1]);
        self::assertSame(['XXXX XXXX 1234', null], AssociateKyc::normaliseReference('aadhaar', '1234'));
        self::assertSame(['XXXX XXXX 1234', null], AssociateKyc::normaliseReference('aadhaar', 'XXXX XXXX 1234'));
        [$v, $e] = AssociateKyc::normaliseReference('aadhaar', '2345 6789 1234');
        self::assertNull($v, 'a full Aadhaar number is never stored');
        self::assertStringContainsString('only the last 4 digits', (string) $e);
        self::assertNotNull(AssociateKyc::normaliseReference('aadhaar', '12a4')[1]);
        self::assertSame(['29ABCDE1234F1Z5', null], AssociateKyc::normaliseReference('gst', '29abcde1234f1z5'));
        self::assertSame(['Z1234567', null], AssociateKyc::normaliseReference('identity', 'z1234567'));
        self::assertSame([null, null], AssociateKyc::normaliseReference('bank_proof', '  '));
        self::assertNotNull(AssociateKyc::normaliseReference('other', str_repeat('x', 51))[1]);
        self::assertSame('Aadhaar card', AssociateDocumentRepository::KINDS['aadhaar']);
        self::assertContains('aadhaar', AssociateDocumentRepository::ASSOCIATE_KINDS);

        // The portal refuses a full Aadhaar number before anything is stored.
        $a = $this->associate();
        $this->asAssociate((int) $a['id']);
        $_POST = ['kind' => 'aadhaar', 'reference' => '234567891234'];
        (new AssociatePortalController())->uploadDocument([]);
        self::assertStringContainsString('only the last 4 digits', $this->flash()['message']);
        self::assertSame([], AssociateDocumentRepository::forAssociate((int) $a['id']));
        // The page offers Aadhaar with its rule for the browser check.
        $html = $this->render(fn () => (new AssociatePortalController())->profile([]));
        self::assertStringContainsString('data-pattern="[0-9]{4}"', $html);
        self::assertStringContainsString('>Aadhaar card</option>', $html);
        self::assertStringContainsString('My documents (identity and bank checks)', $html);
    }

    public function testKycChecklistReviewAndPayoutGate(): void
    {
        $a = $this->associate(['gstin' => '29ABCDE1234F1Z5']);
        $labels = array_map(static fn (array $i): string => $i['label'], AssociateKyc::checklist($a));
        // D-290 (11 Nov 2026): ID proof and address proof are separate required items in India and abroad.
        self::assertSame(['PAN card', 'ID proof (Aadhaar card, passport or other ID document)', 'Address proof', 'Bank proof (cancelled cheque or bank statement)', 'Signed agreement', 'GST certificate'], $labels);
        $abroad = $this->associate(['based_in' => 'abroad', 'currency_code' => 'USD']);
        self::assertSame(['ID proof (passport, national ID card, residence permit or driving licence)', 'Address proof', 'Bank proof (cancelled cheque or bank statement)', 'Signed agreement'], array_map(static fn (array $i): string => $i['label'], AssociateKyc::checklist($abroad)));

        $pan = $this->doc((int) $a['id'], 'pan', 'ABCDE1234F');
        $aad = $this->doc((int) $a['id'], 'aadhaar', 'XXXX XXXX 1234');
        $bank = $this->doc((int) $a['id'], 'bank_proof');
        $agr = $this->doc((int) $a['id'], 'agreement');
        $gst = $this->doc((int) $a['id'], 'gst', '29ABCDE1234F1Z5');
        self::assertSame(['pending'], array_values(array_unique(array_map(static fn (array $i): string => $i['status'], AssociateKyc::checklist($a)))));

        // A reject needs a reason; the associate sees it.
        $this->actAs($this->admin);
        $_POST = ['decision' => 'reject', 'reason' => 'blurry'];
        (new AssociateAdminController())->reviewDocument(['id' => (string) $a['id'], 'docId' => (string) $bank]);
        self::assertStringContainsString('at least 10 characters', $this->flash()['message']);
        $_POST = ['decision' => 'reject', 'reason' => 'The cheque photo is blurry'];
        (new AssociateAdminController())->reviewDocument(['id' => (string) $a['id'], 'docId' => (string) $bank]);
        $items = array_column(AssociateKyc::checklist($a), null, 'key');
        self::assertSame('rejected', $items['bank']['status']);
        self::assertSame('The cheque photo is blurry', $items['bank']['doc']['review_reason']);
        $event = Database::connection()->query("SELECT message FROM associate_events WHERE associate_id = {$a['id']} AND event = 'document_rejected'")->fetchColumn();
        self::assertStringContainsString('The cheque photo is blurry', (string) $event);
        self::assertSame('ASSOCIATE_DOCUMENT_REJECTED', Database::connection()->query("SELECT action_type FROM audit_log WHERE entity_type = 'associate_documents' AND entity_id = {$bank} ORDER BY id DESC LIMIT 1")->fetchColumn());

        // The payout gate names what is still missing.
        $payout = ['payee_kind' => 'associate', 'associate_id' => $a['id'], 'order_id' => 0, 'due_on' => '2020-01-01', 'amount_inr' => 100];
        $block = (string) AssociateCommission::payBlockReason($payout, date('Y-m-d'));
        self::assertStringContainsString("KYC is not complete", $block);
        self::assertStringContainsString('Bank proof', $block);

        foreach ([$pan, $aad, $agr, $gst] as $d) {
            $_POST = ['decision' => 'accept', 'reason' => ''];
            (new AssociateAdminController())->reviewDocument(['id' => (string) $a['id'], 'docId' => (string) $d]);
            self::assertSame('success', $this->flash()['type']);
        }
        $bank2 = $this->doc((int) $a['id'], 'bank_proof');
        self::assertSame('pending', array_column(AssociateKyc::checklist($a), null, 'key')['bank']['status'], 'a re-upload is pending again');
        $_POST = ['decision' => 'accept'];
        (new AssociateAdminController())->reviewDocument(['id' => (string) $a['id'], 'docId' => (string) $bank2]);
        self::assertSame([], AssociateKyc::missing($a));
        self::assertNull(AssociateCommission::payBlockReason($payout, date('Y-m-d')), 'KYC complete — the payout may be paid');

        // Staff page shows the checklist and the accept / reject form.
        $html = $this->render(fn () => (new AssociateAdminController())->show(['id' => (string) $a['id']]));
        self::assertStringContainsString('KYC complete — commission can be paid.', $html);
        self::assertStringContainsString('/documents/' . $bank2 . '/review', $html);
    }

    public function testSelfRegistrationThroughAOneUseLink(): void
    {
        $this->actAs($this->admin);
        (new AssociateRegistrationController())->createLink([]);
        $url = (string) ($_SESSION['_associate_registration_link'] ?? '');
        self::assertMatchesRegularExpression('#/associate-register/[0-9a-f]{48}$#', $url);
        $token = substr($url, strrpos($url, '/') + 1);
        self::assertSame(7, AssociateRegistrationRepository::linkDays());

        // Public form; a wrong token shows the friendly page (no error code).
        $_SESSION = [];
        self::assertStringContainsString('This link is no longer open', $this->render(fn () => (new AssociateRegistrationController())->show(['token' => 'nope' . $this->tag])));
        self::assertStringContainsString('Register as a NexaCrest associate', $this->render(fn () => (new AssociateRegistrationController())->show(['token' => $token])));

        // Validation: every field, consent.
        $_POST = ['name' => 'R', 'email' => 'bad', 'phone' => 'x', 'country' => '', 'based_in' => 'mars', 'city' => ''];
        $html = $this->render(fn () => (new AssociateRegistrationController())->submit(['token' => $token]));
        foreach (['Enter your full name', 'valid e-mail', 'phone number', 'Enter your country', 'India or abroad', 'Enter your city', 'Tick the box'] as $msg) {
            self::assertStringContainsString($msg, $html);
        }
        self::assertNotNull(AssociateRegistrationRepository::usableLink($token), 'a refused form does not use the link');

        $email = 'reg-' . strtolower($this->tag) . '@assoc.test';
        $_POST = ['name' => 'Associate ' . $this->tag, 'email' => $email, 'phone' => '+91-0000000000', 'country' => 'India', 'based_in' => 'india', 'city' => 'Bengaluru', 'consent' => '1'];
        self::assertStringContainsString('Thank you', $this->render(fn () => (new AssociateRegistrationController())->submit(['token' => $token])));
        self::assertNull(AssociateRegistrationRepository::usableLink($token), 'one use');
        self::assertStringContainsString('This link is no longer open', $this->render(fn () => (new AssociateRegistrationController())->submit(['token' => $token])));
        $reg = Database::connection()->query("SELECT * FROM associate_registrations WHERE email = '{$email}'")->fetch();
        self::assertSame('pending', $reg['status']);

        // Staff review queue; accept creates the associate (Invited) and sends the portal login link.
        $this->actAs($this->admin);
        $list = $this->render(fn () => (new AssociateRegistrationController())->index([]));
        self::assertStringContainsString('Associate ' . $this->tag, $list);
        (new AssociateRegistrationController())->accept(['id' => (string) $reg['id']]);
        $a = AssociateRepository::findByEmail($email);
        self::assertNotNull($a);
        self::assertSame(['invited', 'india', 'INR', 'Bengaluru', 1], [$a['status'], $a['based_in'], $a['currency_code'], $a['city'], (int) $a['portal_enabled']]);
        self::assertNotEmpty($a['personal_code']);
        self::assertSame('accepted', Database::connection()->query("SELECT status FROM associate_registrations WHERE id = {$reg['id']}")->fetchColumn());

        // Reject needs a reason; an expired link shows the friendly page.
        (new AssociateRegistrationController())->createLink([]);
        $t2 = substr((string) $_SESSION['_associate_registration_link'], -48);
        $_SESSION = [];
        $_POST = ['name' => 'Second ' . $this->tag, 'email' => 'reg2-' . strtolower($this->tag) . '@assoc.test', 'phone' => '+44 20 7946 0000', 'country' => 'UK', 'based_in' => 'abroad', 'city' => 'London', 'consent' => '1'];
        $this->render(fn () => (new AssociateRegistrationController())->submit(['token' => $t2]));
        $reg2 = (int) Database::connection()->query("SELECT id FROM associate_registrations WHERE name = 'Second {$this->tag}'")->fetchColumn();
        $this->actAs($this->admin);
        $_POST = ['reason' => 'no'];
        (new AssociateRegistrationController())->reject(['id' => (string) $reg2]);
        self::assertStringContainsString('10 to 300 characters', $this->flash()['message']);
        $_POST = ['reason' => 'We do not work with associates in this region yet'];
        (new AssociateRegistrationController())->reject(['id' => (string) $reg2]);
        self::assertSame('rejected', Database::connection()->query("SELECT status FROM associate_registrations WHERE id = {$reg2}")->fetchColumn());
        (new AssociateRegistrationController())->createLink([]);
        $t3 = substr((string) $_SESSION['_associate_registration_link'], -48);
        Database::connection()->exec("UPDATE associate_registration_links SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE token_hash = '" . hash('sha256', $t3) . "'");
        self::assertStringContainsString('This link is no longer open', $this->render(fn () => (new AssociateRegistrationController())->show(['token' => $t3])));
    }

    public function testTheAssociateChangesTheirCodeAndOldCodesKeepWorking(): void
    {
        $a = $this->associate();
        $first = (string) $a['personal_code'];
        $this->asAssociate((int) $a['id']);

        $_POST = ['code' => 'NXA-WHATEVER-AA'];
        (new AssociatePortalController())->chooseCode([]);
        self::assertStringContainsString('Show another ending', $this->flash()['message'], 'nothing switches without a proposal');

        $_POST = ['readable' => 'Demo Exim'];
        (new AssociatePortalController())->proposeCode([]);
        $p1 = (string) $_SESSION['associate_code_proposal']['code'];
        self::assertMatchesRegularExpression('/^NXA-DEMOEXIM-..$/', $p1);
        self::assertTrue(AssociateCodeService::isValid($p1));
        $html = $this->render(fn () => (new AssociatePortalController())->clients([]));
        self::assertStringContainsString($p1, $html);
        self::assertStringContainsString('Use this code', $html);
        self::assertSame($first, AssociateRepository::find((int) $a['id'])['personal_code'], 'a proposal changes nothing');

        $_POST = ['code' => $p1];
        (new AssociatePortalController())->chooseCode([]);
        self::assertStringContainsString("Your personal code is now {$p1}", $this->flash()['message']);
        self::assertSame($p1, AssociateRepository::find((int) $a['id'])['personal_code']);

        // Both codes bring the buyer to the associate.
        self::assertSame((int) $a['id'], (int) AssociateIntakeService::introducer($first, null)['associate']['id']);
        self::assertSame((int) $a['id'], (int) AssociateIntakeService::introducer($p1, null)['associate']['id']);
        $codes = AssociateCodeService::codes((int) $a['id']);
        self::assertCount(2, $codes);
        $html = $this->render(fn () => (new AssociatePortalController())->clients([]));
        self::assertStringContainsString('Still accepted (old links)', $html);
        self::assertStringContainsString('Current', $html);

        // The yearly limit (setting).
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '1' WHERE setting_key = 'associate_code_changes_per_year'");
        $_POST = ['readable' => 'Again'];
        (new AssociatePortalController())->proposeCode([]);
        $_POST = ['code' => (string) $_SESSION['associate_code_proposal']['code']];
        (new AssociatePortalController())->chooseCode([]);
        self::assertStringContainsString('1 times a year', $this->flash()['message']);
        Database::connection()->exec("UPDATE incentive_settings SET setting_value = '3' WHERE setting_key = 'associate_code_changes_per_year'");

        // Came via: the code the buyer used is kept.
        $intro = AssociateIntakeService::introducer($first, null);
        self::assertSame(AssociateCodeService::normalise($first), $intro['code']);
        $invId = \App\Repositories\AssociateInvitationRepository::createForCode((int) $a['id'], 'Came Via Buyer', 'NL', $intro['code']);
        $clientId = $this->createTestClient();
        \App\Services\AssociateService::linkClient($clientId, (int) $a['id'], 'code', $invId, date('Y-m-d'), $this->admin);
        $row = array_values(array_filter(\App\Repositories\AssociatePortalRepository::clients((int) $a['id']), static fn (array $c): bool => (int) $c['id'] === $clientId))[0];
        self::assertSame('Code ' . AssociateCodeService::normalise($first), $row['came_via']);

        // Staff may block an old code (e.g. a leak), with a reason.
        $this->actAs($this->admin);
        $_POST = ['readable' => 'Leak', 'block_old' => '1', 'block_reason' => 'short'];
        (new AssociateAdminController())->setCode(['id' => (string) $a['id']]);
        self::assertStringContainsString('at least 10 characters', $this->flash()['message']);
        $_POST = ['readable' => 'Leak', 'block_old' => '1', 'block_reason' => 'The code was posted on a public forum'];
        (new AssociateAdminController())->setCode(['id' => (string) $a['id']]);
        self::assertNull(AssociateIntakeService::introducer($p1, null)['associate'], 'the blocked code no longer applies');
        self::assertSame((int) $a['id'], (int) AssociateIntakeService::introducer($first, null)['associate']['id'], 'older codes not blocked keep working');
    }
}
