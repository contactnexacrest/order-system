<div class="card page-wide">
  <h1>Gallery</h1>
  <p class="muted">Browse screenshots and reference images straight off the server — a visual knowledgebase, view-only. Add more by uploading files into the <code>docs/gallery</code> folder; new folders become categories automatically.</p>

  <nav class="gallery-breadcrumb" aria-label="Breadcrumb">
    <a href="/gallery">Gallery</a>
    <?php foreach ($crumbs as $crumb): ?>
      <span aria-hidden="true"> / </span><a href="/gallery?p=<?= urlencode($crumb['path']) ?>"><?= htmlspecialchars($crumb['name']) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($parentPath !== null): ?>
    <p><a href="/gallery<?= $parentPath === '' ? '' : '?p=' . urlencode($parentPath) ?>">&larr; Up one level</a></p>
  <?php endif; ?>

  <?php if ($isEmpty): ?>
    <p class="muted">This folder is empty.</p>
  <?php endif; ?>

  <?php if (!empty($folders)): ?>
    <div class="gallery-folders">
      <?php foreach ($folders as $folder): ?>
        <a class="gallery-folder" href="/gallery?p=<?= urlencode($folder['path']) ?>">
          <span class="gallery-folder-ico" aria-hidden="true">&#128193;</span>
          <span><?= htmlspecialchars($folder['name']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php $images = array_filter($files, static fn(array $f): bool => $f['isImage']); ?>
  <?php if (!empty($images)): ?>
    <div class="gallery-grid">
      <?php foreach ($images as $image): ?>
        <a class="gallery-thumb" href="/gallery/file?p=<?= urlencode($image['path']) ?>" target="_blank" rel="noopener">
          <img src="/gallery/file?p=<?= urlencode($image['path']) ?>" alt="<?= htmlspecialchars($image['name']) ?>" loading="lazy">
          <span class="gallery-thumb-name"><?= htmlspecialchars($image['name']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php $otherFiles = array_filter($files, static fn(array $f): bool => !$f['isImage']); ?>
  <?php if (!empty($otherFiles)): ?>
    <ul class="gallery-file-list">
      <?php foreach ($otherFiles as $file): ?>
        <li>
          <a href="/gallery/file?p=<?= urlencode($file['path']) ?>"><?= htmlspecialchars($file['name']) ?></a>
          <span class="muted">(<?= number_format($file['sizeBytes'] / 1024, 1) ?> KB)</span>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
