<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\Database;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanySettingsRepository;

/**
 * Owner decision D-280 (11 Nov 2026, point 3): Admin › E-mail settings.
 *
 * Every e-mail the app sends has a kind (one row on the settings page) and an
 * audience (Staff, Associate, Buyer, Supplier). Each kind × audience is
 *   - MUST: always sent, cannot be switched off (log-in and account access);
 *   - SHOULD: on when installed, can be switched off;
 *   - OPTIONAL: off when installed, can be switched on.
 * The switches live in the email_settings table (MUST rows are never read).
 *
 * "Testing mode — hold e-mails to buyers and suppliers" (company setting
 * email_testing_mode, replaces the old mail_sending_enabled master switch)
 * holds only buyer and supplier e-mails that are not MUST. Staff and associate
 * e-mails, and every MUST e-mail, still go out.
 *
 * The screen never says "e-mailed" when nothing was sent: callers ask
 * holdReason() / notSentReason() for the true wording.
 */
final class EmailPolicy
{
    public const AUDIENCES = ['staff' => 'Staff', 'associate' => 'Associate', 'buyer' => 'Buyer', 'supplier' => 'Supplier'];

    public const GROUPS = [
        'A' => 'Log-in and account access',
        'B' => 'Orders and documents (to the buyer and the supplier)',
        'C' => 'Associate programme',
        'D' => 'Staff — approvals, tasks and alerts',
    ];

    /**
     * kind => [group, label, [audience => tier], access_critical?]
     * Tiers: must | should | optional.
     */
    public const KINDS = [
        // A. Log-in and account access — all MUST.
        'login_code' => ['group' => 'A', 'label' => '6-digit login code', 'audiences' => ['staff' => 'must', 'associate' => 'must', 'buyer' => 'must']],
        'set_password_invite' => ['group' => 'A', 'label' => 'Set your password (portal opened / registration accepted)', 'audiences' => ['associate' => 'must', 'buyer' => 'must'], 'access_critical' => true],
        'forgot_password' => ['group' => 'A', 'label' => 'Forgot password — reset link', 'audiences' => ['staff' => 'must', 'associate' => 'must', 'buyer' => 'must']],
        'bank_details_changed' => ['group' => 'A', 'label' => 'Bank details changed — "if this wasn\'t you, tell us"', 'audiences' => ['associate' => 'must']],
        'backup_code_alert' => ['group' => 'A', 'label' => 'Backup code used — security alert', 'audiences' => ['staff' => 'must']],
        'quote_verification_code' => ['group' => 'A', 'label' => 'Quotation request — e-mail verification code', 'audiences' => ['buyer' => 'must']],

        // B. Orders and documents.
        'buyer_documents' => ['group' => 'B', 'label' => 'Documents: Quotation, Proforma, OC, Commercial Invoice, Debit Note, Packing List … (still need Level-2 e-mail approval)', 'audiences' => ['associate' => 'optional', 'buyer' => 'should']],
        'buyer_links' => ['group' => 'B', 'label' => 'Links to fill in: quotation correction, PI-stage details, acknowledge OC / upload PO, payment details', 'audiences' => ['buyer' => 'should']],
        'buyer_messages' => ['group' => 'B', 'label' => 'Order messages and complaint replies', 'audiences' => ['buyer' => 'should']],
        'buyer_followups' => ['group' => 'B', 'label' => 'Payment follow-up, shipment readiness, BL copy, balance received', 'audiences' => ['buyer' => 'should']],
        'supplier_links' => ['group' => 'B', 'label' => 'Supplier / logistics partner: upload signed PO, confirm company and bank details', 'audiences' => ['supplier' => 'should']],

        // C. Associate programme — all SHOULD.
        'associate_clients' => ['group' => 'C', 'label' => 'A client was accepted / not accepted, moved to or from you, an order created for your client', 'audiences' => ['associate' => 'should']],
        'associate_commission' => ['group' => 'C', 'label' => 'Commission earned, payout held / released / paid, adjustment', 'audiences' => ['associate' => 'should']],
        'associate_kyc_price_message' => ['group' => 'C', 'label' => 'KYC document accepted / rejected, price ready, message from NexaCrest, new bank details not accepted', 'audiences' => ['associate' => 'should']],
        'associate_registration_declined' => ['group' => 'C', 'label' => 'Registration declined', 'audiences' => ['associate' => 'should']],
        'associate_code_status' => ['group' => 'C', 'label' => 'Code / status changed, reminders (protection ending, registration lapsed, agreement ending)', 'audiences' => ['associate' => 'should']],

        // D. Staff — all SHOULD (each person can still turn off their own non-MUST e-mails under My Account).
        'staff_approvals' => ['group' => 'D', 'label' => 'Something waits for my approval (price, payment terms, e-mail, document, bank check, cancellation, amendment …) — one e-mail per approval', 'audiences' => ['staff' => 'should']],
        'staff_counterparty' => ['group' => 'D', 'label' => 'Buyer / supplier / associate did something (paid, uploaded PO, message, registration …) — to the department on the order', 'audiences' => ['staff' => 'should']],
        'staff_compliance' => ['group' => 'D', 'label' => 'Compliance and deadline alerts (LUT, RCMC, LC dates, overdue payments, eBRC, ECGC, TDS …)', 'audiences' => ['staff' => 'should']],
        'staff_zoho' => ['group' => 'D', 'label' => 'Zoho sync failures', 'audiences' => ['staff' => 'should']],
        'staff_daily_summary' => ['group' => 'D', 'label' => 'Daily summary ("your day")', 'audiences' => ['staff' => 'should']],
        'staff_assigned' => ['group' => 'D', 'label' => 'Order assigned to me / I became department owner', 'audiences' => ['staff' => 'should']],
        'staff_other' => ['group' => 'D', 'label' => 'Answers to my own requests (approved, rejected, cancelled) and other notices', 'audiences' => ['staff' => 'should']],
    ];

    /**
     * Staff notification type (notifications.type) => e-mail kind. A type not
     * listed here is 'staff_other'.
     */
    public const NOTIFICATION_KINDS = [
        // approvals (D-280 Q3: one e-mail per approval)
        'payment_terms_approval_needed' => 'staff_approvals', 'order_cancellation_requested' => 'staff_approvals',
        'amendment_pending_md_approval' => 'staff_approvals', 'email_send_pending_approval' => 'staff_approvals',
        'line_price_approval' => 'staff_approvals', 'price_request' => 'staff_approvals', 'associate_bank_change' => 'staff_approvals',
        'document_review_assigned' => 'staff_approvals', 'review_assigned' => 'staff_approvals',
        'short_payment_approval' => 'staff_approvals', // D-310
        // the buyer / supplier / associate did something
        'buyer_oc_acknowledged' => 'staff_counterparty', 'buyer_po_uploaded_by_client' => 'staff_counterparty',
        'client_payment_reported' => 'staff_counterparty', 'supplier_po_ack_uploaded_by_supplier' => 'staff_counterparty',
        'order_comment_from_client' => 'staff_counterparty', 'dispute_raised' => 'staff_counterparty',
        'order_feedback_submitted' => 'staff_counterparty', 'reorder_request_submitted' => 'staff_counterparty',
        'pi_intake_submitted' => 'staff_counterparty', 'client_intake_submitted' => 'staff_counterparty',
        'client_intake_updated' => 'staff_counterparty', 'associate_message' => 'staff_counterparty',
        'associate_document_uploaded' => 'staff_counterparty', 'associate_registration' => 'staff_counterparty',
        'complaint_raised' => 'staff_counterparty', 'inspection_failed' => 'staff_counterparty',
        // compliance and deadlines
        'lut_expiry' => 'staff_compliance', 'lut_escalation' => 'staff_compliance', 'rcmc_expiry' => 'staff_compliance',
        'rcmc_escalation' => 'staff_compliance', 'fdn_overdue' => 'staff_compliance', 'balance_overdue' => 'staff_compliance',
        'dispute_overdue' => 'staff_compliance', 'quotation_expired' => 'staff_compliance', 'pi_expired' => 'staff_compliance',
        'pre_shipment_inspection_reminder' => 'staff_compliance', 'agreement_expiry' => 'staff_compliance',
        'merchant_trade_rbi' => 'staff_compliance', 'ebrc_missing_monthly' => 'staff_compliance',
        'ecgc_declaration_due' => 'staff_compliance', 'ecgc_declaration_overdue' => 'staff_compliance',
        'ecgc_policy_expiring' => 'staff_compliance', 'benefit_claim_deadline' => 'staff_compliance',
        'associate_tds_deposit' => 'staff_compliance', 'associate_tds_26q' => 'staff_compliance',
        'lc_expiry_soon' => 'staff_compliance', 'lc_last_shipment_soon' => 'staff_compliance', 'lc_presentation_soon' => 'staff_compliance',
        // Zoho, digest, assignment
        'zoho_sync_failed' => 'staff_zoho',
        'order_department_owner' => 'staff_assigned', 'order_assigned' => 'staff_assigned',
        // security (MUST)
        'backup_code_used' => 'backup_code_alert',
    ];

    /** Associate notice event => e-mail kind (AssociateService::notify). */
    public const ASSOCIATE_EVENT_KINDS = [
        'bank_changed' => 'bank_details_changed',
        'client_accepted' => 'associate_clients', 'client_not_accepted' => 'associate_clients', 'order_created' => 'associate_clients',
        'direct_contact' => 'associate_clients', 'link_changed' => 'associate_clients',
        'commission_earned' => 'associate_commission', 'payout_held' => 'associate_commission', 'payout_released' => 'associate_commission',
        'payout_paid' => 'associate_commission', 'clawback' => 'associate_commission',
        'document_added' => 'associate_kyc_price_message', 'document_accepted' => 'associate_kyc_price_message',
        'document_rejected' => 'associate_kyc_price_message', 'price_ready' => 'associate_kyc_price_message',
        'message' => 'associate_kyc_price_message', 'bank_rejected' => 'associate_kyc_price_message',
        'code_changed' => 'associate_code_status', 'status_changed' => 'associate_code_status', 'lock_ending' => 'associate_code_status',
        'registration_lapsed' => 'associate_code_status', 'agreement_ending' => 'associate_code_status',
        'portal_invite' => 'set_password_invite',
    ];

    /** Generic buyer e-mail templates (EmailDispatchService, no document) => kind. */
    public const TEMPLATE_KINDS = [
        'payment_followup' => 'buyer_followups', 'shipment_readiness' => 'buyer_followups',
        'bl_copy_sent' => 'buyer_followups', 'balance_receipt_confirmation' => 'buyer_followups',
    ];

    /** @var array<string,bool>|null kind|audience => on */
    private static ?array $switches = null;

    /** @var array<string,string>|null test hook: a fixed set of switches instead of the table */
    public static ?array $testSwitches = null;

    public static function resetCache(): void
    {
        self::$switches = null;
    }

    /** Tests: sets one switch in the table (as the settings page would) and clears the cache. */
    public static function setSwitchForTest(string $kind, string $audience, bool $on): void
    {
        Database::connection()->prepare(
            'INSERT INTO email_settings (email_kind, audience, is_enabled) VALUES (:k, :a, :e) ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled)'
        )->execute(['k' => $kind, 'a' => $audience, 'e' => $on ? 1 : 0]);
        self::resetCache();
    }

    public static function kindForNotification(string $type): string
    {
        return self::NOTIFICATION_KINDS[$type] ?? 'staff_other';
    }

    public static function kindForAssociateEvent(string $event): string
    {
        return self::ASSOCIATE_EVENT_KINDS[$event] ?? 'associate_kyc_price_message';
    }

    public static function tier(string $kind, string $audience): ?string
    {
        return self::KINDS[$kind]['audiences'][$audience] ?? null;
    }

    public static function isMust(string $kind, string $audience): bool
    {
        return self::tier($kind, $audience) === 'must';
    }

    public static function testingMode(): bool
    {
        return CompanySettingsRepository::get('email_testing_mode') === '1';
    }

    /** The switch for one kind × audience: MUST is always on; a missing row falls back to its tier's default. */
    public static function switchOn(string $kind, string $audience): bool
    {
        $tier = self::tier($kind, $audience);
        if ($tier === null) {
            return false;
        }
        if ($tier === 'must') {
            return true;
        }
        if (self::$switches === null) {
            self::$switches = [];
            try {
                foreach (Database::connection()->query('SELECT email_kind, audience, is_enabled FROM email_settings')->fetchAll() as $r) {
                    self::$switches[$r['email_kind'] . '|' . $r['audience']] = (int) $r['is_enabled'] === 1;
                }
            } catch (\Throwable $e) {
                // Table not there yet (a database from before D-280): the tier defaults apply.
            }
        }
        $key = $kind . '|' . $audience;
        return self::$switches[$key] ?? ($tier === 'should');
    }

    /**
     * Why this e-mail would NOT be sent — null when it goes out.
     * The wording is shown to staff as "not e-mailed — <reason>".
     */
    public static function holdReason(string $kind, string $audience): ?string
    {
        if (self::tier($kind, $audience) === null) {
            return 'this e-mail is not set up for ' . strtolower(self::AUDIENCES[$audience] ?? $audience) . 's';
        }
        if (self::isMust($kind, $audience)) {
            return null;
        }
        if (in_array($audience, ['buyer', 'supplier'], true) && self::testingMode()) {
            return 'testing mode holds e-mails to buyers and suppliers';
        }
        if (!self::switchOn($kind, $audience)) {
            return 'this e-mail is switched off in Admin › E-mail settings';
        }
        return null;
    }

    public static function allows(string $kind, string $audience): bool
    {
        return self::holdReason($kind, $audience) === null;
    }

    /** The reason to show after a send that returned false. */
    public static function notSentReason(string $kind, string $audience): string
    {
        return self::holdReason($kind, $audience) ?? 'the e-mail could not be sent';
    }

    /** @return array<string, array<string, array{tier:string, on:bool}>> kind => audience => state, for the settings page */
    public static function matrix(): array
    {
        $out = [];
        foreach (self::KINDS as $kind => $def) {
            foreach ($def['audiences'] as $audience => $tier) {
                $out[$kind][$audience] = ['tier' => $tier, 'on' => self::switchOn($kind, $audience)];
            }
        }
        return $out;
    }

    /**
     * Saves the page: $on = set of "kind|audience" keys ticked. MUST rows are
     * ignored (always on). Returns the list of changes made ("label — audience: on/off").
     *
     * @param array<string,mixed> $on
     * @return list<string>
     */
    public static function save(array $on, bool $testingMode, int $userId, string $reason): array
    {
        $changes = [];
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO email_settings (email_kind, audience, is_enabled, updated_by) VALUES (:k, :a, :e, :u)
             ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_by = VALUES(updated_by)'
        );
        foreach (self::KINDS as $kind => $def) {
            foreach ($def['audiences'] as $audience => $tier) {
                if ($tier === 'must') {
                    continue;
                }
                $new = isset($on[$kind . '|' . $audience]);
                $old = self::switchOn($kind, $audience);
                if ($new !== $old) {
                    $changes[] = $def['label'] . ' — ' . self::AUDIENCES[$audience] . ': ' . ($new ? 'on' : 'off');
                    $stmt->execute(['k' => $kind, 'a' => $audience, 'e' => $new ? 1 : 0, 'u' => $userId]);
                    AuditLogRepository::log($userId, 'EMAIL_SETTING_CHANGED', 'email_settings', null, $kind . '|' . $audience, $old ? '1' : '0', $new ? '1' : '0', $reason);
                }
            }
        }
        if ($testingMode !== self::testingMode()) {
            CompanySettingsRepository::set('email_testing_mode', $testingMode ? '1' : '0', $userId);
            AuditLogRepository::log($userId, 'EMAIL_TESTING_MODE_CHANGED', 'company_settings', null, 'email_testing_mode', $testingMode ? '0' : '1', $testingMode ? '1' : '0', $reason);
            $changes[] = 'Testing mode (hold e-mails to buyers and suppliers): ' . ($testingMode ? 'on' : 'off');
        }
        self::resetCache();
        return $changes;
    }
}
