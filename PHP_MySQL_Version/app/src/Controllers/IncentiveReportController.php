<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Csv;
use App\Helpers\View;
use App\Services\IncentiveReportService;

/**
 * Owner decision D-245 P5: the incentive & commission register, "To pay",
 * "Owed at month end" and a person's statement. For whoever manages or pays
 * incentives or sees the price build-up (MD / ED / Accounts) — staff see only
 * their own amounts on My incentives, associates on their portal.
 */
final class IncentiveReportController
{
    /** @return array<string,mixed> the register filters from the query string */
    private static function filters(): array
    {
        $month = (string) ($_GET['month'] ?? '');
        $from = (string) ($_GET['from'] ?? '');
        $to = (string) ($_GET['to'] ?? '');
        $state = (string) ($_GET['state'] ?? '');
        return [
            'state' => isset(IncentiveReportService::STATES[$state]) ? $state : '',
            'month' => preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : '',
            'user_id' => max(0, (int) ($_GET['user_id'] ?? 0)),
            'associate_id' => max(0, (int) ($_GET['associate_id'] ?? 0)),
            'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 ? $from : '',
            'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) === 1 ? $to : '',
        ];
    }

    /** @param list<array<string,mixed>> $rows @return array{people: array<int,string>, associates: array<int,string>} */
    private static function payees(array $rows): array
    {
        $people = [];
        $associates = [];
        foreach ($rows as $r) {
            if ($r['user_id'] !== null) {
                $people[$r['user_id']] = $r['payee'];
            }
            if ($r['associate_id'] !== null) {
                $associates[$r['associate_id']] = $r['payee'];
            }
        }
        asort($people);
        asort($associates);
        return ['people' => $people, 'associates' => $associates];
    }

    public function register(array $params): void
    {
        $f = self::filters();
        $all = IncentiveReportService::allRows();
        $rows = IncentiveReportService::filter($all, $f);
        if (($_GET['format'] ?? '') === 'csv') {
            Csv::stream('incentive_commission_register.csv', IncentiveReportService::HEADERS, IncentiveReportService::csvRows($rows));
        }
        View::render('incentives/report_register', ['rows' => $rows, 'totals' => IncentiveReportService::totals($rows), 'filters' => $f] + self::payees($all), 'layout/base');
    }

    public function toPay(array $params): void
    {
        $kind = in_array($_GET['payee'] ?? '', ['staff', 'associate'], true) ? (string) $_GET['payee'] : '';
        $groups = IncentiveReportService::toPay($kind);
        if (($_GET['format'] ?? '') === 'csv') {
            $rows = [];
            foreach ($groups as $g) {
                foreach (IncentiveReportService::csvRows($g['rows']) as $r) {
                    $rows[] = ['Group' => $g['label']] + $r;
                }
            }
            Csv::stream('incentive_to_pay.csv', array_merge(['Group'], IncentiveReportService::HEADERS), $rows);
        }
        View::render('incentives/report_to_pay', ['groups' => $groups, 'payee' => $kind], 'layout/base');
    }

    public function owed(array $params): void
    {
        $date = (string) ($_GET['date'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $date = date('Y-m-t');
        }
        $rows = IncentiveReportService::owedAt($date);
        if (($_GET['format'] ?? '') === 'csv') {
            Csv::stream('incentive_owed_' . $date . '.csv', IncentiveReportService::HEADERS, IncentiveReportService::csvRows($rows));
        }
        View::render('incentives/report_owed', ['rows' => $rows, 'totals' => IncentiveReportService::totals($rows), 'date' => $date], 'layout/base');
    }

    public function statement(array $params): void
    {
        $all = IncentiveReportService::allRows();
        $userId = max(0, (int) ($_GET['user_id'] ?? 0));
        $associateId = $userId > 0 ? 0 : max(0, (int) ($_GET['associate_id'] ?? 0));
        $rows = ($userId || $associateId) ? IncentiveReportService::filter($all, ['user_id' => $userId, 'associate_id' => $associateId]) : [];
        if (($_GET['format'] ?? '') === 'csv' && $rows !== []) {
            Csv::stream('incentive_statement.csv', IncentiveReportService::HEADERS, IncentiveReportService::csvRows($rows));
        }
        $payees = self::payees($all);
        $name = $userId ? ($payees['people'][$userId] ?? '') : ($associateId ? ($payees['associates'][$associateId] ?? '') : '');
        View::render('incentives/report_statement', ['rows' => $rows, 'totals' => IncentiveReportService::totals($rows), 'userId' => $userId, 'associateId' => $associateId, 'name' => $name] + $payees, 'layout/base');
    }
}
