<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Config\Database;

/**
 * docs/schema.sql Section AI — a template can be added or edited (governed
 * by manage_email_templates) but never deleted. email_log already freezes
 * subject/body at send time (body_snapshot), so deactivating a template
 * here never changes what a past send actually said — is_active only
 * controls whether it's still offered for a NEW send.
 */
final class EmailTemplateRepository
{
    public static function find(string $templateKey): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE template_key = :key');
        $stmt->execute(['key' => $templateKey]);
        return $stmt->fetch() ?: null;
    }

    public static function findById(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM email_templates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int, array<string,mixed>> */
    public static function all(): array
    {
        return Database::connection()->query('SELECT * FROM email_templates ORDER BY template_key')->fetchAll();
    }

    public static function create(string $templateKey, string $subject, string $body, ?string $footer, int $createdBy): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO email_templates (template_key, subject, body, footer, is_active, created_by, updated_by)
             VALUES (:template_key, :subject, :body, :footer, 1, :created_by, :updated_by)'
        );
        $stmt->execute([
            'template_key' => $templateKey,
            'subject'      => $subject,
            'body'         => $body,
            'footer'       => $footer,
            'updated_by'   => $createdBy,
            'created_by'   => $createdBy,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public static function update(int $id, string $subject, string $body, ?string $footer, int $updatedBy): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE email_templates SET subject = :subject, body = :body, footer = :footer, updated_by = :updated_by
             WHERE id = :id'
        );
        $stmt->execute([
            'subject'    => $subject,
            'body'       => $body,
            'footer'     => $footer,
            'updated_by' => $updatedBy,
            'id'         => $id,
        ]);
    }

    public static function setActive(int $id, bool $isActive, int $updatedBy): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE email_templates SET is_active = :is_active, updated_by = :updated_by WHERE id = :id'
        );
        $stmt->execute(['is_active' => $isActive ? 1 : 0, 'updated_by' => $updatedBy, 'id' => $id]);
    }

    public static function keyExists(string $templateKey): bool
    {
        $stmt = Database::connection()->prepare('SELECT 1 FROM email_templates WHERE template_key = :key');
        $stmt->execute(['key' => $templateKey]);
        return (bool) $stmt->fetchColumn();
    }
}
