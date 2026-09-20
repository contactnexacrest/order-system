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
        try {
            $result = SampleDataService::load((int) $user['id']);
        } catch (\Throwable $e) {
            error_log('[SAMPLE DATA LOAD FAILED] ' . $e->getMessage());
            Flash::set('error', $e->getMessage() ?: 'Could not load sample data — check the server error log.');
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
