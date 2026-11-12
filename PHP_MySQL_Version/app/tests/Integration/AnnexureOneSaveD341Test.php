<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Config\Database;
use App\Controllers\AnnexureController;
use App\Controllers\OrderController;
use App\Repositories\AuditLogRepository;
use App\Repositories\OrderAnnexureRepository;
use App\Repositories\OrderAnnexureTermsRepository;
use App\Repositories\OrderRepository;
use App\Services\AnnexureChoice;
use App\Tests\Support\DbTestCase;

/**
 * Owner decision D-341 (12 Nov 2026, "will go with recommendation" + "one save for
 * the whole page"): the order page's Step 1 annexure buttons, the "generate the new
 * revision" banner, Annexure B from the Commercial Invoice step on, the empty
 * Annexure B start, the CI step's "Annexure B not made" note, and one "Save all
 * changes" for the whole annexure page. Twin of Node
 * tests/integration/annexureOneSaveD341.test.js — same wording.
 */
final class AnnexureOneSaveD341Test extends DbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_FILES = [];
        $_GET = [];
        $_SESSION = [];
    }

    private function login(): int
    {
        $userId = $this->createTestUser('Admin');
        $_SESSION['_auth_user_id'] = $userId;
        return $userId;
    }

    private static function entry(int $orderId, string $which, string $name): int
    {
        return OrderAnnexureRepository::createProduct($orderId, [
            'name' => $name, 'description' => 'Desc ' . $name, 'dimensions' => '10 x 10 cm', 'finish' => 'Polished', 'components' => '', 'technical_notes' => '',
        ], $which);
    }

    private function annexurePage(int $orderId, array $get = []): string
    {
        $_GET = $get;
        ob_start();
        (new AnnexureController())->index(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    private function orderPage(int $orderId): string
    {
        ob_start();
        (new OrderController())->show(['id' => (string) $orderId]);
        return (string) ob_get_clean();
    }

    /** Stage 8 (the Commercial Invoice step) open. */
    private static function reachCiStage(int $orderId): void
    {
        Database::connection()->prepare(
            "UPDATE order_stages os JOIN stages_master sm ON sm.id = os.stage_id SET os.status = 'in_progress' WHERE os.order_id = :o AND sm.stage_number = 8"
        )->execute(['o' => $orderId]);
    }

    private static function flash(): array
    {
        return $_SESSION['_flash'][0] ?? [];
    }

    // ---- 1. Order page, Step 1 ----------------------------------------------------

    public function testStep1ShowsEditButtonAndGenerateLabelThenRegenerate(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        OrderRepository::setIncludeAnnexureA($orderId, true);
        $this->login();

        $html = $this->orderPage($orderId);
        self::assertStringContainsString('Edit Annexure A &rsaquo;</a>', $html);
        self::assertStringNotContainsString('Manage Annexure A product entries', $html);
        self::assertStringContainsString('>Generate Annexure A</button>', $html);

        $this->createTestDocument($orderId, 'ANNEXA', 'draft');
        $html = $this->orderPage($orderId);
        self::assertStringContainsString('>Regenerate Annexure A (new revision)</button>', $html);
    }

    // ---- 2. The "generate the new revision" banner -------------------------------

    public function testBannerOnlyWhenAnnexureAChangedAfterTheApprovedRevision(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $userId = $this->login();
        $entryId = self::entry($orderId, 'A', 'Slab');
        $docId = $this->createTestDocument($orderId, 'ANNEXA', 'approved');
        Database::connection()->exec("UPDATE documents SET revision_number = 2, generated_at = NOW() + INTERVAL 1 HOUR WHERE id = {$docId}");

        // Generated after the last change: no banner.
        AuditLogRepository::log($userId, 'ANNEXURE_PRODUCT_UPDATED', 'order_annexure_products', $entryId, 'name', null, 'Slab');
        self::assertStringNotContainsString('Generate the new revision', $this->annexurePage($orderId));

        // Changed after the approved revision: the banner and its button.
        Database::connection()->exec("UPDATE documents SET generated_at = NOW() - INTERVAL 1 HOUR WHERE id = {$docId}");
        $html = $this->annexurePage($orderId);
        self::assertStringContainsString('You changed Annexure A after Rev.2 was approved — generate the new revision, then approve and send it.', $html);
        self::assertStringContainsString('action="/orders/' . $orderId . '/documents/generate"', $html);
        self::assertStringContainsString('name="document_type" value="ANNEXA"', $html);
        self::assertStringContainsString('Generate the new revision &rsaquo;</button>', $html);

        // A draft revision: no banner.
        Database::connection()->exec("UPDATE documents SET status = 'draft' WHERE id = {$docId}");
        self::assertStringNotContainsString('Generate the new revision', $this->annexurePage($orderId));
    }

    public function testRemovedEntryCountsAsAChangeButAnnexureBDoesNot(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->login();
        $entryId = self::entry($orderId, 'A', 'Slab');
        $docId = $this->createTestDocument($orderId, 'ANNEXA', 'sent');
        Database::connection()->exec("UPDATE documents SET generated_at = NOW() - INTERVAL 1 HOUR WHERE id = {$docId}");
        self::assertNull(AnnexureChoice::staleApprovedAnnexA($orderId));

        // A change to Annexure B is not a change to Annexure A.
        self::reachCiStage($orderId);
        $bEntry = self::entry($orderId, 'B', 'Shipped slab');
        $_POST = ['which' => 'B', 'mode' => 'SPEC', 'entries' => [$bEntry => ['name' => 'Shipped slab 2']]];
        (new AnnexureController())->saveAll(['id' => (string) $orderId]);
        self::assertNull(AnnexureChoice::staleApprovedAnnexA($orderId));

        (new AnnexureController())->deleteProduct(['id' => (string) $orderId, 'productId' => (string) $entryId]);
        self::assertNotNull(AnnexureChoice::staleApprovedAnnexA($orderId));
    }

    // ---- 3. Annexure B only from the Commercial Invoice step ---------------------

    public function testAnnexureBHiddenBeforeTheCiStepVisibleAfterOrWithContent(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->login();

        $html = $this->annexurePage($orderId);
        self::assertStringNotContainsString('Annexure B (as shipped) &rsaquo;', $html);
        self::assertStringContainsString('Annexure B can be made at the Commercial Invoice step.', $html);

        // Direct URL: the note and a link back, nothing to edit.
        $html = $this->annexurePage($orderId, ['which' => 'B']);
        self::assertStringContainsString('Annexure B can be made at the Commercial Invoice step.', $html);
        self::assertStringContainsString('&lsaquo; Back to Annexure A</a>', $html);
        self::assertStringNotContainsString('Save all changes', $html);
        self::assertStringNotContainsString('Start Annexure B from a copy of Annexure A', $html);

        // Copy / save to B refused before then.
        $_POST = [];
        (new AnnexureController())->copyFromA(['id' => (string) $orderId]);
        self::assertSame('error', self::flash()['type'] ?? null);
        self::assertSame(0, OrderAnnexureRepository::countFor($orderId, 'B'));

        // B with content (made earlier): always shown.
        $_SESSION['_flash'] = [];
        self::entry($orderId, 'B', 'Made earlier');
        self::assertStringContainsString('Annexure B (as shipped) &rsaquo;', $this->annexurePage($orderId));
        self::assertStringContainsString('Save all changes', $this->annexurePage($orderId, ['which' => 'B']));

        // At the Commercial Invoice step: shown.
        $other = $this->createTestOrder($this->createTestClient());
        self::reachCiStage($other);
        self::assertStringContainsString('Annexure B (as shipped) &rsaquo;', $this->annexurePage($other));
        self::assertStringNotContainsString('Annexure B can be made at the Commercial Invoice step.', $this->annexurePage($other));
    }

    // ---- 4. The empty Annexure B start --------------------------------------------

    public function testEmptyAnnexureBOffersTheCopyFirstThenStartEmpty(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::reachCiStage($orderId);
        $this->login();
        self::entry($orderId, 'A', 'Quoted slab');

        $html = $this->annexurePage($orderId, ['which' => 'B']);
        self::assertStringContainsString('>Start Annexure B from a copy of Annexure A</button>', $html);
        self::assertStringContainsString('action="/orders/' . $orderId . '/annexure/copy-from-a"', $html);
        self::assertStringNotContainsString('data-confirm="Replace Annexure B', $html, 'no confirm while B is empty');
        self::assertStringContainsString('href="/orders/' . $orderId . '/annexure?which=B&amp;start=empty">Start empty instead</a>', $html);
        self::assertStringNotContainsString('Save all changes', $html);

        $html = $this->annexurePage($orderId, ['which' => 'B', 'start' => 'empty']);
        self::assertStringContainsString('Save all changes', $html);
        self::assertStringContainsString('Copy everything from Annexure A', $html);

        (new AnnexureController())->copyFromA(['id' => (string) $orderId]);
        self::assertSame(1, OrderAnnexureRepository::countFor($orderId, 'B'));
        $html = $this->annexurePage($orderId, ['which' => 'B']);
        self::assertStringNotContainsString('Start Annexure B from a copy of Annexure A', $html);
        self::assertStringContainsString('data-confirm="Replace Annexure B with a copy of Annexure A?"', $html);
    }

    // ---- 5. The CI step note -------------------------------------------------------

    public function testCiStepSaysAnnexureBNotMadeWithTheCopyLink(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        self::reachCiStage($orderId);
        $this->login();

        // Owner, 12 Nov 2026: with no Annexure A content nothing is printed — say so, and no copy link.
        $html = $this->orderPage($orderId);
        self::assertStringContainsString('Annexure B not made — no annexure will be printed.', $html);
        self::assertStringNotContainsString('Make Annexure B (copy of A)', $html);

        OrderRepository::setIncludeAnnexureA($orderId, true);
        self::entry($orderId, 'A', 'Quoted slab');
        $html = $this->orderPage($orderId);
        self::assertStringContainsString('Annexure B not made — Annexure A will be printed.', $html);
        self::assertStringContainsString('form="ci-annexure-b-copy" class="btn-link">Make Annexure B (copy of A) &rsaquo;</button>', $html);
        self::assertMatchesRegularExpression('#<form method="post" action="/orders/' . $orderId . '/annexure/copy-from-a" id="ci-annexure-b-copy"#', $html);

        self::entry($orderId, 'B', 'Shipped slab');
        self::assertStringNotContainsString('Annexure B not made', $this->orderPage($orderId));
    }

    // ---- 6. One save for the whole page ---------------------------------------------

    public function testSaveAllSavesModeEntriesAndTermsInOneGo(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->login();
        $one = self::entry($orderId, 'A', 'Slab one');
        $two = self::entry($orderId, 'A', 'Slab two');

        $_POST = ['which' => 'A', 'mode' => 'BOTH', 'content_html' => '<p>Packed in <b>wooden crates</b></p><script>x</script>', 'entries' => [
            $one => ['name' => 'Slab one (edited)', 'dimensions' => '200 x 100 cm', 'finish' => 'Honed', 'description' => 'New desc', 'components' => '1. Top', 'technical_notes' => 'Keep dry'],
            $two => ['name' => 'Slab two', 'dimensions' => '50 x 50 cm', 'finish' => 'Polished', 'description' => 'Desc Slab two', 'components' => '', 'technical_notes' => 'Fragile'],
        ]];
        (new AnnexureController())->saveAll(['id' => (string) $orderId]);

        self::assertSame('success', self::flash()['type'] ?? null);
        self::assertSame('All changes to Annexure A saved.', self::flash()['message'] ?? null);
        self::assertSame('BOTH', OrderRepository::find($orderId)['annexure_mode']);
        $saved = OrderAnnexureRepository::findProduct($one);
        self::assertSame(['Slab one (edited)', '200 x 100 cm', 'Honed', 'New desc', '1. Top', 'Keep dry'], [$saved['name'], $saved['dimensions'], $saved['finish'], $saved['description'], $saved['components'], $saved['technical_notes']]);
        self::assertSame('50 x 50 cm', OrderAnnexureRepository::findProduct($two)['dimensions']);
        $terms = (string) OrderAnnexureTermsRepository::find($orderId, 'A')['content_html'];
        self::assertStringContainsString('<b>wooden crates</b>', $terms);
        self::assertStringNotContainsString('<script', $terms);

        // One audit row per changed entry, the terms and the mode.
        $count = static fn (string $action): int => (int) Database::connection()->query("SELECT COUNT(*) FROM audit_log WHERE action_type = '{$action}' AND ((entity_type = 'order' AND entity_id = {$orderId}) OR (entity_type = 'order_annexure_products' AND entity_id IN ({$one}, {$two})))")->fetchColumn();
        self::assertSame(2, $count('ANNEXURE_PRODUCT_UPDATED'));
        self::assertSame(1, $count('ANNEXURE_TERMS_UPDATED'));
        self::assertSame(1, $count('ANNEXURE_MODE_UPDATED'));
    }

    public function testSaveAllWithAMissingNameSavesNothingAndShowsTheMessageBesideTheField(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->login();
        $one = self::entry($orderId, 'A', 'Slab one');
        $two = self::entry($orderId, 'A', 'Slab two');

        $_POST = ['which' => 'A', 'mode' => 'TERMS', 'content_html' => '<p>New terms</p>', 'entries' => [
            $one => ['name' => 'Slab one (edited)', 'dimensions' => '1 x 1 cm'],
            $two => ['name' => '   ', 'dimensions' => '2 x 2 cm'],
        ]];
        ob_start();
        (new AnnexureController())->saveAll(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();

        self::assertSame('Slab one', OrderAnnexureRepository::findProduct($one)['name'], 'nothing saved');
        self::assertSame('SPEC', OrderRepository::find($orderId)['annexure_mode']);
        self::assertNull(OrderAnnexureTermsRepository::find($orderId, 'A'));
        self::assertStringContainsString('Nothing was saved — correct the fields marked below', $html);
        self::assertMatchesRegularExpression('#name="entries\[' . $two . '\]\[name\]"[^>]*field-invalid[^>]*><small class="reason-error form-field-error">Product name is required\.</small>#', $html);
        self::assertStringContainsString('value="Slab one (edited)"', $html, 'what was typed is kept');
        self::assertStringContainsString('<p>New terms</p>', $html);
    }

    public function testSaveAllRefusesAWrongImageAndRollsBackWhenAnImageCannotBeStored(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->login();
        $one = self::entry($orderId, 'A', 'Slab one');
        $tmp = tempnam(sys_get_temp_dir(), 'd341');
        file_put_contents($tmp, 'not really an image');

        // Wrong type: refused beside the entry's image field, nothing saved.
        $_POST = ['which' => 'A', 'mode' => 'SPEC', 'entries' => [$one => ['name' => 'Renamed']]];
        $_FILES = ['images' => ['name' => [$one => 'notes.txt'], 'type' => [$one => 'text/plain'], 'tmp_name' => [$one => $tmp], 'error' => [$one => UPLOAD_ERR_OK], 'size' => [$one => 19]]];
        ob_start();
        (new AnnexureController())->saveAll(['id' => (string) $orderId]);
        $html = (string) ob_get_clean();
        self::assertStringContainsString('File type .txt is not allowed for this upload (allowed: jpg,jpeg,png,webp).', $html);
        self::assertSame('Slab one', OrderAnnexureRepository::findProduct($one)['name']);

        // A good image that cannot be stored (no real HTTP upload here): the whole save is rolled back.
        $_SESSION['_flash'] = [];
        $_FILES = ['images' => ['name' => [$one => 'slab.jpg'], 'type' => [$one => 'image/jpeg'], 'tmp_name' => [$one => $tmp], 'error' => [$one => UPLOAD_ERR_OK], 'size' => [$one => 19]]];
        (new AnnexureController())->saveAll(['id' => (string) $orderId]);
        self::assertSame('error', self::flash()['type'] ?? null);
        self::assertStringStartsWith('Nothing was saved: ', (string) (self::flash()['message'] ?? ''));
        self::assertSame('Slab one', OrderAnnexureRepository::findProduct($one)['name'], 'the entry change was rolled back with the image');
        self::assertSame([], OrderAnnexureRepository::forOrder($orderId, 'A')[0]['images']);
        @unlink($tmp);
    }

    public function testPageHasOneGuardedFormWithSeparateRemoveForms(): void
    {
        $orderId = $this->createTestOrder($this->createTestClient());
        $this->login();
        $one = self::entry($orderId, 'A', 'Slab one');
        $imageId = OrderAnnexureRepository::addImage($one, $this->createTestFile($orderId));

        $html = $this->annexurePage($orderId);
        self::assertSame(2, substr_count($html, '>Save all changes</button>'), 'top and bottom');
        self::assertStringContainsString('action="/orders/' . $orderId . '/annexure/save-all" enctype="multipart/form-data" id="annexure-save-all" data-unsaved-guard', $html);
        self::assertStringContainsString('name="images[' . $one . ']"', $html);
        self::assertStringNotContainsString('>Upload Image</button>', $html);
        self::assertStringNotContainsString('>Save Additional Terms</button>', $html);
        self::assertStringNotContainsString('>Save</button>', $html);

        // The remove forms are outside the big form, reached through form="…".
        $start = strpos($html, 'id="annexure-save-all"');
        $end = strpos($html, '</form>', $start);
        $big = substr($html, $start, $end - $start);
        self::assertStringNotContainsString('<form', $big, 'no nested form');
        self::assertStringContainsString('form="annexure-entry-remove-' . $one . '"', $big);
        self::assertStringContainsString('form="annexure-image-remove-' . $imageId . '"', $big);
        self::assertStringContainsString('action="/orders/' . $orderId . '/annexure/products/' . $one . '/delete" id="annexure-entry-remove-' . $one . '"', $html);
        self::assertStringContainsString('action="/orders/' . $orderId . '/annexure/images/' . $imageId . '/remove" id="annexure-image-remove-' . $imageId . '"', $html);

        // They still act at once.
        (new AnnexureController())->removeImage(['id' => (string) $orderId, 'imageId' => (string) $imageId]);
        self::assertSame([], OrderAnnexureRepository::forOrder($orderId, 'A')[0]['images']);
        (new AnnexureController())->deleteProduct(['id' => (string) $orderId, 'productId' => (string) $one]);
        self::assertSame(0, OrderAnnexureRepository::countFor($orderId, 'A'));
    }

    public function testAppJsHasTheUnsavedChangesGuard(): void
    {
        $js = (string) file_get_contents(dirname(__DIR__, 3) . '/public_html/assets/js/app.js');
        self::assertStringContainsString("form[data-unsaved-guard]", $js);
        self::assertStringContainsString('You have unsaved changes on this page — leave without saving?', $js);
        self::assertStringContainsString('beforeunload', $js);
    }
}
