'use strict';

const reasonValidator = require('../helpers/reasonValidator');
const auditLogRepository = require('../repositories/auditLogRepository');
const documentCrossVerificationRepository = require('../repositories/documentCrossVerificationRepository');
const documentRepository = require('../repositories/documentRepository');
const documentReviewRepository = require('../repositories/documentReviewRepository');
const notificationRepository = require('../repositories/notificationRepository');
const userRepository = require('../repositories/userRepository');
const documentGenerationService = require('./documentGenerationService');
const makerCheckerGuard = require('./makerCheckerGuard');
const stageGateService = require('./stageGateService');

/**
 * Spec Section 9 — REVIEW QUEUE / REVIEWER ASSIGNMENT / REVIEW ACTIONS /
 * CROSS-VERIFICATION. Orchestrates document_reviews + document_types.
 * min_reviewers_default + the DRAFT->FINAL watermark swap
 * (documentGenerationService.finalizeApproval()) — kept out of the
 * controller because "did every required reviewer approve, with none
 * pending or rejected" is real branching logic worth testing on its own,
 * not routing.
 */

/** @param {number[]} reviewerIds */
async function assignReviewers(documentId, reviewerIds, assignedByUserId) {
  const document = await documentRepository.find(documentId);
  if (!document) {
    throw new Error(`Document ${documentId} not found`);
  }

  // QA-5: validate every requested reviewer up front (all-or-nothing)
  // before inserting any — a partial insert followed by a thrown error
  // would leave some reviewers assigned and others not, for reasons the
  // caller never sees.
  const existingReviews = await documentReviewRepository.forDocument(documentId);
  const existingReviewerIds = new Set(existingReviews.map((r) => Number(r.reviewer_id)));
  const generatedBy = document.generated_by !== null && document.generated_by !== undefined ? Number(document.generated_by) : null;

  const toAssign = [];
  for (const rawId of new Set(reviewerIds.map(Number))) {
    if (existingReviewerIds.has(rawId)) {
      // Already has a review row on this document (pending, approved or
      // rejected) — assigning them again would leave a second 'pending'
      // row nobody is ever prompted to resolve, permanently stalling
      // finalizeIfFullyApproved()'s pending-count check (REV-07).
      continue;
    }
    const reviewer = await userRepository.findById(rawId);
    if (!reviewer) {
      throw new Error(`User ${rawId} not found.`);
    }
    if (!reviewer.is_active) {
      // REV-06: an inactive user (e.g. deactivated after leaving) can no
      // longer log in to ever act on this assignment, which would
      // otherwise stall the document in review forever.
      throw new Error(`${reviewer.name} is not an active user and cannot be assigned as a reviewer.`);
    }
    if (generatedBy !== null && rawId === generatedBy && !(await makerCheckerGuard.selfApprovalAllowed(rawId))) {
      // QA-5 maker-checker (REV-05): the document's own generator cannot
      // review/approve their own work, unless they're a Super Admin or
      // hold manage_permissions (owner decision).
      throw new Error("This document's own generator cannot be assigned as its reviewer — a different person must review it.");
    }
    toAssign.push(rawId);
  }

  for (const reviewerId of toAssign) {
    await documentReviewRepository.assign(documentId, reviewerId);
    await notificationRepository.create(
      reviewerId,
      null,
      'review_assigned',
      document.order_id,
      `You've been assigned to review ${document.document_type_code} ${document.document_reference} Rev.${document.revision_number}.`
    );
  }

  if (toAssign.length > 0) {
    if (document.status === 'draft') {
      await documentRepository.markInReview(documentId);
    }
    await auditLogRepository.log(assignedByUserId, 'REVIEWERS_ASSIGNED', 'documents', documentId, 'reviewers', null, toAssign.join(','));
  }
}

async function approve(documentReviewId, reviewerUserId, comments) {
  const review = await documentReviewRepository.find(documentReviewId);
  if (!review) {
    throw new Error(`Review ${documentReviewId} not found`);
  }
  if (review.reviewer_id !== reviewerUserId) {
    throw new Error('You are not the assigned reviewer for this document.');
  }
  if (review.status !== 'pending') {
    throw new Error('This review has already been actioned.');
  }

  const document = await documentRepository.find(review.document_id);
  if (document && document.order_id !== null) {
    // A newer revision of this (order, type) may have been generated while
    // this review sat pending (e.g. a reviewer was slow and the creator
    // regenerated to fix something reported out-of-band) — approving a
    // stale revision at this point would immediately get superseded again
    // by finalizeIfFullyApproved() below the moment the real latest
    // revision is later approved, which is confusing at best. Block it
    // outright: only the current latest revision of a document may ever
    // be approved.
    const latest = await documentRepository.findLatestForOrderAndType(document.order_id, document.document_type_id);
    if (latest && latest.id !== document.id) {
      throw new Error('A newer revision of this document has since been generated — this one can no longer be approved. Review the latest revision instead.');
    }
  }

  await documentReviewRepository.approve(documentReviewId, comments);
  await auditLogRepository.log(reviewerUserId, 'DOCUMENT_REVIEW_APPROVED', 'documents', review.document_id, null, null, null, comments);

  await finalizeIfFullyApproved(review.document_id);
}

async function reject(documentReviewId, reviewerUserId, comments) {
  const reasonError = reasonValidator.check(comments);
  if (reasonError) {
    throw new Error(reasonError);
  }
  const review = await documentReviewRepository.find(documentReviewId);
  if (!review) {
    throw new Error(`Review ${documentReviewId} not found`);
  }
  if (review.reviewer_id !== reviewerUserId) {
    throw new Error('You are not the assigned reviewer for this document.');
  }
  if (review.status !== 'pending') {
    throw new Error('This review has already been actioned.');
  }

  await documentReviewRepository.reject(documentReviewId, comments);

  const document = await documentRepository.find(review.document_id);
  if (document) {
    // "Document returns to creator. New version must be generated and
    // re-submitted." — back to draft; the creator regenerates (a fresh call
    // to documentGenerationService.generate(), a new revision) once
    // whatever was wrong is fixed.
    await documentRepository.markDraft(document.id);
    if (document.generated_by !== null && document.generated_by !== undefined) {
      await notificationRepository.create(
        document.generated_by,
        null,
        'review_rejected',
        document.order_id,
        `${document.document_type_code} ${document.document_reference} Rev.${document.revision_number} was rejected: ${comments}`
      );
    }
  }

  await auditLogRepository.log(reviewerUserId, 'DOCUMENT_REVIEW_REJECTED', 'documents', review.document_id, null, null, null, comments);
}

async function crossVerify(documentId, verifiedByUserId, result, comments) {
  if (result !== 'pass' && result !== 'fail') {
    throw new Error('Cross-verification result must be pass or fail.');
  }
  await documentCrossVerificationRepository.create(documentId, verifiedByUserId, result, comments);
  await auditLogRepository.log(verifiedByUserId, 'DOCUMENT_CROSS_VERIFIED', 'documents', documentId, 'result', null, result, comments);
}

/**
 * All required reviewers approved, none pending, none rejected -> document
 * is fully approved: swap DRAFT watermark for the final one and flip
 * documents.status. Anything short of that (still pending reviewers, or the
 * min_reviewers_default threshold not yet met) leaves the document exactly
 * as it was.
 */
async function finalizeIfFullyApproved(documentId) {
  const document = await documentRepository.find(documentId);
  if (!document || document.status === 'approved') {
    return;
  }

  const pending = await documentReviewRepository.countPending(documentId);
  const rejected = await documentReviewRepository.countRejected(documentId);
  const approved = await documentReviewRepository.countApproved(documentId);
  const minRequired = parseInt(document.min_reviewers_default || 1, 10);

  if (pending === 0 && rejected === 0 && approved >= Math.max(1, minRequired)) {
    await documentGenerationService.finalizeApproval(documentId);
    await auditLogRepository.log(null, 'DOCUMENT_APPROVED', 'documents', documentId, 'status', document.status, 'approved');

    // Point 1 (2026-10-01): this revision is now THE valid copy of this
    // (order, type) — any other revision still sitting as 'approved'/'sent'
    // from an earlier pass through review is now stale and must be
    // invalidated, both in status and visibly on the PDF itself, or
    // staff/buyers could keep relying on an old copy with no way to tell
    // it apart from the current one.
    await documentGenerationService.supersedeOtherApprovedRevisions(documentId);

    // GATE-01: the QT's approval — not its mere draft generation — is
    // Stage 1's real gate (Owner Decision #1: "No stage passes until its
    // document is approved").
    if (document.document_type_code === 'QT') {
      await stageGateService.passAndUnlockNext(document.order_id, 1, null);
    }
  }
}

module.exports = { assignReviewers, approve, reject, crossVerify };
