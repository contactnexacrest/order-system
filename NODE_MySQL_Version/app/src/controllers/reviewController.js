'use strict';

const flash = require('../helpers/flash');
const documentRepository = require('../repositories/documentRepository');
const documentReviewRepository = require('../repositories/documentReviewRepository');
const reviewWorkflowService = require('../services/reviewWorkflowService');

// Port of App\Controllers\ReviewController. Spec Section 9 — Review,
// Approval & Cross-Verification.

/** My review queue: pending document_reviews rows assigned to me. */
async function queue(req, res) {
  const user = req.user;
  res.renderView('reviews/queue', { pendingReviews: await documentReviewRepository.pendingForReviewer(user.id) }, 'layout/base');
}

/** Admin/privileged: assign one or more reviewers to a generated document. */
async function assign(req, res) {
  const documentId = parseInt(req.params.documentId, 10);
  const document = await documentRepository.find(documentId);
  if (!document) {
    res.status(404).send('Document not found.');
    return;
  }

  const reviewerIds = [].concat(req.body.reviewer_ids || []).map((v) => parseInt(v, 10)).filter((id) => id > 0);

  if (reviewerIds.length === 0) {
    flash.set(req, 'error', 'Select at least one reviewer.');
    res.redirect(`/orders/${document.order_id}`);
    return;
  }

  try {
    await reviewWorkflowService.assignReviewers(documentId, reviewerIds, req.user.id);
    flash.set(req, 'success', 'Reviewer(s) assigned — document is now in review.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }

  res.redirect(`/orders/${document.order_id}`);
}

async function approve(req, res) {
  const reviewId = parseInt(req.params.reviewId, 10);
  const review = await documentReviewRepository.find(reviewId);
  const document = review ? await documentRepository.find(review.document_id) : null;
  const orderId = document ? document.order_id : null;

  try {
    const comments = String(req.body.comments || '').trim() || null;
    await reviewWorkflowService.approve(reviewId, req.user.id, comments);
    flash.set(req, 'success', 'Review approved.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }

  res.redirect(orderId ? `/orders/${orderId}` : '/reviews');
}

async function reject(req, res) {
  const reviewId = parseInt(req.params.reviewId, 10);
  const review = await documentReviewRepository.find(reviewId);
  const document = review ? await documentRepository.find(review.document_id) : null;
  const orderId = document ? document.order_id : null;

  try {
    await reviewWorkflowService.reject(reviewId, req.user.id, String(req.body.comments || '').trim());
    flash.set(req, 'success', 'Review rejected — sent back to the creator.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }

  res.redirect(orderId ? `/orders/${orderId}` : '/reviews');
}

async function crossVerify(req, res) {
  const documentId = parseInt(req.params.documentId, 10);
  const document = await documentRepository.find(documentId);
  if (!document) {
    res.status(404).send('Document not found.');
    return;
  }

  try {
    const comments = String(req.body.comments || '').trim() || null;
    await reviewWorkflowService.crossVerify(documentId, req.user.id, String(req.body.result || ''), comments);
    flash.set(req, 'success', 'Cross-verification recorded.');
  } catch (e) {
    flash.set(req, 'error', e.message);
  }

  res.redirect(`/orders/${document.order_id}`);
}

module.exports = { queue, assign, approve, reject, crossVerify };
