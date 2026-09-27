<div class="card page-wide">
  <h1>Data Export / Migration</h1>
  <p class="muted">For a fresh reinstall of this application (a new server, a major update, or a rebuild after DB changes) without losing any of the real, live records already in <strong><?= htmlspecialchars((string) $dbName) ?></strong>. Every download here is a real dump taken right now, not a sample — treat both files as sensitive: they can contain client, order and staff data (the data file also carries staff login password hashes).</p>

  <div class="section">
    <h2>1. Structure snapshot</h2>
    <p class="muted">The exact table structure as it exists right now — every column, type, and index, with no data inside. Use it as a reference before importing the data file into a newly installed copy of the app: compare it against the new install's own <code>docs/schema.sql</code> to confirm nothing the old data depends on (a column's name, type, or size) was removed or narrowed — only additions are safe to assume automatically.</p>
    <a href="/admin/data-export/schema" class="btn">Download Structure (.sql)</a>
  </div>

  <div class="section">
    <h2>2. Full data export</h2>
    <p class="muted">Every row in every table, right now — clients, orders, documents, financial records, users, all of it. This is the file that actually restores your historical records: import it into a freshly installed copy of the app (after that install has already created its own current-version schema) and every record comes back exactly as it was.</p>
    <a href="/admin/data-export/data" class="btn">Download Full Data (.sql)</a>
  </div>

  <div class="section">
    <h2>How to restore into a fresh installation</h2>
    <ol>
      <li>Set up the new installation normally (new code, run its own <code>docs/schema.sql</code> to create empty, current-version tables) — do not import the structure file over it.</li>
      <li>If the new code's schema differs from the structure file downloaded above, confirm the difference is additive only (new columns/tables), per the note in section 1.</li>
      <li>Import the data file into the new installation: <code>mysql -u &lt;user&gt; -p &lt;database&gt; &lt; nexacrest-data-*.sql</code></li>
    </ol>
    <p class="muted">Full walkthrough: <a href="/sop/18-data-export-migration">SOP — Data Export / Migration</a>.</p>
  </div>
</div>
