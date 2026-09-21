<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Helpers\Flash;
use App\Helpers\ReferenceContent;
use App\Helpers\View;
use App\Repositories\AuditLogRepository;
use App\Repositories\InternalReferenceDocRepository;
use App\Services\AuthService;

/**
 * The Internal Reference Library — any authenticated staff member can
 * view (SOPs, the Stage Gate Reference, the Cross-Verification Checklist,
 * and the Wall Reference are things every staff member should be able to
 * look up), editing is gated the same as Company Settings since this is
 * effectively business-rule configuration content, not per-user data.
 */
final class ReferenceDocController
{
    public function index(array $params): void
    {
        View::render('reference_docs/index', [
            'docs' => InternalReferenceDocRepository::all(),
        ], 'layout/base');
    }

    public function show(array $params): void
    {
        $code = strtoupper((string) ($params['code'] ?? ''));
        $doc = InternalReferenceDocRepository::findByCode($code);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }

        $content = $doc['content'] ?? '';
        if ($code === 'WALLREF' && $content !== '') {
            $content = ReferenceContent::substitutePlaceholders($content);
        }

        View::render('reference_docs/show', [
            'doc' => $doc,
            'contentHtml' => $content !== '' ? ReferenceContent::toHtml($content) : null,
        ], 'layout/base');
    }

    public function edit(array $params): void
    {
        $code = strtoupper((string) ($params['code'] ?? ''));
        $doc = InternalReferenceDocRepository::findByCode($code);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }
        View::render('reference_docs/edit', ['doc' => $doc], 'layout/base');
    }

    public function update(array $params): void
    {
        $code = strtoupper((string) ($params['code'] ?? ''));
        $doc = InternalReferenceDocRepository::findByCode($code);
        if (!$doc) {
            http_response_code(404);
            echo 'Reference document not found.';
            return;
        }

        $content = trim((string) ($_POST['content'] ?? ''));
        $user = AuthService::currentUser();
        InternalReferenceDocRepository::upsert((int) $doc['document_type_id'], $content, (int) $user['id']);
        AuditLogRepository::log((int) $user['id'], 'REFERENCE_DOC_UPDATED', 'internal_reference_docs', (int) $doc['document_type_id'], 'content', null, null, "Updated {$code}");

        Flash::set('success', "{$doc['name']} updated.");
        header("Location: /reference-docs/{$code}");
    }
}
