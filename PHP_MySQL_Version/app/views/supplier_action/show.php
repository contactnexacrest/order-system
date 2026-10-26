<?php use App\Helpers\Csrf; ?>
<?php // Owner request (2026-10-26): same look as the Quotation/PI details forms — a wide card, a summary bar and numbered sections. ?>
<div class="card">
  <h1>Purchase Order <?= htmlspecialchars((string) $supplierPo['supplier_po_reference']) ?></h1>
  <p class="muted">From <?= htmlspecialchars((string) $companyName) ?> to <?= htmlspecialchars((string) ($supplierPo['supplier_legal_name'] ?? 'you')) ?>. No login needed.</p>
  <div class="intake-summary">
    <span>&#128196; PO <?= htmlspecialchars((string) $supplierPo['supplier_po_reference']) ?></span>
    <span><?= !empty($documents) ? '&#9989; Signed copy received' : '&#9203; Waiting for your signed copy' ?></span>
  </div>

  <fieldset class="intake-step action-step">
    <legend><span class="intake-step-num">1</span> Signed PO / Acknowledgment
      <?php if (!empty($documents)): ?><span class="action-status done">&#10003; Received</span><?php else: ?><span class="action-status todo">Action needed</span><?php endif; ?>
    </legend>
    <ol class="action-howto">
      <li><strong>Download</strong> the purchase order.</li>
      <li><strong>Sign and stamp</strong> it (or write your acknowledgment).</li>
      <li><strong>Upload</strong> it below — you can upload more than one file.</li>
    </ol>
    <?php if ($issuedSupplierPo): ?>
      <p><a class="btn btn-secondary" href="/supplier-action/<?= htmlspecialchars($token) ?>/po-document">&#11015; Download Purchase Order (<?= htmlspecialchars((string) $issuedSupplierPo['document_reference']) ?>)</a></p>
    <?php endif; ?>
    <?php if (!empty($documents)): ?>
      <table class="list">
        <tr><th>Received so far</th><th>Uploaded</th></tr>
        <?php foreach ($documents as $d): ?>
          <tr><td><?= htmlspecialchars((string) $d['original_filename']) ?></td><td><?= htmlspecialchars((string) $d['uploaded_at']) ?></td></tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <form method="post" action="/supplier-action/<?= htmlspecialchars($token) ?>/acknowledgment" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <div class="field-grid">
        <label class="full">Signed PO or acknowledgment *<input type="file" name="document" required></label>
      </div>
      <button type="submit">Upload</button>
    </form>
  </fieldset>
  <p class="muted small">Questions about this purchase order? Reply to the email it came with.</p>
</div>
