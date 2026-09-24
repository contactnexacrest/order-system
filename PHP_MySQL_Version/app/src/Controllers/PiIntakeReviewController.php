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

/**
 * Staff review queue for PI-stage intake submissions (schema.sql Section
 * AA) — a separate queue from ClientIntakeReviewController's Quotation-
 * stage one. Accepting is the authoritative correction point for the
 * client's identity fields (the PI Form spec explicitly says "must match
 * exactly as they appear on official documents"), so it overwrites the
 * live `clients` row with what the client confirmed, plus the order's
 * buyers_po_ref. Everything else on the submission (payment-terms
 * confirmation, formal quotation-acceptance reference, confirmed
 * Incoterm/port/COO, changes from quotation, special document
 * requirements) stays on the row itself as the record staff read before
 * generating the PI — never auto-written onto the order's own
 * structured/FK-driven columns.
 */
final class PiIntakeReviewController
{
    public function index(array $params): void
    {
        View::render('pi_intake_review/index', [
            'pending' => PiIntakeRepository::pendingReview(),
            'resolved' => PiIntakeRepository::recentResolved(),
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

        $user = AuthService::currentUser();
        ClientRepository::update((int) $order['client_id'], [
            'company_legal_name'     => $submission['company_legal_name'],
            'billing_address'        => $submission['billing_address'],
            'consignee_name'         => $submission['consignee_name'],
            'consignee_address'      => $submission['consignee_address'],
            'vat_eori_tax_no'        => $submission['vat_eori_tax_no'],
            'contact_person'         => $submission['contact_person'],
            'email'                  => $submission['email'],
            'phone'                  => $submission['phone'],
            'country_of_destination' => $submission['country_of_destination'],
            'coo_type'               => $submission['coo_type'],
            'notify_party'           => $submission['notify_party'],
        ]);
        if (!empty($submission['buyer_po_ref'])) {
            OrderRepository::setBuyersPoRef((int) $order['id'], $submission['buyer_po_ref']);
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
        Flash::set('success', 'PI-stage submission rejected — the client can resubmit via the same link.');
        header('Location: /pi-intake-review');
    }
}
