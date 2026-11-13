<?php
use App\Helpers\Csrf;
// Owner decision D-336: the same screen for Annexure A (as quoted) and Annexure B
// (as shipped, ?which=B — printed with the Commercial Invoice and Packing List).
// Owner decision D-341: one "Save all changes" for the whole page; Annexure B from
// the Commercial Invoice step on; an empty Annexure B starts from a copy of A.
$which = ($which ?? 'A') === 'B' ? 'B' : 'A';
$orderId = (int) $order['id'];
$annexLabel = 'Annexure ' . $which;
$errors = $errors ?? [];
$old = $old ?? null;
$annexureMode = $old['mode'] ?? ($which === 'B' ? ($order['annexure_b_mode'] ?? 'SPEC') : ($order['annexure_mode'] ?? 'SPEC'));
$annexIncluded = $which === 'B' ? !empty($order['include_annexure_b']) : !empty($order['include_annexure_a']);
$whichField = '<input type="hidden" name="which" value="' . $which . '">';
$bAvailable = $bAvailable ?? true;
$bLocked = $which === 'B' && !$bAvailable;
$bStartChoice = !empty($bStartChoice) && !$bLocked;
$imageMaxBytes = (int) ($imageMaxBytes ?? 5242880);
$imageMaxMb = rtrim(rtrim(number_format($imageMaxBytes / 1048576, 1), '0'), '.');
// 90-item backlog #73/#74: once the Annexure document itself has been
// approved/sent, editing the content below silently drifts from what the
// buyer already saw unless the next regeneration+re-approval catches up.
// Warn, don't block — same "tracking aid, not a lock" treatment as every
// other staff-trust gate documented in this codebase (see Stage 9's
// Close Order hint).
$annexaIsLocked = $annexaDocument && in_array($annexaDocument['status'], ['approved', 'sent'], true);
$annexaWarnText = $annexaIsLocked
    ? 'This Annexure was already ' . ($annexaDocument['status'] === 'sent' ? 'sent to the buyer' : 'approved') . '. This change will not update what was already sent — regenerate and get it re-approved first.'
    : '';
/** Builds an onsubmit confirm() attribute, prefixing the Annexure-locked warning (if any) onto an optional existing confirm message. */
$annexureConfirmAttr = static function (string $extraMessage = '') use ($annexaWarnText): string {
    $message = trim($annexaWarnText . ' ' . $extraMessage) . ($annexaWarnText !== '' ? ' Continue?' : '');
    return $message === '' ? '' : 'onsubmit="return confirm(' . htmlspecialchars(json_encode($message), ENT_QUOTES) . ');"';
};
/** D-341: the message beside a field of a refused "Save all changes". */
$fieldErr = static function (int $entryId, string $field) use ($errors): string {
    $message = $errors['entries'][$entryId][$field] ?? null;
    return $message === null ? '' : '<small class="reason-error form-field-error">' . htmlspecialchars($message) . '</small>';
};
$invalid = static fn (int $entryId, string $field): string => isset($errors['entries'][$entryId][$field]) ? ' field-invalid' : '';
/** The value shown in an entry's field: what was typed in a refused save, else the saved value. */
$val = static function (array $p, string $field) use ($old): string {
    $entryId = (int) $p['id'];
    return htmlspecialchars((string) ($old['entries'][$entryId][$field] ?? ($p[$field] ?? '')));
};
$termsHtml = $old !== null && $old['content_html'] !== null ? $old['content_html'] : ($terms['content_html'] ?? '');
?>
<div class="card page-wide">
  <div class="page-header-row annexure-head">
    <h1><?= $annexLabel ?> — <?= htmlspecialchars($order['order_reference'] ?? ('#' . $orderId)) ?></h1>
    <?php if ($which === 'A'): ?>
      <?php if ($bAvailable): ?>
        <a class="btn-sm" href="/orders/<?= $orderId ?>/annexure?which=B">Annexure B (as shipped) &rsaquo;</a>
      <?php else: ?>
        <span class="muted small annexure-b-not-yet"><?= htmlspecialchars(\App\Services\AnnexureChoice::B_NOT_YET_NOTE) ?></span>
      <?php endif; ?>
    <?php else: ?>
      <a class="btn-sm" href="/orders/<?= $orderId ?>/annexure">&lsaquo; Annexure A (as quoted)</a>
    <?php endif; ?>
  </div>

  <?php if ($bLocked): ?>
  <?php // D-341: Annexure B before the Commercial Invoice step — nothing to edit yet. ?>
  <p class="muted annexure-b-not-yet"><?= htmlspecialchars(\App\Services\AnnexureChoice::B_NOT_YET_NOTE) ?></p>
  <p class="muted small"><a href="/orders/<?= $orderId ?>/annexure">&lsaquo; Back to Annexure A</a> &middot; <a href="/orders/<?= $orderId ?>">&larr; Back to Order</a></p>
</div>
  <?php return; endif; ?>

  <?php if ($bStartChoice): ?>
  <?php // D-341: first visit of an empty Annexure B — start it from a copy of Annexure A. ?>
  <div class="annexure-b-start">
    <p>Annexure B shows the goods as shipped. It is printed with the Commercial Invoice and Packing List. Most orders ship as quoted — start from a copy of Annexure A (products, images, Additional Terms and content), then change what is different.</p>
    <form method="post" action="/orders/<?= $orderId ?>/annexure/copy-from-a">
      <?= Csrf::field() ?>
      <button type="submit" class="annexure-b-start-btn">Start Annexure B from a copy of Annexure A</button>
    </form>
    <p class="small"><a href="/orders/<?= $orderId ?>/annexure?which=B&amp;start=empty">Start empty instead</a></p>
  </div>
  <p class="muted small"><a href="/orders/<?= $orderId ?>">&larr; Back to Order</a></p>
</div>
  <?php return; endif; ?>

  <?php if ($which === 'B'): ?>
    <form method="post" action="/orders/<?= $orderId ?>/annexure/copy-from-a" class="annexure-copy-form"<?= !empty($bHasContent) ? ' data-confirm="Replace Annexure B with a copy of Annexure A?"' : '' ?>>
      <?= Csrf::field() ?>
      <button type="submit" class="btn-sm">Copy everything from Annexure A</button>
      <span class="muted small">Starts Annexure B from Annexure A — products, images and terms — then change what is different. Printed with the Commercial Invoice and Packing List.</span>
    </form>
  <?php endif; ?>
  <p class="muted small"><a href="/orders/<?= $orderId ?>">&larr; Back to Order</a></p>
  <?php if ($which === 'B'): ?>
  <p class="muted">Product technical specifications (dimensions, finish, components, technical notes) and images, and/or free-form Additional Terms text, as shipped — printed with the Commercial Invoice and Packing List as an attached, integral document whenever "Include Annexure B" is on for this order (or Annexure B is chosen in the Commercial Invoice step).</p>
  <?php else: ?>
  <p class="muted">Product technical specifications (dimensions, finish, components, technical notes) and images, and/or free-form Additional Terms text, referenced from the Quotation/PI/OC/Buyer PO as an attached, integral document whenever "Include Annexure A" is on for this order.</p>
  <?php endif; ?>

  <?php if (!empty($staleAnnexa)): ?>
    <?php // D-341: Annexure A changed after its approved / sent document was generated. ?>
    <div class="alert alert-warning annexure-new-revision">
      <span>You changed Annexure A after Rev.<?= (int) $staleAnnexa['revision_number'] ?> was approved — generate the new revision, then approve and send it.</span>
      <?php if (!empty($canGenerateDocuments)): ?>
      <form method="post" action="/orders/<?= $orderId ?>/documents/generate" class="annexure-new-revision-form"<?= !empty($annexaRegenerationRisk) ? ' data-confirm="' . htmlspecialchars('Regenerating ANNEXA may leave ' . implode(', ', $annexaRegenerationRisk) . ' out of step with the new data — those documents were generated before this change. Continue?') . '"' : '' ?>>
        <?= Csrf::field() ?>
        <input type="hidden" name="document_type" value="ANNEXA">
        <input type="hidden" name="generate_pdf" value="1">
        <button type="submit" class="btn-sm" data-loading-text="Generating…">Generate the new revision &rsaquo;</button>
      </form>
      <?php endif; ?>
    </div>
  <?php elseif ($annexaIsLocked): ?>
    <div class="alert alert-warning">
      ⚠ This order's Annexure A document has already been <?= htmlspecialchars($annexaDocument['status']) ?> (ref. <?= htmlspecialchars((string) $annexaDocument['document_reference']) ?>). Editing the Product Entries or Additional Terms below does not change that document — generate a new revision and get it re-approved before sending the Annexure to the buyer again.
    </div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error">Nothing was saved — correct the fields marked below, then press "Save all changes" again.<?= isset($errors['mode']) ? ' ' . htmlspecialchars($errors['mode']) : '' ?></div>
  <?php endif; ?>

  <div class="section">
    <form method="post" action="/orders/<?= $orderId ?>/annexure/toggle">
      <?= Csrf::field() ?><?= $whichField ?>
      <label><input type="checkbox" name="include_annexure_<?= strtolower($which) ?>" value="1" <?= $annexIncluded ? 'checked' : '' ?> style="display:inline-block;width:auto;" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()"> Include <?= $annexLabel ?> on this order's documents</label>
    </form>
  </div>

  <?php // D-341: one form for the whole page — the content mode, every product entry, any new image per entry and the Additional Terms. ?>
  <form method="post" action="/orders/<?= $orderId ?>/annexure/save-all" enctype="multipart/form-data" id="annexure-save-all" data-unsaved-guard <?= $annexureConfirmAttr() ?>>
    <?= Csrf::field() ?><?= $whichField ?>
    <div class="annexure-save-bar">
      <button type="submit">Save all changes</button>
      <span class="muted small">Saves everything on this page at once — content, product entries, new images and Additional Terms.</span>
    </div>

    <div class="section">
      <label><?= $annexLabel ?> content
        <select name="mode" id="annexure-mode-select" onchange="annexureApplyMode(this.value);">
          <option value="SPEC" <?= $annexureMode === 'SPEC' ? 'selected' : '' ?>>Product Specification (current view)</option>
          <option value="TERMS" <?= $annexureMode === 'TERMS' ? 'selected' : '' ?>>Additional Terms</option>
          <option value="BOTH" <?= $annexureMode === 'BOTH' ? 'selected' : '' ?>>Both</option>
        </select>
      </label>
      <p class="muted small">Product Specification is the structured product/dimensions table below. Additional Terms is free-form text (clauses, notes, anything agreed with the client) entered as rich text, including pasted images. Both prints Product Specification first, then Additional Terms. Switching this does not delete either section's saved content.</p>
    </div>

    <div id="annexure-spec-section" class="section">
      <h2>Product Entries</h2>
      <?php foreach ($products as $p): $pid = (int) $p['id']; ?>
        <div class="card-nested annexure-entry" id="annexure-entry-<?= $pid ?>">
          <div class="field-grid">
            <label>Name *<input type="text" name="entries[<?= $pid ?>][name]" value="<?= $val($p, 'name') ?>" placeholder="e.g. Granite Countertop Slab" required class="<?= trim($invalid($pid, 'name')) ?>"><?= $fieldErr($pid, 'name') ?></label>
            <label>Dimensions<input type="text" name="entries[<?= $pid ?>][dimensions]" value="<?= $val($p, 'dimensions') ?>" placeholder="e.g. 210 x 528 cm"></label>
            <label>Finish<input type="text" name="entries[<?= $pid ?>][finish]" value="<?= $val($p, 'finish') ?>" placeholder="e.g. Mirror Polished"></label>
            <label class="full">Description<textarea name="entries[<?= $pid ?>][description]" rows="2" placeholder="e.g. Premium grade polished slab for kitchen countertop use"><?= $val($p, 'description') ?></textarea></label>
            <label class="full">Components<textarea name="entries[<?= $pid ?>][components]" rows="2" placeholder="e.g. 1. Left frame&#10;2. Right frame&#10;3. Base"><?= $val($p, 'components') ?></textarea></label>
            <label class="full">Technical Notes<textarea name="entries[<?= $pid ?>][technical_notes]" rows="2" placeholder="e.g. Store flat, avoid moisture exposure"><?= $val($p, 'technical_notes') ?></textarea></label>
          </div>

          <div class="section">
            <strong>Images</strong>
            <div class="btn-row">
              <?php foreach ($p['images'] as $img): ?>
                <div class="thumb-card">
                  <img src="/file-store/<?= (int) $img['file_id'] ?>/download" alt="<?= htmlspecialchars($img['original_filename']) ?>" class="asset-preview">
                  <span class="muted small"><?= htmlspecialchars($img['original_filename']) ?></span>
                  <button type="submit" form="annexure-image-remove-<?= (int) $img['id'] ?>" class="btn-sm btn-danger" title="Remove this image" aria-label="Remove this image">&times;</button>
                </div>
              <?php endforeach; ?>
              <?php if (empty($p['images'])): ?><span class="muted small">No images uploaded yet.</span><?php endif; ?>
            </div>
            <label class="annexure-image-pick">Add image <small class="muted">(jpg, png or webp, up to <?= $imageMaxMb ?> MB — saved with "Save all changes")</small><input type="file" name="images[<?= $pid ?>]" accept=".jpg,.jpeg,.png,.webp" data-max-bytes="<?= $imageMaxBytes ?>" class="<?= trim($invalid($pid, 'image')) ?>"><?= $fieldErr($pid, 'image') ?></label>
          </div>
          <button type="submit" form="annexure-entry-remove-<?= $pid ?>" class="btn-sm btn-danger">Remove Entry</button>
        </div>
      <?php endforeach; ?>
      <?php if (empty($products)): ?>
        <p class="muted">No product entries yet — add one below.</p>
      <?php endif; ?>
    </div>

    <div id="annexure-terms-section" class="section">
      <h2>Additional Terms</h2>
      <p class="muted small">Free-form text — special terms, clauses, or anything else agreed with the client that doesn't fit the Product Specification table. Supports basic formatting and pasted/inserted images. Video/audio can't be embedded here: a generated PDF or Word document can't play media, so only images are supported.</p>
      <div class="wysiwyg-toolbar" role="toolbar" aria-label="Text formatting">
        <button type="button" class="wysiwyg-btn" data-cmd="bold" title="Bold"><strong>B</strong></button>
        <button type="button" class="wysiwyg-btn" data-cmd="italic" title="Italic"><em>I</em></button>
        <button type="button" class="wysiwyg-btn" data-cmd="underline" title="Underline"><u>U</u></button>
        <button type="button" class="wysiwyg-btn" data-cmd="strikeThrough" title="Strikethrough"><s>S</s></button>
        <span class="wysiwyg-sep"></span>
        <button type="button" class="wysiwyg-btn" data-block="H3" title="Heading">H3</button>
        <button type="button" class="wysiwyg-btn" data-block="H4" title="Subheading">H4</button>
        <button type="button" class="wysiwyg-btn" data-block="BLOCKQUOTE" title="Quote">&ldquo;&rdquo;</button>
        <span class="wysiwyg-sep"></span>
        <button type="button" class="wysiwyg-btn" data-cmd="insertUnorderedList" title="Bullet list">&bull; List</button>
        <button type="button" class="wysiwyg-btn" data-cmd="insertOrderedList" title="Numbered list">1. List</button>
        <span class="wysiwyg-sep"></span>
        <button type="button" class="wysiwyg-btn" id="annexure-terms-insert-image" title="Insert image">Image</button>
        <button type="button" class="wysiwyg-btn" id="annexure-terms-insert-link" title="Insert link">Link</button>
        <span class="wysiwyg-sep"></span>
        <button type="button" class="wysiwyg-btn" data-cmd="removeFormat" title="Clear formatting">Clear</button>
        <button type="button" class="wysiwyg-btn" data-cmd="undo" title="Undo">&#8634;</button>
        <button type="button" class="wysiwyg-btn" data-cmd="redo" title="Redo">&#8635;</button>
      </div>
      <input type="file" id="annexure-terms-image-input" accept="image/png,image/jpeg,image/webp,image/gif" style="display:none;" aria-label="Terms image">
      <div id="annexure-terms-surface" class="wysiwyg-surface" contenteditable="true"><?= $termsHtml ?></div>
      <textarea name="content_html" id="annexure-terms-hidden" style="display:none;" placeholder="Type the text" aria-label="Terms text"></textarea>
    </div>

    <div class="annexure-save-bar">
      <button type="submit">Save all changes</button>
    </div>
  </form>

  <?php // D-341: these act at once (with a confirm) — outside the big form; the buttons above reach them through form="…". ?>
  <div class="annexure-side-forms" hidden>
    <?php foreach ($products as $p): ?>
      <form method="post" action="/orders/<?= $orderId ?>/annexure/products/<?= (int) $p['id'] ?>/delete" id="annexure-entry-remove-<?= (int) $p['id'] ?>" <?= $annexureConfirmAttr('Remove this product entry and its images?') ?>>
        <?= Csrf::field() ?>
      </form>
      <?php foreach ($p['images'] as $img): ?>
        <form method="post" action="/orders/<?= $orderId ?>/annexure/images/<?= (int) $img['id'] ?>/remove" id="annexure-image-remove-<?= (int) $img['id'] ?>" <?= $annexureConfirmAttr('Remove this image from ' . $annexLabel . '?') ?>>
          <?= Csrf::field() ?>
        </form>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>

  <div class="section" id="annexure-add-section">
    <h2>Add Product Entry</h2>
    <form method="post" action="/orders/<?= $orderId ?>/annexure/products" enctype="multipart/form-data" <?= $annexureConfirmAttr() ?>>
      <?= Csrf::field() ?><?= $whichField ?>
      <div class="field-grid">
        <label>Name *<input type="text" name="name" placeholder="e.g. Granite Countertop Slab" required></label>
        <label>Dimensions<input type="text" name="dimensions" placeholder="e.g. 210 x 528 cm" maxlength="150"></label>
        <label>Finish<input type="text" name="finish" placeholder="e.g. Mirror Polished" maxlength="150"></label>
        <label>Image <small class="muted">(optional — can also be added after saving)</small><input type="file" name="image" accept=".jpg,.jpeg,.png,.webp" data-max-bytes="<?= $imageMaxBytes ?>"></label>
        <label class="full">Description<textarea name="description" rows="2" placeholder="e.g. Premium grade polished slab for kitchen countertop use"></textarea></label>
        <label class="full">Components<textarea name="components" rows="2" placeholder="e.g. 1. Left frame&#10;2. Right frame&#10;3. Base"></textarea></label>
        <label class="full">Technical Notes<textarea name="technical_notes" rows="2" placeholder="e.g. Store flat, avoid moisture exposure"></textarea></label>
      </div>
      <button type="submit">Add Product Entry</button>
    </form>
  </div>
</div>

<script>
(function () {
  var mode = <?= json_encode($annexureMode) ?>;
  var specSection = document.getElementById('annexure-spec-section');
  var addSection = document.getElementById('annexure-add-section');
  var termsSection = document.getElementById('annexure-terms-section');

  window.annexureApplyMode = function (m) {
    specSection.style.display = (m === 'TERMS') ? 'none' : '';
    addSection.style.display = (m === 'TERMS') ? 'none' : '';
    termsSection.style.display = (m === 'SPEC') ? 'none' : '';
  };
  annexureApplyMode(mode);

  var surface = document.getElementById('annexure-terms-surface');
  var hidden = document.getElementById('annexure-terms-hidden');
  var saveForm = document.getElementById('annexure-save-all');
  function syncHidden() { hidden.value = surface.innerHTML; }
  surface.addEventListener('input', syncHidden);
  // Capture phase: the terms are in the hidden field before the form is checked and sent.
  saveForm.addEventListener('submit', syncHidden, true);

  document.querySelectorAll('.wysiwyg-btn[data-cmd]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      surface.focus();
      document.execCommand(btn.getAttribute('data-cmd'), false, null);
      syncHidden();
    });
  });
  document.querySelectorAll('.wysiwyg-btn[data-block]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      surface.focus();
      document.execCommand('formatBlock', false, btn.getAttribute('data-block'));
      syncHidden();
    });
  });

  var imageInput = document.getElementById('annexure-terms-image-input');
  document.getElementById('annexure-terms-insert-image').addEventListener('click', function () {
    imageInput.value = '';
    imageInput.click();
  });
  imageInput.addEventListener('change', function (e) {
    // Not a field of its own: the picture goes into the text, so the change guard ignores this input.
    e.stopPropagation();
    var file = imageInput.files[0];
    if (!file) { return; }
    if (file.size > 3 * 1024 * 1024) {
      alert('Image is larger than 3 MB — please use a smaller image.');
      return;
    }
    var reader = new FileReader();
    reader.onload = function () {
      surface.focus();
      document.execCommand('insertImage', false, reader.result);
      syncHidden();
    };
    reader.readAsDataURL(file);
  });

  document.getElementById('annexure-terms-insert-link').addEventListener('click', function () {
    var url = prompt('Link URL (https://...)');
    if (!url) { return; }
    surface.focus();
    document.execCommand('createLink', false, url);
    syncHidden();
  });

  syncHidden();
})();
</script>
