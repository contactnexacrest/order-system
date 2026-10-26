<?php use App\Helpers\Csrf; ?>
<div class="card">
  <h1>Purchase Order <?= htmlspecialchars((string) $supplierPo['supplier_po_reference']) ?></h1>
  <p class="muted">From <?= htmlspecialchars((string) $companyName) ?> to <?= htmlspecialchars((string) ($supplierPo['supplier_legal_name'] ?? 'you')) ?></p>

  <div class="section">
    <h2>Signed PO / Acknowledgment</h2>
    <p>Please upload the purchase order signed and stamped by you, or your written acknowledgment of it. You can upload more than one file.</p>
    <?php if (!empty($documents)): ?>
      <p class="small"><strong>Received so far:</strong></p>
      <ul class="small">
        <?php foreach ($documents as $d): ?>
          <li><?= htmlspecialchars((string) $d['original_filename']) ?> — <?= htmlspecialchars((string) $d['uploaded_at']) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <form method="post" action="/supplier-action/<?= htmlspecialchars($token) ?>/acknowledgment" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <label>Signed PO or acknowledgment <input type="file" name="document" required></label>
      <button type="submit" class="btn-sm">Upload</button>
    </form>
  </div>
</div>
