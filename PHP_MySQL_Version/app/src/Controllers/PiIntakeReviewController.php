<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PiIntakeRepository;
use App\Services\AuthService;
use App\Services\UiTemplateService;

/**
 * Staff review queue for PI-stage intake submissions (schema.sql Section
 * AA) — a separate queue from ClientIntakeReviewController's Quotation-
 * stage one. Accepting is the authoritative correction point for the
 * client's identity fields (the PI Form spec explicitly says "must match
 * exactly as they appear on official documents"), so it overwrites the
 * live `clients` row with what the client confirmed (so any FUTURE new
 * order for this client starts from the corrected details), plus the
 * order's buyers_po_ref. 90-item backlog "standalone order" follow-on: it
 * also applies that same correction onto THIS order's own snapshot
 * columns (OrderRepository::applyClientSnapshot()) — this order's own
 * printed content is this order's own current truth, never a side effect
 * of what happens to be true on `clients` right now. Every other existing
 * order for this client is untouched either way. Everything else on the
 * submission (payment-terms confirmation, formal quotation-acceptance
 * reference, confirmed Incoterm/port/COO, changes from quotation, special
 * document requirements) stays on the row itself as the record staff read
 * before generating the PI — never auto-written onto the order's own
 * structured/FK-driven columns.
 */
final class PiIntakeReviewController
{
    public function index(array $params): void
    {
        $actor = AuthService::currentUser();
        if (UiTemplateService::resolve($actor['ui_template_preference_id'] ?? null) === 'kanban') {
            $this->indexKanban();
            return;
        }

        View::render('pi_intake_review/index', [
            'pending' => PiIntakeRepository::pendingReview(),
            'resolved' => PiIntakeRepository::recentResolved(),
        ], 'layout/base');
    }

    /** The Kanban UI template's PI Intake Review board: Pending Review / Applied / Rejected columns. */
    private function indexKanban(): void
    {
        $pending = PiIntakeRepository::pendingReview();
        $resolved = PiIntakeRepository::recentResolved();
        $applied = array_values(array_filter($resolved, static fn(array $s): bool => $s['status'] === 'applied'));
        $rejected = array_values(array_filter($resolved, static fn(array $s): bool => $s['status'] === 'rejected'));

        View::render('pi_intake_review/index_kanban', [
            'pending'  => $pending,
            'applied'  => $applied,
            'rejected' => $rejected,
        ], 'layout/base');
    }

    public function accept(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $submission = PiIntakeRepository::find($id);
        if (!$submission || $submission['status'] !== 'pending_review') {
            Flash::set('error', 'Submission not found, or already resolved.');
            header('Location: /pi-intake-review');
            return;
        }

        $order = OrderRepository::find((int) $submission['order_id']);
        if (!$order) {
            Flash::set('error', 'The order for this submission no longer exists.');
            header('Location: /pi-intake-review');
            return;
        }

        // QA Round 5, R5-02: the buyer's corrected email must not be one another client already uses.
        if ($other = ClientRepository::findOtherWithEmail($submission['email'] ?? null, (int) $order['client_id'])) {
            Flash::set('error', ClientRepository::duplicateEmailMessage((string) $submission['email'], $other));
            header('Location: /pi-intake-review');
            return;
        }

        $user = AuthService::currentUser();
        ClientRepository::update((int) $order['client_id'], [
            'company_legal_name'     => $submission['company_legal_name'],
            'billing_address'        => $submission['billing_address'],
            'billing_address_line1'  => $submission['billing_address_line1'] ?? null,
            'billing_address_line2'  => $submission['billing_address_line2'] ?? null,
            'billing_city'           => $submission['billing_city'] ?? null,
            'billing_postcode'       => $submission['billing_postcode'] ?? null,
            'billing_country'        => $submission['billing_country'] ?? null,
            'vat_eori_tax_no'        => $submission['vat_eori_tax_no'],
            'contact_person'         => $submission['contact_person'],
            'email'                  => $submission['email'],
            'phone'                  => $submission['phone'],
            'country_of_destination' => $submission['country_of_destination'],
            // Owner report (2026-10-26): a blank answer ("not sure") keeps the COO type already on file.
            'coo_type'               => ($submission['coo_type'] ?? '') !== '' ? $submission['coo_type'] : (ClientRepository::find((int) $order['client_id'])['coo_type'] ?? null),
            // Section BB — the client's own self-service Consignee/Notify
            // Party split from the PI-details form, carried through
            // verbatim to the exact same ClientRepository::update()
            // columns the admin Clients edit screen writes to.
            'consignee_same_as_buyer'        => $submission['consignee_same_as_buyer'],
            'consignee_name'                  => $submission['consignee_name'],
            'consignee_address_line1'         => $submission['consignee_address_line1'],
            'consignee_address_line2'         => $submission['consignee_address_line2'],
            'consignee_city'                  => $submission['consignee_city'],
            'consignee_postcode'              => $submission['consignee_postcode'],
            'consignee_country'               => $submission['consignee_country'],
            'consignee_vat_eori_tax_no'       => $submission['consignee_vat_eori_tax_no'],
            'consignee_contact_person'        => $submission['consignee_contact_person'],
            'consignee_phone'                 => $submission['consignee_phone'],
            'consignee_email'                 => $submission['consignee_email'],
            'notify_party_same_as_consignee'  => $submission['notify_party_same_as_consignee'],
            'notify_party'                     => $submission['notify_party'],
            'notify_party_address_line1'      => $submission['notify_party_address_line1'],
            'notify_party_address_line2'      => $submission['notify_party_address_line2'],
            'notify_party_city'               => $submission['notify_party_city'],
            'notify_party_postcode'           => $submission['notify_party_postcode'],
            'notify_party_country'            => $submission['notify_party_country'],
            'notify_party_contact_person'     => $submission['notify_party_contact_person'],
            'notify_party_phone'              => $submission['notify_party_phone'],
            'notify_party_email'              => $submission['notify_party_email'],
        ]);
        // 90-item backlog "standalone order" follow-on: the correction
        // above only changes what a FUTURE new order for this client
        // starts from. This order is already created — its own printed
        // content only changes when something happens to it directly, so
        // the same confirmed details are applied onto its own snapshot
        // columns too. Every other existing order for this client is
        // untouched.
        OrderRepository::applyClientSnapshot((int) $order['id'], (int) $order['client_id']);
        if (!empty($submission['buyer_po_ref'])) {
            OrderRepository::setBuyersPoRef((int) $order['id'], $submission['buyer_po_ref']);
        }
        // Owner report (2026-10-26): the COO type the client confirmed is
        // what this order's documents print, not just the client record.
        if (($submission['coo_type'] ?? '') !== '') {
            OrderRepository::setCooType((int) $order['id'], (string) $submission['coo_type']);
        }

        // docs/schema.sql Section AC — the client explicitly consented to
        // this exact data being locked when they checked the box on the
        // PI-details form; applying it here is the moment that consent
        // takes effect. A no-op if OrderController::recordAdvancePayment()
        // already locked this client first (ClientRepository::lockData()
        // only ever fires once).
        ClientRepository::lockData((int) $order['client_id'], "Client consented via PI-details form, applied by {$user['name']}");

        PiIntakeRepository::markApplied($id, (int) $user['id']);
        AuditLogRepository::log(
            (int) $user['id'], 'PI_INTAKE_APPLIED', 'orders', (int) $order['id'],
            null, null, null, "PI-stage details confirmed by client and applied to client #{$order['client_id']}"
        );

        Flash::set('success', "Client details updated from the PI-stage confirmation. Review the confirmed Incoterm/port/COO/payment-terms on this page before generating the PI.");
        header("Location: /orders/{$order['id']}");
    }

    public function reject(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $submission = PiIntakeRepository::find($id);
        if (!$submission || $submission['status'] !== 'pending_review') {
            Flash::set('error', 'Submission not found, or already resolved.');
            header('Location: /pi-intake-review');
            return;
        }

        $reason = trim((string) ($_POST['reason'] ?? ''));
        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /pi-intake-review');
            return;
        }

        $user = AuthService::currentUser();
        PiIntakeRepository::markRejected($id, (int) $user['id'], $reason);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'PI_INTAKE_REJECTED', 'pi_intake_submissions', $id, null, 'pending_review', 'rejected', $reason);
        Flash::set('success', 'PI-stage submission rejected — the client can resubmit via the same link.');
        header('Location: /pi-intake-review');
    }
}
