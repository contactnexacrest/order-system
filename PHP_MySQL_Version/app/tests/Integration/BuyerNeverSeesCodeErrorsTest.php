<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Repositories\AssociateInvitationRepository;
use App\Repositories\AssociateRepository;
use App\Services\AssociateCodeService;
use App\Services\AssociateIntakeService;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision 4 Nov 2026 (A6): a buyer never sees an error caused by an
 * associate code or link. Whatever is wrong with it, the buyer carries on
 * as a direct buyer, and staff see on the intake review what was given and
 * why it was not applied. Node twin: buyerNeverSeesCodeErrors.test.js.
 */
final class BuyerNeverSeesCodeErrorsTest extends DbTestCase
{
    private int $admin;
    private string $tag;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createTestUser('Admin');
        $this->tag = strtoupper(bin2hex(random_bytes(3)));
    }

    private function associate(array $fields = []): array
    {
        $id = AssociateRepository::create($fields + ['name' => 'A6 Assoc ' . $this->tag . bin2hex(random_bytes(2)), 'based_in' => 'india', 'currency_code' => 'INR',
            'email' => 'a6-' . bin2hex(random_bytes(4)) . '@assoc.test', 'status' => 'active'], $this->admin);
        AssociateCodeService::issue($id, 'Q' . $this->tag);
        return AssociateRepository::find($id);
    }

    /** @return array{associate: ?array, note: ?string, error: ?string} */
    private function intro(?string $code, ?string $token = null): array
    {
        return AssociateIntakeService::introducer($code, $token);
    }

    public function testEveryBadCodeOrLinkIsIgnoredSilentlyWithAReasonForStaff(): void
    {
        $a = $this->associate();
        $cases = [];

        // Mistyped (fails the check character).
        $pc = (string) $a['personal_code'];
        $cases['mistyped'] = [$this->intro(substr($pc, 0, -1) . (substr($pc, -1) === 'A' ? 'B' : 'A')), 'the code is not valid (mistyped?)'];
        // Unknown: a valid-looking code nobody has.
        $cases['unknown'] = [$this->intro(AssociateCodeService::generate('NOBODY' . $this->tag)), 'no associate has this code'];
        // D-254 (owner, 4 Nov 2026): an old code still brings the buyer to the associate…
        AssociateCodeService::issue((int) $a['id'], 'N' . $this->tag);
        $old = $this->intro($pc);
        self::assertSame([(int) $a['id'], null], [(int) $old['associate']['id'], $old['note']], 'an old code keeps working');
        // …unless staff blocked it (e.g. after a leak).
        $mid = (string) AssociateRepository::find((int) $a['id'])['personal_code'];
        AssociateCodeService::issue((int) $a['id'], 'B' . $this->tag, true, 'The code was posted on a public forum');
        $cases['blocked old'] = [$this->intro($mid), "it is an old code of associate {$a['name']} that NexaCrest blocked"];
        // Suspended and ended associates.
        $s = $this->associate(['status' => 'suspended']);
        $cases['suspended'] = [$this->intro((string) $s['personal_code']), 'the associate is suspended'];
        $e = $this->associate(['status' => 'ended']);
        $cases['ended'] = [$this->intro((string) $e['personal_code']), 'the associate is ended'];
        // Links: unknown token, expired link.
        $cases['bad link'] = [$this->intro(null, 'not-a-real-token-' . $this->tag), 'the link is not valid'];
        $inv = AssociateInvitationRepository::createLink((int) $a['id'], 'A6 Link Buyer', 'Netherlands');
        Database::connection()->prepare('UPDATE associate_invitations SET expires_at = NOW() - INTERVAL 1 DAY WHERE id = :id')->execute(['id' => $inv['id']]);
        $cases['expired link'] = [$this->intro(null, $inv['token']), 'the link expired or was already used'];

        foreach ($cases as $name => [$r, $reason]) {
            self::assertNull($r['associate'], "{$name}: not applied");
            self::assertNull($r['error'], "{$name}: the buyer sees no error");
            self::assertStringContainsString("given but not applied: {$reason}.", (string) $r['note'], $name);
        }

        // The current code of an active associate still works, with no note.
        $ok = $this->intro((string) AssociateRepository::find((int) $a['id'])['personal_code']);
        self::assertSame([(int) $a['id'], null, null], [(int) $ok['associate']['id'], $ok['note'], $ok['error']]);
    }

    public function testStaffSeeTheNoteOnTheIntakeReview(): void
    {
        $pdo = Database::connection();
        $pdo->prepare("INSERT INTO client_intake_submissions (company_legal_name, billing_address, contact_person, email, country_of_destination, status, associate_note)
                       VALUES ('A6 Review Buyer', '1 Harbour Road', 'Purchase Head', 'buyer@a6.example', 'Netherlands', 'pending', :n)")
            ->execute(['n' => 'Associate code NXA-OLD-7K given but not applied: no associate has this code.']);
        $s = AssociateIntakeService::submission((int) $pdo->lastInsertId());
        $info = AssociateIntakeService::reviewInfo($s);
        self::assertNull($info['associate']);
        self::assertContains('Associate code NXA-OLD-7K given but not applied: no associate has this code.', $info['flags']);
    }
}
