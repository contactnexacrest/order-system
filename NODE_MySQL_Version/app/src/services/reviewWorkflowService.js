'use strict';

const auditLogRepository = require('../repositories/auditLogRepository');
const documentCrossVerificationRepository = require('../repositories/documentCrossVerificationRepository');
const documentRepository = require('../repositories/documentRepository');
const documentReviewRepository = require('../repositories/documentReviewRepository');
const notificationRepository = require('../repositories/notificationRepository');
const documentGenerationService = require('./documentGenerationService');

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

  const uniqueIds = [...new Set(reviewerIds)];
  for (const reviewerId of uniqueIds) {
    await documentReviewRepository.assign(documentId, reviewerId);
    await notificationRepository.create(
      reviewerId,
      null,
      'review_assigned',
      document.order_id,
      `You've been assigned to review ${document.document_type_code} ${document.document_reference} Rev.${document.revision_number}.`
    );
  }

  if (document.status === 'draft') {
    await documentRepository.markInReview(documentId);
  }

  await auditLogRepository.log(assignedByUserId, 'REVIEWERS_ASSIGNED', 'documents', documentId, 'reviewers', null, reviewerIds.join(','));
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

  await documentReviewRepository.approve(documentReviewId, comments);
  await auditLogRepository.log(reviewerUserId, 'DOCUMENT_REVIEW_APPROVED', 'documents', review.document_id, null, null, null, comments);

  await finalizeIfFullyApproved(review.document_id);
}

async function reject(documentReviewId, reviewerUserId, comments) {
  if (!comments || comments.trim() === '') {
    throw new Error('A comment is mandatory when rejecting a document.');
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
  }
}

module.exports = { assignReviewers, approve, reject, crossVerify };
