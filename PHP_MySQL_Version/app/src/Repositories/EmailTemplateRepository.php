<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

final class EmailTemplateRepository
{
    public static function find(string $templateKey): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE template_key = :key');
        $stmt->execute(['key' => $templateKey]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()->query('SELECT * FROM email_templates ORDER BY template_key')->fetchAll();
    }
}
