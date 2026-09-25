<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/orders/<?= (int) $orderId ?>">&larr; Back to Order</a></p>
  <?php if ($documentId): ?>
    <h1>Send to Buyer — <?= htmlspecialchars($document['document_type_code'] ?? '') ?> <?= htmlspecialchars($document['document_reference'] ?? '—') ?></h1>
  <?php else: ?>
    <h1>Compose Email</h1>
  <?php endif; ?>
  <p class="muted"><?= htmlspecialchars($order['company_legal_name'] ?? '') ?> &nbsp;·&nbsp; <?= htmlspecialchars($order['order_reference'] ?? '') ?></p>

  <form method="get" action="<?= $documentId ? "/orders/{$orderId}/documents/{$documentId}/send" : "/orders/{$orderId}/email/compose" ?>">
    <label>Email Template *
      <select name="template_key" required>
        <option value="">Select a template…</option>
        <?php foreach ($templates as $t): ?>
          <?php if (!$t['is_active']) continue; ?>
          <option value="<?= htmlspecialchars($t['template_key']) ?>" <?= $templateKey === $t['template_key'] ? 'selected' : '' ?>><?= htmlspecialchars($t['subject']) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <button type="submit" class="btn-sm">Load Preview</button>
  </form>

  <?php if ($previewError): ?>
    <div class="alert alert-error"><?= htmlspecialchars($previewError) ?></div>
  <?php elseif ($preview): ?>
    <div class="section">
      <h2>Exact Preview</h2>
      <div class="kv-grid">
        <div><span class="k">To</span><span class="v"><?= htmlspecialchars($preview['recipient_email']) ?></span></div>
        <div><span class="k">Subject</span><span class="v" id="email-preview-subject"><?= htmlspecialchars($preview['subject']) ?></span></div>
      </div>
      <pre id="email-preview-body" style="white-space:pre-wrap; border:1px solid #dcd6c9; padding:8px; font-size:9px;"><?= htmlspecialchars($preview['body']) ?></pre>
      <div class="chat-post-form-row">
        <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('email-preview-subject').textContent).then(()=>this.textContent='Copied!').catch(()=>{})">Copy Subject</button>
        <button type="button" class="btn-sm" onclick="navigator.clipboard.writeText(document.getElementById('email-preview-body').textContent).then(()=>this.textContent='Copied!').catch(()=>{})">Copy Body</button>
      </div>

      <?php if ($documentId): ?>
        <p class="muted" style="margin-top:0.75rem">Attachment: the final watermarked PDF (never DOCX, never a clean copy).</p>
        <form method="post" action="/orders/<?= (int) $orderId ?>/documents/<?= (int) $documentId ?>/send">
          <?= Csrf::field() ?>
          <input type="hidden" name="template_key" value="<?= htmlspecialchars($templateKey) ?>">
          <label>Scheduled send time (leave blank for immediate)
            <input type="datetime-local" name="scheduled_at">
          </label>
          <button type="submit" class="btn-sm btn-success">Submit for Level-2 Approval</button>
        </form>
      <?php else: ?>
        <p class="muted" style="margin-top:0.75rem">This isn't tied to a document, so there's no attachment/approval-queue flow here — copy the subject/body above and send it yourself from your own mail client, addressed to <?= htmlspecialchars($preview['recipient_email']) ?>.</p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
