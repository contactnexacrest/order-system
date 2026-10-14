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

    /**
     * 90-item backlog #37: for the system/transactional emails (2FA code,
     * password resets, portal invite, correction/intake links — see
     * seed.sql's own comment block for the full key list) that used to
     * have their subject/body hardcoded inline rather than pulled from
     * this table. Unlike buildPreview() in EmailDispatchService (which
     * throws on an unknown/inactive template, since a buyer document-send
     * is always a deliberate staff action with a visible error path), a
     * template row going missing or getting deactivated here must never
     * stop a security-critical email like a password reset from going
     * out — so this falls back to the exact original hardcoded wording
     * (also token-substituted, for consistency) instead of throwing.
     *
     * @param array<string,string> $tokens
     * @return array{subject:string, body:string}
     */
    public static function renderOrDefault(string $templateKey, array $tokens, string $defaultSubject, string $defaultBody): array
    {
        $template = self::find($templateKey);
        if ($template && (int) $template['is_active'] === 1) {
            $body = strtr($template['body'], $tokens);
            if (!empty($template['footer'])) {
                $body .= "\n\n" . strtr($template['footer'], $tokens);
            }
            return ['subject' => strtr($template['subject'], $tokens), 'body' => $body];
        }
        return ['subject' => strtr($defaultSubject, $tokens), 'body' => strtr($defaultBody, $tokens)];
    }
}
