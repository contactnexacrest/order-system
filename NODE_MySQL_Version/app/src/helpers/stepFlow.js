'use strict';

/**
 * Order detail page step/sub-step progression — the Node counterpart of the
 * $stepStates cascade / $currentUserStep / $subStepDone block in PHP's
 * views/orders/show.php. A plain JS helper rather than template code:
 * `{% set %}` inside a `{% for %}` is scoped to that iteration and never
 * leaks back out, so "first match wins" searches can't be written as
 * template-side loops.
 *
 * stepStates values mostly come from {% macro %} calls (stepStatusFn /
 * step123Status), which return SafeString objects, not strings — every
 * value is String()-ed before comparing, or a strict !== 'done' is always
 * true and every order page opens Step 1 regardless.
 */

const PIPELINE_STEPS = ['s1', 's2', 's3', 's4', 's5', 's6', 's7', 's8', 's9', 's10', 's11', 's12', 's13'];

/**
 * @param {Object<string, *>} rawStepStates step key ('s1'..'s19', 's8b') -> 'done'|'current'|'locked'
 * @param {Object<string, Object<string, *>>} rawSubStepDone step key -> { sub key -> truthy/falsy done
 *   signal, in card order }, or the string 'optional' for a sub-step with no signal of its own
 */
function computeStepFlow(rawStepStates, rawSubStepDone) {
  const stepStates = {};
  for (const key of Object.keys(rawStepStates)) {
    stepStates[key] = String(rawStepStates[key]);
  }

  // Owner rule (2026-10-25): "when a later step is completed, the previous
  // ones must be completed" — once any later pipeline step is done, every
  // earlier one shows done too. The Compliance Checklist (s8b) is the one
  // deliberate exception: real paperwork the stage gates don't require, so
  // it stays honest (amber, with its open-task count) rather than claiming
  // tasks nobody did.
  let laterDone = false;
  for (const key of [...PIPELINE_STEPS].reverse()) {
    if (laterDone) {
      stepStates[key] = 'done';
    } else if (stepStates[key] === 'done') {
      laterDone = true;
    }
  }

  // The step whose card starts open: the first one not yet done, or 's19'
  // once everything is. An outstanding checklist (s8b) no longer claims the
  // page once the pipeline has moved past it (Step 9 onward reached).
  const pipelineMovedPast8b = stepStates.s9 !== 'locked';
  let currentUserStep = 's19';
  for (const key of Object.keys(stepStates)) {
    if (key === 's8b' && pipelineMovedPast8b) {
      continue;
    }
    if (stepStates[key] !== 'done') {
      currentUserStep = key;
      break;
    }
  }

  // Sub-steps: within a step a later sub-step done means the earlier ones
  // count as done too; the first unfinished one is current.
  const subStepDone = {};
  const currentSubStep = {};
  for (const step of Object.keys(rawSubStepDone || {})) {
    const subs = {};
    for (const sub of Object.keys(rawSubStepDone[step])) {
      const value = rawSubStepDone[step][sub];
      subs[sub] = value === 'optional' ? null : Boolean(value);
    }
    let laterSubDone = false;
    for (const sub of Object.keys(subs).reverse()) {
      if (laterSubDone && subs[sub] !== null) {
        subs[sub] = true;
      } else if (subs[sub] === true) {
        laterSubDone = true;
      }
    }
    subStepDone[step] = subs;
    currentSubStep[step] = Object.keys(subs).find((sub) => subs[sub] === false) || null;
  }

  return {
    stepStates,
    currentUserStep,
    currentSubStep,
    stepCardOpen(step) {
      return step === currentUserStep ? ' open' : '';
    },
    // done/current/locked for one sub-step card: a done or locked step
    // overrides every sub-step; inside the current step, done sub-steps show
    // done, the first unfinished one is current, the rest wait their turn.
    subStepStatus(step, sub) {
      const parent = stepStates[step];
      const done = subStepDone[step] ? subStepDone[step][sub] : undefined;
      if (parent !== 'current' || done === null || done === undefined) {
        return parent;
      }
      if (done) {
        return 'done';
      }
      return sub === currentSubStep[step] ? 'current' : 'locked';
    },
    // Inside the current step only its first unfinished sub-step starts open
    // (the page scrolls to it), so after any action the page lands on the
    // card that's next. A step with no unfinished sub-step left keeps the old
    // behaviour (all its cards open).
    subCardOpen(step, sub) {
      if (step !== currentUserStep) {
        return '';
      }
      if (!currentSubStep[step]) {
        return ' open';
      }
      return sub === currentSubStep[step] ? ' open' : '';
    },
  };
}

module.exports = { computeStepFlow };
