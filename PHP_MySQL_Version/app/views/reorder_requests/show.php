<?php use App\Helpers\Csrf; ?>
<div class="card page-wide">
  <p class="muted small"><a href="/reorder-requests">&larr; Back to Reorder Requests</a></p>
  <h1>Reorder Request — <?= htmlspecialchars($request['company_legal_name']) ?></h1>
  <p class="muted">
    From <a href="/orders/<?= (int) $request['source_order_id'] ?>"><?= htmlspecialchars($request['source_order_reference']) ?></a>,
    submitted <?= htmlspecialchars((string) $request['submitted_at']) ?>.
    <?php if ($request['notes']): ?><br>Client's note: <?= htmlspecialchars($request['notes']) ?><?php endif; ?>
  </p>

  <?php if ($request['status'] !== 'pending'): ?>
    <p class="notice">This request was already <?= htmlspecialchars($request['status']) ?> — nothing more to do here.</p>
  <?php else: ?>
    <form method="post" action="/reorder-requests/<?= (int) $request['id'] ?>/approve">
      <?= Csrf::field() ?>
      <div class="section">
        <h2>Product Lines <small class="muted">(confirm HS code and unit price for each before approving)</small></h2>
        <?php foreach ($lines as $i => $l): ?>
          <div class="card-nested">
            <label>Description *<input type="text" name="description[<?= $i ?>]" value="<?= htmlspecialchars($l['description']) ?>"></label>
            <label>Dimensions<input type="text" name="dimensions[<?= $i ?>]" value="<?= htmlspecialchars($l['dimensions'] ?? '') ?>"></label>
            <label>Finish<input type="text" name="finish[<?= $i ?>]" value="<?= htmlspecialchars($l['finish'] ?? '') ?>"></label>
            <label>Qty<input type="text" name="quantity[<?= $i ?>]" value="<?= htmlspecialchars((string) ($l['quantity'] ?? '')) ?>"></label>
            <label><input type="checkbox" name="quantity_is_tbc[<?= $i ?>]" value="1" style="display:inline-block;width:auto;" <?= $l['quantity_is_tbc'] ? 'checked' : '' ?>> Qty TBC</label>
            <label>Unit<input type="text" name="unit[<?= $i ?>]" value="<?= htmlspecialchars($l['unit'] ?? '') ?>"></label>
            <label>Unit Price *<input type="text" name="unit_price[<?= $i ?>]" value="<?= htmlspecialchars((string) ($l['unit_price'] ?? '')) ?>"></label>
            <label>HS Code *<input type="text" name="hs_code[<?= $i ?>]" value="<?= htmlspecialchars($l['hs_code'] ?? '') ?>" list="hs_code_list" required></label>
          </div>
        <?php endforeach; ?>
        <datalist id="hs_code_list">
          <?php foreach ($hsCodes as $hc): ?>
            <option value="<?= htmlspecialchars($hc['code']) ?>"><?= htmlspecialchars($hc['description'] ?? '') ?></option>
          <?php endforeach; ?>
        </datalist>
      </div>
      <button type="submit" class="btn-success" onclick="return confirm('Create a new order from these lines?');">Approve &amp; Create Order</button>
    </form>

    <div class="section">
      <h2>Reject Instead</h2>
      <form method="post" action="/reorder-requests/<?= (int) $request['id'] ?>/reject" onsubmit="return confirm('Reject this reorder request?');">
        <?= Csrf::field() ?>
        <textarea name="reason" rows="2" placeholder="Reason (required)" required minlength="10"></textarea>
        <button type="submit" class="btn-danger btn-sm">Reject</button>
      </form>
    </div>
  <?php endif; ?>
</div>
