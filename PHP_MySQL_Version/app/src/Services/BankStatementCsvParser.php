<?php

declare(strict_types=1);

namespace App\Services;

/**
 * CA / Accounting module (Phase 5) — parses a bank-issued CSV export into
 * plain transaction rows. Bank statement CSV layouts vary wildly bank to
 * bank; this recognizes a handful of common header-name variants rather
 * than one fixed format, and reports how many rows it couldn't parse
 * rather than silently dropping them. If a real statement's headers don't
 * match any alias here, that's a real gap to close by adding the alias,
 * not a bug in the parsing logic itself.
 */
final class BankStatementCsvParser
{
    private const DATE_ALIASES = ['date', 'txn date', 'transaction date', 'value date'];
    private const DESCRIPTION_ALIASES = ['description', 'narration', 'particulars', 'remarks'];
    private const REFERENCE_ALIASES = ['reference', 'reference no', 'ref no', 'cheque no', 'chq no', 'utr'];
    private const DEBIT_ALIASES = ['debit', 'withdrawal amt', 'withdrawal', 'debit amount'];
    private const CREDIT_ALIASES = ['credit', 'deposit amt', 'deposit', 'credit amount'];
    private const AMOUNT_ALIASES = ['amount'];
    private const TYPE_ALIASES = ['type', 'dr/cr', 'cr/dr'];

    /**
     * @return array{rows: array<int, array{date:string, description:?string, reference:?string, credit:?float, debit:?float}>, skipped: int}
     * @throws \RuntimeException if the file can't be read or no recognizable header is found
     */
    public static function parse(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Could not read the uploaded file.');
        }

        $header = fgetcsv($handle);
        if ($header === false || $header === [null]) {
            fclose($handle);
            throw new \RuntimeException('The file appears to be empty.');
        }
        $normalized = array_map(static fn($h) => strtolower(trim((string) $h)), $header);

        $dateIdx = self::findColumn($normalized, self::DATE_ALIASES);
        $descIdx = self::findColumn($normalized, self::DESCRIPTION_ALIASES);
        $refIdx = self::findColumn($normalized, self::REFERENCE_ALIASES);
        $debitIdx = self::findColumn($normalized, self::DEBIT_ALIASES);
        $creditIdx = self::findColumn($normalized, self::CREDIT_ALIASES);
        $amountIdx = self::findColumn($normalized, self::AMOUNT_ALIASES);
        $typeIdx = self::findColumn($normalized, self::TYPE_ALIASES);

        $hasCreditDebitPair = $debitIdx !== null || $creditIdx !== null;
        $hasAmountTypePair = $amountIdx !== null && $typeIdx !== null;
        if ($dateIdx === null || (!$hasCreditDebitPair && !$hasAmountTypePair)) {
            fclose($handle);
            throw new \RuntimeException('Could not find recognizable Date and Credit/Debit (or Amount + Type) columns in the file\'s header row.');
        }

        $rows = [];
        $skipped = 0;
        while (($row = fgetcsv($handle)) !== false) {
            if (count(array_filter($row, static fn($c) => trim((string) $c) !== '')) === 0) {
                continue; // blank line
            }

            $date = self::parseDate(trim((string) ($row[$dateIdx] ?? '')));
            if ($date === null) {
                $skipped++;
                continue;
            }

            $credit = null;
            $debit = null;
            if ($hasCreditDebitPair) {
                $credit = $creditIdx !== null ? self::parseAmount($row[$creditIdx] ?? '') : null;
                $debit = $debitIdx !== null ? self::parseAmount($row[$debitIdx] ?? '') : null;
            } else {
                $amount = self::parseAmount($row[$amountIdx] ?? '');
                $type = strtolower(trim((string) ($row[$typeIdx] ?? '')));
                if ($amount !== null) {
                    if (str_starts_with($type, 'cr')) {
                        $credit = $amount;
                    } elseif (str_starts_with($type, 'dr')) {
                        $debit = $amount;
                    }
                }
            }

            if ($credit === null && $debit === null) {
                $skipped++;
                continue;
            }

            $rows[] = [
                'date' => $date,
                'description' => $descIdx !== null ? (trim((string) ($row[$descIdx] ?? '')) ?: null) : null,
                'reference' => $refIdx !== null ? (trim((string) ($row[$refIdx] ?? '')) ?: null) : null,
                'credit' => $credit,
                'debit' => $debit,
            ];
        }
        fclose($handle);

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** @param string[] $normalizedHeader @param string[] $aliases */
    private static function findColumn(array $normalizedHeader, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $idx = array_search($alias, $normalizedHeader, true);
            if ($idx !== false) {
                return $idx;
            }
        }
        return null;
    }

    private static function parseDate(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }
        foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y', 'd-M-Y', 'd M Y', 'd-M-y'] as $format) {
            $dt = \DateTime::createFromFormat($format, $raw);
            if ($dt !== false) {
                return $dt->format('Y-m-d');
            }
        }
        $ts = strtotime($raw);
        return $ts !== false ? date('Y-m-d', $ts) : null;
    }

    private static function parseAmount(mixed $raw): ?float
    {
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '-') {
            return null;
        }
        $clean = preg_replace('/[^0-9.\-]/', '', str_replace(',', '', $raw));
        if ($clean === null || $clean === '' || $clean === '-' || $clean === '.') {
            return null;
        }
        $value = (float) $clean;
        return $value !== 0.0 ? abs($value) : null;
    }
}
