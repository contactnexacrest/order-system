'use strict';

const actionNotificationService = require('../../src/services/actionNotificationService');

/**
 * Port of ActionNotificationServiceTest.php. User-reported batch item
 * 20 ("non-negotiable"): the system must email a notification for
 * every action, to a configurable catch-all notification_email AND to
 * the acting user's own email when they've opted in. Drives the two
 * pure helper functions directly — resolveRecipients() and
 * buildMessage() — rather than notify() itself, since notify() touches
 * the DB; that round-trip is covered separately by
 * auditLogNotificationHook.test.js.
 */
describe('actionNotificationService (90-item backlog item 20, non-negotiable)', () => {
  it('resolveRecipients returns empty when nothing is configured', async () => {
    expect(await actionNotificationService.resolveRecipients(null)).toEqual([]);
  });

  it("resolveRecipients includes the opted-in user's email", async () => {
    const user = { email: 'jane@nexacrestinternational.com', email_action_notifications_enabled: 1 };
    expect(await actionNotificationService.resolveRecipients(user)).toEqual(['jane@nexacrestinternational.com']);
  });

  it('resolveRecipients excludes an opted-out user', async () => {
    const user = { email: 'jane@nexacrestinternational.com', email_action_notifications_enabled: 0 };
    expect(await actionNotificationService.resolveRecipients(user)).toEqual([]);
  });

  it('resolveRecipients defaults to opted-in when the column is missing', async () => {
    const user = { email: 'jane@nexacrestinternational.com' };
    expect(await actionNotificationService.resolveRecipients(user)).toEqual(['jane@nexacrestinternational.com']);
  });

  it('buildMessage humanizes the action type and includes the change', () => {
    const user = { name: 'Jane Staff' };
    const message = actionNotificationService.buildMessage(user, 'FIELD_EDIT', 'orders', 42, 'incoterm_id', '1', '2', 'Buyer changed terms');

    expect(message.subject).toContain('Field Edit');
    expect(message.subject).toContain('Orders #42');
    expect(message.body).toContain('By: Jane Staff');
    expect(message.body).toContain('Field: incoterm_id');
    expect(message.body).toContain('Change: 1 -> 2');
    expect(message.body).toContain('Reason: Buyer changed terms');
  });

  it('buildMessage falls back to System when no user performed the action', () => {
    const message = actionNotificationService.buildMessage(null, 'ALERT_RAISED', null, null, null, null, null, null);

    expect(message.body).toContain('By: System');
  });
});
