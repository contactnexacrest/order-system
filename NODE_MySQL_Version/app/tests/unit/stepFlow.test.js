'use strict';

const { computeStepFlow } = require('../../src/helpers/stepFlow');

function states(overrides) {
  const base = {};
  for (const key of ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's8b', 's9', 's10', 's11', 's12', 's13']) {
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

  it('keeps an outstanding checklist (s8b) amber but skips it as the current step once the pipeline is past it', () => {
    const before = computeStepFlow(states({ s1: 'done', s7: 'done', s8: 'done', s8b: 'current', s9: 'locked' }), {});
    const after = computeStepFlow(states({ s1: 'done', s10: 'done', s8b: 'current', s11: 'current' }), {});

    expect(before.currentUserStep).toBe('s8b');
    expect(after.stepStates.s8b).toBe('current');
    expect(after.currentUserStep).toBe('s11');
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
    expect(flow.subCardOpen('s5', '5c')).toBe('');
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
    expect(flow.subCardOpen('s6', '6a')).toBe('');
  });
});
