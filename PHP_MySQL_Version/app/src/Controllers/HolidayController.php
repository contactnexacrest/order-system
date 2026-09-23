<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\CompanyHolidayRepository;
use App\Services\AuthService;

/**
 * Admin screen for company_holidays — the specific dated holidays
 * WorkingDaysCalculator skips when computing a "working days" deadline
 * (e.g. a dispute's contractual response window). Deliberately a flat
 * list of exact dates rather than a recurring-rule engine: a public
 * holiday's actual date changes every year (Diwali, Eid, etc.), so
 * re-entering each year's real dates is both simpler and more correct
 * than a "same day every year" rule that would drift wrong for lunar/
 * lunisolar holidays.
 */
final class HolidayController
{
    public function index(array $params): void
    {
        View::render('holidays/index', [
            'holidays' => CompanyHolidayRepository::all(),
        ], 'layout/base');
    }

    public function create(array $params): void
    {
        $date = trim((string) ($_POST['holiday_date'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));

        if ($date === '' || $description === '') {
            Flash::set('error', 'Both a date and a description are required.');
            header('Location: /holidays');
            return;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            Flash::set('error', 'Invalid date.');
            header('Location: /holidays');
            return;
        }

        $user = AuthService::currentUser();
        try {
            $id = CompanyHolidayRepository::create($date, $description, (int) $user['id']);
        } catch (\PDOException $e) {
            Flash::set('error', 'That date is already on the holiday calendar.');
            header('Location: /holidays');
            return;
        }
        AuditLogRepository::log((int) $user['id'], 'HOLIDAY_ADDED', 'company_holidays', $id, null, null, "{$date}: {$description}");
        Flash::set('success', "Holiday added: {$date} — {$description}.");
        header('Location: /holidays');
    }

    public function update(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $existing = CompanyHolidayRepository::find($id);
        if (!$existing) {
            Flash::set('error', 'Holiday not found.');
            header('Location: /holidays');
            return;
        }

        $date = trim((string) ($_POST['holiday_date'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($date === '' || $description === '') {
            Flash::set('error', 'Both a date and a description are required.');
            header('Location: /holidays');
            return;
        }
        $d = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if (!$d || $d->format('Y-m-d') !== $date) {
            Flash::set('error', 'Invalid date.');
            header('Location: /holidays');
            return;
        }

        $user = AuthService::currentUser();
        try {
            CompanyHolidayRepository::update($id, $date, $description);
        } catch (\PDOException $e) {
            Flash::set('error', 'That date is already on the holiday calendar.');
            header('Location: /holidays');
            return;
        }
        AuditLogRepository::log(
            (int) $user['id'], 'HOLIDAY_UPDATED', 'company_holidays', $id,
            null, "{$existing['holiday_date']}: {$existing['description']}", "{$date}: {$description}"
        );
        Flash::set('success', "Holiday updated: {$date} — {$description}.");
        header('Location: /holidays');
    }

    public function delete(array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        $user = AuthService::currentUser();
        CompanyHolidayRepository::delete($id);
        AuditLogRepository::log((int) $user['id'], 'HOLIDAY_REMOVED', 'company_holidays', $id);
        Flash::set('success', 'Holiday removed.');
        header('Location: /holidays');
    }
}
