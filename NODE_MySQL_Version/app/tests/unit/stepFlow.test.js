'use strict';

const { computeStepFlow } = require('../../src/helpers/stepFlow');

function states(overrides) {
  const base = {};
  for (const key of ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9', 's10', 's11', 's12', 's13']) {
    base[key] = 'locked';
  }
  for (const key of ['s14', 's15', 's16', 's17', 's18']) {
    base[key] = 'done';
  }
  base.s19 = 'locked';
  return { ...base, ...overrides };
}

// A {% macro %} returns a SafeString-like object, not a string.
const safe = (value) => ({ toString: () => value, valueOf: () => value });

describe('computeStepFlow (order page step/sub-step progression)', () => {
  it('compares macro (SafeString) output as strings, so the real current step is found', () => {
    const flow = computeStepFlow(states({ s1: safe('done'), s2: safe('done'), s3: safe('done'), s4: safe('current') }), {});

    expect(flow.currentUserStep).toBe('s4');
  });

  it('marks every earlier pipeline step done once a later one is', () => {
    const flow = computeStepFlow(states({ s1: 'done', s2: 'current', s3: 'locked', s4: 'done', s5: 'current' }), {});

    expect(flow.stepStates.s2).toBe('done');
    expect(flow.stepStates.s3).toBe('done');
    expect(flow.currentUserStep).toBe('s5');
  });

  // Owner request (2026-10-26): supplier payments (7e / 10a) always show
  // their own real status — a finished step never paints them done.
  it('keeps the supplier payment sub-steps honest after their step is done', () => {
    const flow = computeStepFlow(
      states({ s1: 'done', s7: 'done', s8: 'current' }),
      { s7: { '7a': true, '7b': true, '7c': true, '7d': true, '7e': false } }
    );

    expect(flow.subStepStatus('s7', '7d')).toBe('done');
    expect(flow.subStepStatus('s7', '7e')).toBe('current');
  });

  it('opens only the first unfinished sub-step of the current step and ticks the done ones', () => {
    const flow = computeStepFlow(
      states({ s1: 'done', s2: 'done', s3: 'done', s4: 'done', s5: 'current' }),
      { s5: { '5a': { id: 1 }, '5b': null, '5c': false, '5d': undefined, '5e': false } }
    );

    expect(flow.subStepStatus('s5', '5a')).toBe('done');
    expect(flow.subStepStatus('s5', '5b')).toBe('current');
    expect(flow.subStepStatus('s5', '5c')).toBe('locked');
    expect(flow.subCardOpen('s5', '5a')).toBe('');
    expect(flow.subCardOpen('s5', '5b')).toBe(' open');
    // Owner rule (2026-10-26): a sub-step waiting on the one before it is locked.
    expect(String(flow.subCardOpen('s5', '5c'))).toBe(' data-locked="Complete Step 5b first"');
  });

  it('counts earlier sub-steps done when a later one is, and leaves optional ones following their step', () => {
    const flow = computeStepFlow(
      states({ s1: 'done', s5: 'done', s6: 'current' }),
      { s6: { '6a': false, '6b': true, '6c': 'optional' } }
    );

    expect(flow.subStepStatus('s6', '6a')).toBe('done');
    expect(flow.subStepStatus('s6', '6c')).toBe('current');
  });

  it('lets a done or locked step override every sub-step', () => {
    const flow = computeStepFlow(states({ s1: 'done', s4: 'done', s5: 'current' }), { s4: { '4a': false }, s6: { '6a': true } });

    expect(flow.subStepStatus('s4', '4a')).toBe('done');
    expect(flow.subStepStatus('s6', '6a')).toBe('locked');
    expect(String(flow.subCardOpen('s6', '6a'))).toBe(' data-locked="Complete the previous step first"');
  });

  // Owner request (2026-10-25): the active step and sub-step must stand out.
  it('highlights the current step and sub-step', () => {
    const css = require('fs').readFileSync(require('path').join(__dirname, '..', '..', 'public', 'css', 'app.css'), 'utf8');

    expect(css).toContain('.step-group:has(> summary > .step-card-icon.current){border:2px solid var(--gold);');
    expect(css).toContain('.step-card:has(> summary > .step-card-icon.current){border-color:var(--gold);border-left:5px solid var(--gold);');
  });
});
