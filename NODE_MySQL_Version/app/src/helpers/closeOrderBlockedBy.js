'use strict';

/**
 * 90-item backlog #57: Stage 9's non-blocking "before closing" hint — which
 * of BLI/COOPREP's labels still need generating+approving, based on each
 * type's latest revision (callers pass documents ordered newest-first by
 * generated_at, matching documentRepository.forOrder()). Informational
 * only: Port of the same logic in PHP's orders/show.php. Close Order
 * itself is never gated on this — docs/SOP/09-stage9-despatch-closure.md
 * documents that gate as an intentional staff-trust decision, and commit
 * 8543e0c already investigated and rejected a hard block here.
 */
const REQUIRED_DOCUMENT_TYPES = { BLI: 'BL Instruction Sheet', COOPREP: 'COO Prep Sheet' };

function closeOrderBlockedBy(documents) {
  const docs = documents || [];
  return Object.entries(REQUIRED_DOCUMENT_TYPES)
    .filter(([code]) => {
      const latest = docs.find((d) => d.document_type_code === code);
      return !latest || !['approved', 'sent'].includes(latest.status);
    })
    .map(([, label]) => label);
}

module.exports = { closeOrderBlockedBy };
