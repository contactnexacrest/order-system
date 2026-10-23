<?php use App\Helpers\Csrf; use App\Helpers\Dates; ?>
<div class="card page-wide">
  <h1>Order <?= htmlspecialchars($order['order_reference'] ?? ('#' . $order['id'])) ?></h1>
  <p class="muted small"><a href="/client">&larr; Back to My Orders</a> &nbsp;·&nbsp; <a href="/client/orders/<?= (int) $order['id'] ?>/reorder">Reorder This</a></p>

  <?php if (!empty($ocAcknowledgment) && $ocAcknowledgment['acknowledged_at'] === null): ?>
  <div class="section">
    <h2>Order Confirmation — Your Acknowledgement Needed</h2>
    <p>Please review your Order Confirmation below and confirm to proceed.</p>
    <table class="list">
      <tr><th>Order Reference</th><td><?= htmlspecialchars($order['order_reference']) ?></td></tr>
      <tr><th>Product(s)</th><td><?php
        $lines = [];
        foreach ($products as $p) {
            $lines[] = htmlspecialchars($p['description']) . ' (Qty: ' . htmlspecialchars((string) $p['quantity']) . ' ' . htmlspecialchars($p['unit'] ?? '') . ')';
        }
        echo implode('<br>', $lines) ?: '—';
      ?></td></tr>
      <tr><th>Total Order Value</th><td><?= htmlspecialchars($order['currency_code'] ?? '') ?> <?= number_format((float) $totalFobValue, 2) ?></td></tr>
      <tr><th>Incoterm</th><td><?= htmlspecialchars($order['incoterm_code'] ?? '—') ?></td></tr>
      <tr><th>Port of Discharge</th><td><?= htmlspecialchars($order['port_of_discharge_name'] ?? '—') ?></td></tr>
    </table>
    <?php if (!$downloadsHidden): ?><p class="muted small">The full Order Confirmation document is available to download above under Documents.</p><?php endif; ?>
    <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/acknowledge-oc">
      <?= Csrf::field() ?>
      <button type="submit" class="btn-success">I acknowledge and confirm to proceed</button>
    </form>
  </div>
  <?php endif; ?>

  <?php /* External QA report M-02: global + per-client document-download visibility. */ ?>
  <?php if (!$downloadsHidden): ?>
  <div class="section">
    <h2>Documents</h2>
    <p class="muted small">Every document we've finalized for your order so far — download any time.</p>
    <table class="list">
      <tr><th>Document</th><th>Reference</th><th>Date</th><th></th></tr>
      <?php foreach ($documents as $d): ?>
      <tr>
        <td><?= htmlspecialchars($d['document_type_name']) ?></td>
        <td><?= htmlspecialchars($d['document_reference'] ?? '—') ?></td>
        <td><?= htmlspecialchars(Dates::human($d['generated_at'])) ?></td>
        <td><a href="/client/documents/<?= (int) $d['id'] ?>/download">Download PDF</a></td>
      </tr>
      <?php endforeach; ?>
      <?php if (empty($documents)): ?>
        <tr><td colspan="4" class="muted">No documents available yet — check back once your order progresses.</td></tr>
      <?php endif; ?>
    </table>
  </div>
  <?php endif; ?>

  <div class="section">
    <h2>Buyer PO</h2>
    <p class="muted small">Once you've signed your Purchase Order, upload a scanned copy here any time — each upload is kept, so re-uploading a corrected copy never loses the earlier one.</p>
    <?php if (!empty($buyerPoDocuments)): ?>
      <table class="list">
        <tr><th>File</th><th>Uploaded</th></tr>
        <?php foreach ($buyerPoDocuments as $doc): ?>
        <tr>
          <td><?= htmlspecialchars($doc['original_filename']) ?></td>
          <td><?= htmlspecialchars(Dates::human($doc['uploaded_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php else: ?>
      <p class="muted">No Buyer PO copy on file yet.</p>
    <?php endif; ?>
    <?php if ($advanceCleared): ?>
      <p class="muted small">The advance payment has already cleared, so self-service upload is now closed here. Contact your NexaCrest representative if you need to submit an updated copy.</p>
    <?php else: ?>
      <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/buyer-po" enctype="multipart/form-data">
        <?= Csrf::field() ?>
        <label>Upload Buyer PO (signed) <input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.eml,.msg" required></label>
        <button type="submit" class="btn-sm">Upload</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="section" id="order-updates">
    <h2>Order Updates</h2>
    <p class="muted">A running conversation with our team about this order. Post a message any time — you can attach photos or videos too.</p>
    <div class="chat-thread">
      <?php if (empty($comments)): ?>
        <p class="chat-empty">No updates yet.</p>
      <?php endif; ?>
      <?php foreach ($comments as $c): ?>
        <?php $isStaff = $c['author_type'] === 'staff'; ?>
        <div class="chat-bubble-row <?= $isStaff ? 'client' : 'staff' ?>">
          <div class="chat-bubble <?= $isStaff ? 'client' : 'staff' ?>">
            <div class="chat-bubble-meta">
              <span class="who"><?= $isStaff ? 'Our Team' : 'You' ?></span>
              <span><?= htmlspecialchars(Dates::human($c['created_at'])) ?></span>
            </div>
            <?php if ($c['body']): ?><div class="chat-bubble-body"><?= htmlspecialchars($c['body']) ?></div><?php endif; ?>
            <?php if (!empty($c['attachments'])): ?>
              <div class="chat-attachments">
                <?php foreach ($c['attachments'] as $att): ?>
                  <?php
                    $mime = (string) ($att['mime_type'] ?? '');
                    $url = "/client/orders/{$order['id']}/comment-attachments/{$att['file_id']}/download";
                  ?>
                  <?php if (str_starts_with($mime, 'image/')): ?>
                    <a href="<?= $url ?>" target="_blank"><img class="chat-attachment-image" src="<?= $url ?>" alt="<?= htmlspecialchars($att['original_filename']) ?>"></a>
                  <?php elseif (str_starts_with($mime, 'video/')): ?>
                    <video class="chat-attachment-video" controls src="<?= $url ?>"></video>
                  <?php else: ?>
                    <a class="chat-attachment-file" href="<?= $url ?>"><?= htmlspecialchars($att['original_filename']) ?></a>
                  <?php endif; ?>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <form class="chat-post-form" method="post" action="/client/orders/<?= (int) $order['id'] ?>/comments#order-updates" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <textarea name="body" placeholder="Write a message…"></textarea>
      <div class="chat-post-form-row">
        <input type="file" name="attachments[]" multiple accept="image/*,video/*,.pdf">
        <button type="submit" class="btn-sm" data-loading-text="Sending…">Send</button>
      </div>
    </form>
  </div>

  <div class="section">
    <h2>Report a Payment</h2>
    <p class="muted">Made a payment on this order? Let us know the transaction details below — our team will verify it against our bank statement before updating your order.</p>
    <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/report-payment" enctype="multipart/form-data">
      <?= Csrf::field() ?>
      <div class="field-grid">
        <label>Which Payment? *
          <select name="payment_type" required>
            <option value="">Select…</option>
            <option value="advance">Advance Payment</option>
            <option value="balance">Balance Payment</option>
            <option value="freight">Freight Payment</option>
          </select>
        </label>
        <label>Transaction ID / UTR *<input type="text" name="transaction_ref" required></label>
        <label>Amount Paid<input type="text" name="amount" placeholder="e.g. 12500.00"></label>
        <label>Date Paid<input type="date" name="payment_date"></label>
        <label>Your Bank / Payer Details<input type="text" name="payer_bank_details" placeholder="e.g. Sent from HSBC UK, account ending 4321"></label>
        <label>Payment Screenshot / Proof of Payment (optional)<input type="file" name="screenshot" accept=".pdf,.jpg,.jpeg,.png"></label>
      </div>
      <button type="submit">Submit Payment Details</button>
    </form>

    <?php if (!empty($paymentReports)): ?>
      <h3 style="margin-top:20px">Previously Reported</h3>
      <table class="list">
        <tr><th>Payment</th><th>Transaction ID / UTR</th><th>Amount</th><th>Date</th><th>Status</th><th>Attachment</th></tr>
        <?php foreach ($paymentReports as $r): ?>
        <tr>
          <td><?= htmlspecialchars(ucfirst($r['payment_type'])) ?></td>
          <td><?= htmlspecialchars($r['transaction_ref']) ?></td>
          <td><?= $r['amount'] !== null ? number_format((float) $r['amount'], 2) . ' ' . htmlspecialchars($order['currency_code'] ?? '') : '—' ?></td>
          <td><?= htmlspecialchars($r['payment_date'] ?? '—') ?></td>
          <td><?= $r['status'] === 'reviewed' ? 'Reviewed by our team' : 'Received — pending review' ?></td>
          <td>
            <?php if (!empty($r['screenshot_file_id'])): ?>
              <?php $screenshotUrl = '/client/orders/' . (int) $order['id'] . '/payment-reports/' . (int) $r['id'] . '/screenshot'; ?>
              <?php if (str_starts_with((string) $r['screenshot_mime_type'], 'image/')): ?>
                <a href="<?= $screenshotUrl ?>" target="_blank"><img src="<?= $screenshotUrl ?>" alt="Payment screenshot" class="asset-preview" style="max-height:60px;"></a>
              <?php else: ?>
                <a href="<?= $screenshotUrl ?>" target="_blank">View attachment</a>
              <?php endif; ?>
            <?php else: ?>
              <span class="muted small">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

  <?php if ((int) $order['dispute_button_visible_to_client'] === 1): ?>
  <div class="section">
    <h2>Raise a Dispute</h2>
    <p class="muted">If you want to cancel this order and not proceed with us, let us know here — our team will respond within the timeframe set out in your order terms.</p>
    <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/disputes">
      <?= Csrf::field() ?>
      <label>Describe the issue *<textarea name="description" rows="4" required></textarea></label>
      <button type="submit">Raise a Dispute</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="section" id="order-complaints">
    <h2>Report a Process Issue</h2>
    <p class="muted">Something about this order we could do better next time? Tell us here and a NexaCrest team member will reply.</p>
    <?php foreach ($orderComplaints as $c): ?>
      <div class="card-nested" style="margin-bottom:0.75rem">
        <p class="muted small" style="margin:0 0 0.4rem;">Raised <?= htmlspecialchars(Dates::human($c['created_at'])) ?> — <?= htmlspecialchars(ucfirst($c['status'])) ?></p>
        <p style="margin:0 0 0.5rem;"><?= nl2br(htmlspecialchars($c['description'])) ?></p>
        <?php foreach ($c['replies'] as $r): ?>
          <div style="border-top:1px solid var(--line, #EFE6D2);padding:0.4rem 0;">
            <p class="muted small" style="margin:0;"><?= $r['author_type'] === 'staff' ? 'Our Team' : 'You' ?> — <?= htmlspecialchars(Dates::human($r['created_at'])) ?></p>
            <p style="margin:0;"><?= nl2br(htmlspecialchars($r['body'])) ?></p>
          </div>
        <?php endforeach; ?>
        <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/complaints/<?= (int) $c['id'] ?>/replies" style="margin-top:0.5rem">
          <?= Csrf::field() ?>
          <textarea name="body" rows="2" placeholder="Reply…" required></textarea>
          <button type="submit" class="btn-sm">Send</button>
        </form>
      </div>
    <?php endforeach; ?>
    <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/complaints">
      <?= Csrf::field() ?>
      <label>Describe the issue *<textarea name="description" rows="3" required placeholder="e.g. packaging could be sturdier, or a delay in replies"></textarea></label>
      <button type="submit">Report This Issue</button>
    </form>
  </div>

  <div class="section">
    <h2>Order Feedback</h2>
    <p class="muted">Let us know how we did on this order — your rating and comments are visible to our team.</p>
    <form method="post" action="/client/orders/<?= (int) $order['id'] ?>/feedback">
      <?= Csrf::field() ?>
      <label>Rating *
        <select name="rating" required>
          <option value="">Choose a rating</option>
          <?php for ($i = 5; $i >= 1; $i--): ?>
            <option value="<?= $i ?>" <?= (!empty($orderFeedback) && (int) $orderFeedback['rating'] === $i) ? 'selected' : '' ?>><?= str_repeat('★', $i) ?><?= str_repeat('☆', 5 - $i) ?> (<?= $i ?>/5)</option>
          <?php endfor; ?>
        </select>
      </label>
      <label>Comments<textarea name="comments" rows="3" placeholder="Optional"><?= htmlspecialchars($orderFeedback['comments'] ?? '') ?></textarea></label>
      <button type="submit"><?= !empty($orderFeedback) ? 'Update Feedback' : 'Submit Feedback' ?></button>
    </form>
    <?php if (!empty($orderFeedback)): ?>
      <p class="muted small">Last submitted: <?= htmlspecialchars(Dates::human($orderFeedback['submitted_at'])) ?></p>
    <?php endif; ?>
  </div>
</div>
