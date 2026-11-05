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
    public static function forDocumentTypeCodeAndGroup(string $documentTypeCode, string $clauseGroup, ?string $balanceTriggerOption, ?array $clauseSets = null): array
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
        $rows = $stmt->fetchAll();

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
