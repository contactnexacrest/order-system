<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReasonValidator;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\ClientIntakeRepository;
use App\Repositories\ClientRepository;
use App\Services\AuthService;
use App\Services\ReferenceNumberService;
use App\Services\UiTemplateService;

/**
 * Staff review queue for public quotation-details submissions
 * (client_intake_submissions). Accepting a submission creates the real
 * `clients` row through the exact same ClientRepository::create() path a
 * manually-entered walk-in client goes through — never a separate,
 * parallel creation path — then hands off to the normal /clients/{id}
 * screen so staff proceeds through order creation and Quotation
 * generation exactly as they always have. Per the confirmed flow, this
 * never auto-generates a Quotation.
 */
final class ClientIntakeReviewController
{
    public function index(array $params): void
    {
        $actor = AuthService::currentUser();
        if (UiTemplateService::showsBoard($actor['ui_template_preference_id'] ?? null)) {
            $this->indexKanban();
            return;
        }

        View::render('client_intake_review/index', [
            'pending' => ClientIntakeRepository::pending(),
            'resolved' => ClientIntakeRepository::recentResolved(),
            // Owner hard rule 2026-11-01: a Kanban user who switched to the list can switch back.
            'kanbanUser' => UiTemplateService::resolve($actor['ui_template_preference_id'] ?? null) === 'kanban',
        ], 'layout/base');
    }

    /** The Kanban UI template's Client Intake Review board: Pending / Converted / Rejected columns. */
    private function indexKanban(): void
    {
        $pending = ClientIntakeRepository::pending();
        $resolved = ClientIntakeRepository::recentResolved();
        $converted = array_values(array_filter($resolved, static fn(array $s): bool => $s['status'] === 'converted'));
        $rejected = array_values(array_filter($resolved, static fn(array $s): bool => $s['status'] === 'rejected'));

        View::render('client_intake_review/index_kanban', [
            'pending'   => $pending,
            'converted' => $converted,
            'rejected'  => $rejected,
        ], 'layout/base');
    }

    public function accept(array $params): void
    {
        $user = AuthService::currentUser();
        $id = (int) ($params['id'] ?? 0);
        $submission = ClientIntakeRepository::find($id);

        if (!$submission || $submission['status'] !== 'pending') {
            Flash::set('error', 'Submission not found, or already resolved.');
            header('Location: /client-intake');
            return;
        }

        // QA Round 5, R5-02: an enquiry from an email another client already
        // uses is not accepted as a new client.
        if ($other = ClientRepository::findOtherWithEmail($submission['email'] ?? null)) {
            Flash::set('error', ClientRepository::duplicateEmailMessage((string) $submission['email'], $other) . ' If this enquiry is from that same buyer, create the order under their existing client record and reject this submission.');
            header('Location: /client-intake');
            return;
        }

        $clientUniqueNumber = ReferenceNumberService::generateClientUniqueNumber();
        $consigneeSameAsBuyer = (int) ($submission['consignee_same_as_buyer'] ?? 1) === 1;
        $clientId = ClientRepository::create([
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
            'coo_type'               => $submission['coo_type'] ?: 'To Be Confirmed',
            // Section BB — the client's own self-service Consignee split
            // from the quotation-details form; Notify Party is never
            // collected at this stage (see ClientIntakeController), so it
            // stays at its default (same-as-consignee) here.
            'consignee_same_as_buyer' => $consigneeSameAsBuyer ? 1 : 0,
            'consignee_name'              => $consigneeSameAsBuyer ? null : $submission['consignee_name'],
            'consignee_address_line1'     => $consigneeSameAsBuyer ? null : $submission['consignee_address_line1'],
            'consignee_address_line2'     => $consigneeSameAsBuyer ? null : $submission['consignee_address_line2'],
            'consignee_city'              => $consigneeSameAsBuyer ? null : $submission['consignee_city'],
            'consignee_postcode'          => $consigneeSameAsBuyer ? null : $submission['consignee_postcode'],
            'consignee_country'           => $consigneeSameAsBuyer ? null : $submission['consignee_country'],
            'consignee_vat_eori_tax_no'   => $consigneeSameAsBuyer ? null : $submission['consignee_vat_eori_tax_no'],
            'consignee_contact_person'    => $consigneeSameAsBuyer ? null : $submission['consignee_contact_person'],
            'consignee_phone'             => $consigneeSameAsBuyer ? null : $submission['consignee_phone'],
            'consignee_email'             => $consigneeSameAsBuyer ? null : $submission['consignee_email'],
        ], (int) $user['id'], $clientUniqueNumber);

        ClientIntakeRepository::markConverted($id, $clientId, (int) $user['id']);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'CLIENT_INTAKE_ACCEPTED', 'client_intake_submissions', $id, null, 'pending', 'converted', null);

        Flash::set('success', "Client created — Buyer Inquiry Ref {$clientUniqueNumber}. Proceed to create the order and generate the Quotation.");
        header("Location: /clients/{$clientId}");
    }

    /**
     * 90-item backlog "resend-lost-intake-link flow": the correction link
     * issued at submit() time (ClientIntakeController::issueCorrectionLink)
     * expires after 7 days with no recovery path — if the client never
     * got the email, or it expired before they got to it, the submission
     * was stuck pending forever with no way to correct it themselves.
     * Re-issues a fresh token/expiry and re-emails it, same staff-
     * initiated regenerate-and-send pattern as the PI-stage intake link
     * (OrderController::generatePiFormLink) and the Buyer Action link.
     */
    public function resendLink(array $params): void
    {
        $user = AuthService::currentUser();
        $id = (int) ($params['id'] ?? 0);
        $submission = ClientIntakeRepository::find($id);

        if (!$submission || $submission['status'] !== 'pending') {
            Flash::set('error', 'Submission not found, or already resolved.');
            header('Location: /client-intake');
            return;
        }

        $link = ClientIntakeController::issueCorrectionLink($id, $submission['email']);

        AuditLogRepository::log((int) $user['id'], 'INTAKE_CORRECTION_LINK_RESENT', 'client_intake_submissions', $id, null, null, null, null);
        Flash::set('success', "Correction link resent to {$submission['email']}: {$link}");
        header('Location: /client-intake');
    }

    public function reject(array $params): void
    {
        $user = AuthService::currentUser();
        $id = (int) ($params['id'] ?? 0);
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($error = ReasonValidator::check($reason)) {
            Flash::set('error', $error);
            header('Location: /client-intake');
            return;
        }

        ClientIntakeRepository::markRejected($id, (int) $user['id'], $reason);
        // External QA report N-08: this action never reached the audit
        // log, so it never notified anyone either.
        AuditLogRepository::log((int) $user['id'], 'CLIENT_INTAKE_REJECTED', 'client_intake_submissions', $id, null, 'pending', 'rejected', $reason);
        Flash::set('success', 'Request rejected.');
        header('Location: /client-intake');
    }
}
