'use strict';

const request = require('supertest');
const app = require('../../src/server');
const db = require('../../src/config/db');
const passwordHash = require('../../src/helpers/passwordHash');
const documentReviewRepository = require('../../src/repositories/documentReviewRepository');
const { createTestClient, createTestOrder, createTestDocument } = require('../support/fixtures');

/**
 * Port of MyReviewsSidebarBadgeTest.php. Owner request (2026-10-26): "My
 * Reviews" shows how many documents are waiting on the signed-in user's
 * review, the same live badge the Quotation/PI Intake Review links carry.
 */
describe('My Reviews sidebar badge', () => {
  afterAll(async () => {
    await db.pool.end();
  });

  async function loginAsNewAdmin() {
    const role = await db.queryOne('SELECT id FROM roles WHERE name = :name', { name: 'Admin' });
    const password = 'My-Reviews-Badge-Test-1!';
    const email = `jest-my-reviews-${Math.random().toString(16).slice(2, 10)}@nexacrest.test`;
    const result = await db.execute(
      `INSERT INTO users (name, email, phone, password_hash, role_id, is_active, force_password_change, two_fa_enabled)
       VALUES ('Jest My Reviews User', :email, NULL, :hash, :role_id, 1, 0, 0)`,
      { email, hash: await passwordHash.hash(password), role_id: role.id }
    );
    const agent = request.agent(app);
    const loginPage = await agent.get('/login');
    const csrf = loginPage.text.match(/name="_csrf"\s+value="([^"]+)"/)[1];
    await agent.post('/login').type('form').send({ _csrf: csrf, email, password });
    return { agent, userId: result.insertId };
  }

  async function myReviewsLink(agent) {
    const { text } = await agent.get('/');
    const start = text.indexOf('<a href="/reviews"');
    expect(start).toBeGreaterThan(-1);
    return text.slice(start, text.indexOf('</a>', start));
  }

  it('shows no badge when nothing is waiting on the user', async () => {
    const { agent } = await loginAsNewAdmin();
    expect(await myReviewsLink(agent)).not.toContain('nav-badge');
  });

  it('counts only this user\'s pending reviews', async () => {
    const { agent, userId } = await loginAsNewAdmin();
    const other = await loginAsNewAdmin();
    const orderId = await createTestOrder(await createTestClient());
    await documentReviewRepository.assign(await createTestDocument(orderId, 'QT', 'in_review'), userId);
    await documentReviewRepository.assign(await createTestDocument(orderId, 'PI', 'in_review'), userId);
    const approvedId = await documentReviewRepository.assign(await createTestDocument(orderId, 'OC', 'in_review'), userId);
    await documentReviewRepository.approve(approvedId, null);
    await documentReviewRepository.assign(await createTestDocument(orderId, 'CI', 'in_review'), other.userId);

    expect(await documentReviewRepository.pendingCountForReviewer(userId)).toBe(2);
    expect(await myReviewsLink(agent)).toContain('<span class="nav-badge">2</span>');
  });
});
