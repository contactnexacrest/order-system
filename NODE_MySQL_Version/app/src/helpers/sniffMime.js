'use strict';

// Minimal content-sniffing helper, standing in for PHP's finfo_file()
// (magic-byte detection of the actual file content — never trusts the
// client-supplied Content-Type/extension). Only recognizes the three types
// AssetController allows: PNG, JPEG, SVG.

function sniff(buffer) {
  if (buffer.length >= 8 && buffer[0] === 0x89 && buffer[1] === 0x50 && buffer[2] === 0x4e && buffer[3] === 0x47) {
    return 'image/png';
  }
  if (buffer.length >= 3 && buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff) {
    return 'image/jpeg';
  }
  const head = buffer.slice(0, 512).toString('utf8').replace(/^﻿/, '').trimStart().toLowerCase();
  if (head.startsWith('<?xml') || head.startsWith('<svg')) {
    // If it declares XML first, the <svg> root must still show up shortly after.
    if (head.startsWith('<svg') || head.includes('<svg')) {
      return 'image/svg+xml';
    }
  }
  return null;
}

module.exports = { sniff };
