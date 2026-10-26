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

const { SafeString } = require('nunjucks').runtime;

// Supplier payments (owner request 2026-10-26: their own sub-steps) are
// money that has or hasn't actually gone out — they always show their own
// real status, never take part in the "later done means earlier done" rule,
// and a finished step never paints them done.
const HONEST_SUB_STEPS = new Set(['7e', '10a']);

// Owner request (2026-10-26): the 19 steps are grouped into five major
// sections, collapsed by default — only the section holding the order's
// current step opens on load. Steps 14-18 (after-sales records) are open to
// use at any time, so their section has no progress of its own.
const PHASES = {
  booking: { letter: 'A', title: 'Order Booking', sub: 'Quotation → Buyer PO → PI & advance → Order Confirmation', steps: ['s1', 's2', 's3', 's4', 's5', 's6'] },
  production: { letter: 'B', title: 'Procurement & Production', sub: 'Supplier PO → production & estimated shipment', steps: ['s7', 's8'] },
  shipment: { letter: 'C', title: 'Shipment & Balance', sub: 'Freight → packing & BL → commercial invoice & balance', steps: ['s9', 's10', 's11'] },
  closure: { letter: 'D', title: 'Closure & Settlement', sub: 'Financials & benefits → documents despatched → fully settled', steps: ['s12', 's13', 's19'] },
  aftersales: { letter: 'E', title: 'After-Sales & Records', sub: 'Additional documents · feedback · complaints · disputes · amendments', steps: [] },
};

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
  // earlier one shows done too.
  let laterDone = false;
  for (const key of [...PIPELINE_STEPS].reverse()) {
    if (laterDone) {
      stepStates[key] = 'done';
    } else if (stepStates[key] === 'done') {
      laterDone = true;
    }
  }

  // The step whose card starts open: the first one not yet done, or 's19'
  // once everything is.
  let currentUserStep = 's19';
  for (const key of Object.keys(stepStates)) {
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
      if (HONEST_SUB_STEPS.has(sub)) {
        continue;
      }
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
    // One of the five sections: { letter, title, sub, state, open, done, total }
    // — state is done/current/locked, or 'any' for the after-sales section.
    phase(key) {
      const phase = PHASES[key];
      const done = phase.steps.filter((st) => stepStates[st] === 'done').length;
      let state = 'any';
      if (phase.steps.length) {
        if (phase.steps.includes(currentUserStep) && stepStates[currentUserStep] !== 'done') {
          state = 'current';
        } else {
          state = done === phase.steps.length ? 'done' : 'locked';
        }
      }
      return {
        letter: phase.letter, title: phase.title, sub: phase.sub, state,
        open: phase.steps.includes(currentUserStep), done, total: phase.steps.length,
      };
    },
    // done/current/locked for one sub-step card: a done or locked step
    // overrides every sub-step; inside the current step, done sub-steps show
    // done, the first unfinished one is current, the rest wait their turn.
    subStepStatus(step, sub) {
      const parent = stepStates[step];
      const done = subStepDone[step] ? subStepDone[step][sub] : undefined;
      if (HONEST_SUB_STEPS.has(sub) && parent === 'done' && done !== null && done !== undefined) {
        return done ? 'done' : 'current';
      }
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
    //
    // Owner rule (2026-10-26): a sub-step can't be opened or used until the
    // one before it is complete. A waiting sub-step (or every sub-step of a
    // step not reached yet) renders data-locked: greyed, can't be expanded.
    // Optional sub-steps (no done signal of their own) never lock.
    subCardOpen(step, sub) {
      const signal = subStepDone[step] ? subStepDone[step][sub] : undefined;
      const hasSignal = signal !== null && signal !== undefined;
      if ((hasSignal || stepStates[step] === 'locked') && this.subStepStatus(step, sub) === 'locked') {
        const waitingOn = stepStates[step] === 'locked' ? 'the previous step' : `Step ${currentSubStep[step]}`;
        // Marked safe: the attribute's own quotes must reach the page as-is
        // (the text is fixed wording plus a sub-step id, never user input).
        return new SafeString(` data-locked="Complete ${waitingOn} first"`);
      }
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

module.exports = { computeStepFlow, PHASES };
