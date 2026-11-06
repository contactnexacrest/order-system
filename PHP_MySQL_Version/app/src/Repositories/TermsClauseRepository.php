<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class TermsClauseRepository
{
    /** @return array<int, array{title:string, text:string, is_locked:bool}> ordered clauses for a document type code */
    public static function forDocumentTypeCode(string $documentTypeCode): array
    {
        return self::forDocumentTypeCodeAndGroup($documentTypeCode, 'standard', null);
    }

    /**
     * Batch 3+ — Legal Terms & Definitions. Same table, same admin-edit
     * story as every other clause (status/text/clause_order are just
     * ordinary row edits) — `clause_group` only changes WHERE a clause
     * renders (T&C body vs. the Legal Terms box vs. the Definitions
     * box), and `visibility_rule` is the one new thing: a clause can be
     * scoped to only the orders whose own payment-preset balance trigger
     * matches, evaluated here rather than hardcoded in any template.
     * $balanceTriggerOption is the order's own
     * payment_presets.balance_trigger_option (A_BEFORE_SHIPMENT /
     * B_AGAINST_BL, via orders.balance_trigger_option_override) — pass
     * null when no order context applies (never matches a conditional
     * visibility_rule, so only 'always' rows come back).
     *
     * @return array<int, array{title:string, text:string, is_locked:bool}>
     */
    /** @var array<string, list<array<string,mixed>>>|null D-262: an order's frozen clauses while its documents are made */
    private static ?array $snapshot = null;

    /**
     * Sets the frozen clauses read instead of tc_clauses (null clears them).
     *
     * @param array<string, list<array<string,mixed>>>|null $rows "CODE|group" => the rows the live query returned
     * @return array<string, list<array<string,mixed>>>|null the previous snapshot, to restore
     */
    public static function setSnapshot(?array $rows): ?array
    {
        $previous = self::$snapshot;
        self::$snapshot = $rows === [] ? null : $rows;
        return $previous;
    }

    /**
     * D-262: every document type's clauses in every group, exactly as the live query returns them —
     * what an order keeps when its PI is approved.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public static function captureAll(): array
    {
        $pdo = Database::connection();
        $codes = $pdo->query('SELECT code FROM document_types ORDER BY code')->fetchAll(\PDO::FETCH_COLUMN);
        $groups = $pdo->query('SELECT DISTINCT clause_group FROM tc_clauses ORDER BY clause_group')->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        foreach ($codes as $code) {
            foreach ($groups as $group) {
                $rows = self::liveRows((string) $code, (string) $group);
                if ($rows !== []) {
                    $out[$code . '|' . $group] = $rows;
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private static function liveRows(string $documentTypeCode, string $clauseGroup): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.clause_title, c.clause_text, c.is_locked, c.visibility_rule, c.clause_set
             FROM tc_clauses c
             JOIN tc_clause_documents cd ON cd.clause_id = c.id
             JOIN document_types dt ON dt.id = cd.document_type_id
             WHERE dt.code = :code AND c.status = \'active\' AND c.clause_group = :clause_group
             ORDER BY c.clause_order'
        );
        $stmt->execute(['code' => $documentTypeCode, 'clause_group' => $clauseGroup]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function forDocumentTypeCodeAndGroup(string $documentTypeCode, string $clauseGroup, ?string $balanceTriggerOption, ?array $clauseSets = null): array
    {
        $rows = self::$snapshot !== null
            ? (self::$snapshot[$documentTypeCode . '|' . $clauseGroup] ?? [])
            : self::liveRows($documentTypeCode, $clauseGroup);

        // D-257 Phase 1: a clause with a clause set prints only when one of the
        // order's categories uses that set; an empty set = every order (today's
        // clauses). No order context = Natural Stone, as before.
        $sets = $clauseSets ?? ['natural_stone'];
        $visible = array_filter($rows, static function (array $row) use ($balanceTriggerOption, $sets): bool {
            if (!self::inClauseSets($row['clause_set'] ?? null, $sets)) {
                return false;
            }
            return match ($row['visibility_rule']) {
                'balance_trigger_before_shipment' => $balanceTriggerOption === 'A_BEFORE_SHIPMENT',
                'balance_trigger_against_bl'       => $balanceTriggerOption === 'B_AGAINST_BL',
                default                            => true, // 'always'
            };
        });

        return array_map(static function (array $row): array {
            return [
                'title'     => $row['clause_title'],
                'text'      => $row['clause_text'],
                'is_locked' => (bool) $row['is_locked'],
            ];
        }, array_values($visible));
    }

    /** @param list<string> $orderSets */
    public static function inClauseSets(?string $clauseSet, array $orderSets): bool
    {
        $own = array_filter(array_map('trim', explode(',', (string) $clauseSet)), static fn ($v) => $v !== '');
        return $own === [] || array_intersect($own, $orderSets) !== [];
    }
}
