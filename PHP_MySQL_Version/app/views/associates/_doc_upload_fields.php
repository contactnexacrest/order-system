<?php
// Owner decision D-251 (4 Nov 2026): document kind + its number / reference (checked per kind;
// Aadhaar: last 4 digits only) + file. Shared by the staff page and the associate portal.
// Expects $__docKinds: list of kind keys to offer.
use App\Repositories\AssociateDocumentRepository;
use App\Services\AssociateKyc;
?>
<label>Document *
  <select name="kind" required data-pattern-target="input[name=reference]">
    <option value="">Choose…</option>
    <?php foreach ($__docKinds as $__k): [$__p, $__ph, $__t] = AssociateKyc::REFERENCE_RULES[$__k] ?? AssociateKyc::OTHER_RULE; ?>
      <option value="<?= htmlspecialchars($__k) ?>" data-pattern="<?= htmlspecialchars($__p) ?>" data-placeholder="<?= htmlspecialchars($__ph) ?>" data-title="<?= htmlspecialchars($__t) ?>"><?= htmlspecialchars(AssociateDocumentRepository::KINDS[$__k]) ?></option>
    <?php endforeach; ?>
  </select></label>
<label>Document number / reference
  <input type="text" name="reference" maxlength="50" placeholder="e.g. ABCDE1234F (PAN) or the last 4 Aadhaar digits" autocomplete="off"></label>
<label>File *<input type="file" name="document_file" required accept=".pdf,.jpg,.jpeg,.png"></label>
