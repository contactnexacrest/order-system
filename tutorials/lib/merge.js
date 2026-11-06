'use strict';
// Joins several part scripts (tutorials/scripts/parts/) into one video.
// Each part keeps its own demo data and actors (actor keys are prefixed per part, so two parts
// may both use "associate"); every part opens with its own title card ("Part 2 of 3") and its
// chapters are numbered from 1 again. Setups all run before recording; teardowns all run after.
function merge(meta, parts) {
  const n = parts.length;
  return {
    ...meta,
    restoreDb: parts.some((p) => p.restoreDb),
    planContext: {},

    async setup(demo) {
      const ctxs = [];
      const actors = {};
      for (const [i, p] of parts.entries()) {
        const c = p.setup ? (await p.setup.call(p, demo)) || {} : {};
        ctxs.push(c);
        for (const [k, v] of Object.entries(c.actors || {})) actors[`p${i}.${k}`] = v;
      }
      return { actors, ctxs };
    },

    async teardown(demo, ctx) {
      for (const [i, p] of parts.entries()) if (p.teardown) await p.teardown.call(p, demo, ctx && ctx.ctxs ? ctx.ctxs[i] : undefined);
    },

    steps(ctx = {}) {
      const out = [];
      parts.forEach((p, i) => {
        const steps = p.steps.call(p, (ctx.ctxs && ctx.ctxs[i]) || {});
        steps.forEach((s, j) => {
          const t = { ...s };
          if (t.as) t.as = `p${i}.${t.as}`;
          if (j === 0 && t.card === 'title') {
            Object.assign(t, { resetChapter: true, code: `${meta.code} · Part ${i + 1} of ${n}` });
            if (i > 0) Object.assign(t, { title: p.title, subtitle: p.subtitle, minutes: null });
          }
          out.push(t);
        });
      });
      return out;
    },
  };
}
module.exports = { merge };
