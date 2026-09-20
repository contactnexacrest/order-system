<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class TermsClauseRepository
{
    /** @return array<int, array{title:string, text:string, is_locked:bool}> ordered clauses for a document type code */
    public static function forDocumentTypeCode(string $documentTypeCode): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT c.clause_title, c.clause_text, c.is_locked
             FROM tc_clauses c
             JOIN tc_clause_documents cd ON cd.clause_id = c.id
             JOIN document_types dt ON dt.id = cd.document_type_id
             WHERE dt.code = :code AND c.status = \'active\'
             ORDER BY c.clause_order'
        );
        $stmt->execute(['code' => $documentTypeCode]);
        return array_map(static function (array $row): array {
            return [
                'title'     => $row['clause_title'],
                'text'      => $row['clause_text'],
                'is_locked' => (bool) $row['is_locked'],
            ];
        }, $stmt->fetchAll());
    }
}
