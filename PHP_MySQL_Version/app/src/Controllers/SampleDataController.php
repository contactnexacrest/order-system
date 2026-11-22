<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Services\AuthService;
use App\Services\SampleDataService;

/**
 * Phase E follow-up — Sample Data Playground (Section: user request
 * 2026-09-19, "can we have some sample records to play with... this
 * effects only the test data and not actual data"). Gated on the new
 * `manage_sample_data` permission (Admin/Managing Director by default,
 * same wildcard grant every other admin-only permission gets).
 */
final class SampleDataController
{
    public function index(array $params): void
    {
        View::render('sample_data/index', [
            'isLoaded' => SampleDataService::isLoaded(),
            'summary'  => SampleDataService::isLoaded() ? SampleDataService::summary() : [],
        ], 'layout/base');
    }

    public function load(array $params): void
    {
        $user = AuthService::currentUser();
        // R10-N10 (QA Round 10): loading builds every sample document (PDFs) and takes longer than a shared host's
        // usual 30-second limit — this one admin action may run to the end, and a failed load is cleared again,
        // so the sample set is never left half-loaded.
        @set_time_limit(0);
        ignore_user_abort(true);
        try {
            // QA MTX-008: loading demo records never e-mails anyone (the sample buyer's portal access, review notices, ...).
            $result = \App\Services\EmailService::withoutSending('sample data is being loaded', static fn (): array => SampleDataService::load((int) $user['id']));
        } catch (\Throwable $e) {
            error_log('[SAMPLE DATA LOAD FAILED] ' . $e->getMessage());
            try {
                SampleDataService::clear(); // R10-N10: never leave a half-loaded sample set
            } catch (\Throwable $ignored) {
                error_log('[SAMPLE DATA CLEAR AFTER FAILED LOAD FAILED] ' . $ignored->getMessage());
            }
            Flash::setErrorFromException($e, 'Could not load sample data — nothing was kept; check the server error log.');
            header('Location: /sample-data');
            return;
        }

        AuditLogRepository::log((int) $user['id'], 'SAMPLE_DATA_LOADED', 'clients', null, null, null, null,
            "Loaded {$result['clients']} sample client(s) / {$result['orders']} sample order(s).");

        Flash::set('success', "Sample data loaded: {$result['clients']} client(s), {$result['orders']} order(s) — look for the \"[SAMPLE]\" prefix everywhere they appear. Clear them any time from this same screen.");
        header('Location: /sample-data');
    }

    public function clear(array $params): void
    {
        $user = AuthService::currentUser();
        try {
            $result = SampleDataService::clear();
        } catch (\Throwable $e) {
            error_log('[SAMPLE DATA CLEAR FAILED] ' . $e->getMessage());
            Flash::set('error', 'Could not clear sample data — check the server error log. Nothing was left half-deleted (it runs in one transaction).');
            header('Location: /sample-data');
            return;
        }

        AuditLogRepository::log((int) $user['id'], 'SAMPLE_DATA_CLEARED', 'clients', null, null, null, null,
            "Removed {$result['clients']} sample client(s), {$result['orders']} sample order(s), {$result['files']} generated file(s).");

        Flash::set('success', "Sample data cleared: {$result['clients']} client(s), {$result['orders']} order(s), {$result['files']} file(s) removed. Load it again whenever you like.");
        header('Location: /sample-data');
    }
}
