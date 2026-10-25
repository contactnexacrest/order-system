'use strict';

const fs = require('fs');
const path = require('path');

/**
 * Port of ReasonPromptRetryTest.php. Owner report (2026-10-25): "deleting the
 * duplicate draft document is not working". The Delete Draft prompt silently
 * threw away a reason under the 10-character minimum ("duplicate" is 9), so
 * the button just did nothing. Every reason prompt now goes through app.js's
 * promptForReason(), which asks again with an explanation instead.
 */
describe('Reason prompts ask again instead of silently doing nothing', () => {
  const app = path.join(__dirname, '..', '..');

  it('app.js defines the retrying reason prompt', () => {
    const js = fs.readFileSync(path.join(app, 'public', 'js', 'app.js'), 'utf8');

    expect(js).toContain('window.promptForReason = function (question)');
    expect(js).toContain('Please give a reason of at least');
  });

  it('no view asks for a reason with a raw prompt()', () => {
    const walk = (dir) => fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) => (
      e.isDirectory() ? walk(path.join(dir, e.name)) : [path.join(dir, e.name)]
    ));
    for (const file of walk(path.join(app, 'views')).filter((f) => f.endsWith('.njk'))) {
      expect([file, /[^.\w]prompt\('Why/.test(fs.readFileSync(file, 'utf8'))]).toEqual([file, false]);
    }
  });
});
