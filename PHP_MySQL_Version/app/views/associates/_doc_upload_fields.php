<?php
// Owner decision D-251 (4 Nov 2026): document kind + its number / reference (checked per kind;
// Aadhaar: last 4 digits only) + file. Shared by the staff page and the associate portal.
// Expects $__docKinds: list of kind keys to offer.
// D-290 (11 Nov 2026): for an ID document the type is chosen (Passport / National ID card / Residence
// permit / Driving licence) and the number is required (5–30 characters). An ID proof and an address
// proof are separate uploads — the same document may be uploaded under each; the app names the files.
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
<label>Type of ID document <small class="muted">(for an ID document)</small>
  <select name="id_doc_type" data-required-if="kind=identity" data-required-if-message="Choose the type of ID document: Passport, National ID card, Residence permit or Driving licence.">
    <option value="">Choose…</option>
    <?php foreach (AssociateKyc::ID_DOC_TYPES as $__t => $__tl): ?><option value="<?= htmlspecialchars($__t) ?>"><?= htmlspecialchars($__tl) ?></option><?php endforeach; ?>
  </select></label>
<label>Document number / reference
  <input type="text" name="reference" maxlength="50" placeholder="e.g. ABCDE1234F (PAN) or the last 4 Aadhaar digits" autocomplete="off" data-required-if="kind=identity" data-required-if-message="Enter the ID document's number (5 to 30 letters, digits, dashes, slashes or spaces)."></label>
<label>File *<input type="file" name="document_file" required accept=".pdf,.jpg,.jpeg,.png"></label>
