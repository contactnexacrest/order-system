'use strict';
// Shared settings for the tutorial video pipeline. Override with environment variables.
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const WORK = process.env.TUT_WORK || path.join(ROOT, 'tutorials', 'out');

module.exports = {
  ROOT,
  BASE_URL: process.env.TUT_BASE_URL || 'http://127.0.0.1:8080',
  // Piper voice (owner decision: US English, lessac medium).
  VOICE: process.env.TUT_VOICE || path.join(WORK, 'voices', 'en_US-lessac-medium.onnx'),
  WORK,                                   // cache, temp files, raw webm
  OUT: process.env.TUT_OUT || path.join(WORK, 'videos'), // finished MP4s
  CHROMIUM: process.env.TUT_CHROMIUM || '/opt/pw-browsers/chromium',
  DB: {
    user: process.env.TUT_DB_USER || 'nexacrest_app',
    password: process.env.TUT_DB_PASSWORD || 'DevPass123!',
    database: process.env.TUT_DB_NAME || 'nexacrest_php_dev',
  },
  PHP_APP: path.join(ROOT, 'PHP_MySQL_Version', 'app'),
  FONTS: path.join(ROOT, 'PHP_MySQL_Version', 'public_html', 'assets', 'fonts'),
  LOGO: path.join(ROOT, 'PHP_MySQL_Version', 'public_html', 'assets', 'img', 'logo.jpg'),
  // Video geometry: the recording captures CSS pixels, so the viewport is 1920x1080 and the
  // page body is zoomed 1.5x: the app lays out as on a 1280x720 screen, sharp at full HD.
  VIEW: { width: 1920, height: 1080, zoom: 1.5 },
  VIDEO: { width: 1920, height: 1080 },
  MAX_SECONDS: 15 * 60,                   // hard limit (owner): split into parts if longer
  TARGET_SECONDS: [8 * 60, 12 * 60],      // aim
  PAUSE_MS: 600,                          // breath after each narrated step
  BRAND: { navy: '#013090', navyStrong: '#001F5C', gold: '#C9962B', bg: '#FBF8F2', ink: '#1F2430' },
  // Demo password for every tutorial login (dev database only).
  PASSWORD: 'TutorialDemo#2026',
};
