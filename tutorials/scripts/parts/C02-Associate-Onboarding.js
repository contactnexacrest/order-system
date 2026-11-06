'use strict';
// C02 — How you become an associate: the registration link, NexaCrest's review,
// setting your password and the first login. Also the second door: NexaCrest adds you directly.
const { ACTORS, nav } = require('../_common');

const NEW = 'associate.new@email.com';
const DIRECT = 'associate.direct@email.com';
const NOTE = 'TEST tutorial C02 registration';

module.exports = {
  code: 'C02',
  file: 'C02-Associate-Onboarding.mp4',
  group: 'Group C · Associates',
  title: 'How you become an associate',
  subtitle: 'From the registration link to your first login: every step, on both sides.',
  minutes: '10',

  async setup(demo) {
    this.teardown(demo);
    return {
      actors: {
        md: { ...ACTORS.md, cookies: await demo.staffCookies('md') },
        visitor: { label: 'Future associate', sub: NEW, initials: 'FA', color: '#6B4FA0', cookies: [] },
        associate: { ...ACTORS.associate(NEW), cookies: [] },
      },
    };
  },

  teardown(demo) {
    demo.removeAssociates([NEW, DIRECT]);
    demo.sql(`DELETE FROM associate_registrations WHERE email IN ('${NEW}', '${DIRECT}')`);
    demo.sql(`DELETE FROM associate_registration_links WHERE note = '${NOTE}'`);
  },

  steps: () => {
    const state = {};
    return [
      { card: 'title', say: 'Welcome back! In this video you follow a brand-new associate from the very first link, all the way to their first login. And we switch sides a few times, so you can see what NexaCrest does in the background.' },
      { card: 'list', kicker: 'Two doors', title: 'Two ways to become an associate', items: [
        'Door one: NexaCrest sends you an associate registration link. You fill in a short form.',
        'Door two: NexaCrest adds you directly, after a meeting or a call.',
        'Either way: you get a link to set your password, and your portal opens.',
      ], say: 'There are two doors into NexaCrest. Door one: NexaCrest sends you an associate registration link, and you fill in a short form yourself. Door two: after a meeting or a phone call, NexaCrest adds you directly. Either way, you end up with a link to set your password, and your portal opens. Let us walk through door one first.' },

      { card: 'chapter', title: 'NexaCrest creates your link', subtitle: 'One link, for one person, valid for a few days.', say: 'Part one. It starts at NexaCrest.' },
      { as: 'md', goto: '/associates/registrations', caption: 'Staff: Associates → Registrations', say: 'This is the staff side. The badge, top right, tells you who we are: the Managing Director. This page is called Associate registrations.', do: async (d) => { await d.point('h1', 1200); } },
      { caption: 'Create an associate registration link', say: 'The Managing Director types a short note, just to remember who the link is for, and presses Create link.', do: async (d) => {
        await d.type(d.page.getByLabel('For whom (note, optional)'), NOTE, { delay: 35 });
        await d.clickNav(d.page.getByRole('button', { name: 'Create link' }));
      } },
      { caption: 'One link, one registration', say: 'Here is the link. It works for one registration only, and it expires after a few days. NexaCrest copies it and sends it to you, by e-mail or chat.', do: async (d) => {
        state.link = await d.text('.alert-success code');
        await d.point('.alert-success', 1500);
      } },

      { card: 'chapter', title: 'You fill in the form', subtitle: 'Six details and a tick. That is all.', say: 'Part two. Now you open the link.' },
      { as: 'visitor', caption: 'The registration form', say: 'This is what you see when you open the link. Register as a NexaCrest associate. Only a few details are needed now. Your address, PAN, bank details and documents come later, inside your portal.', do: async (d) => { await d.goto(new URL(state.link).pathname); await d.point('h1', 1200); } },
      { caption: 'Mistakes are caught at once', say: 'Watch what happens if we press the button too early. Every missing field is marked right away, with a message beside it. Nothing is sent until the form is complete.', do: async (d) => {
        await d.click(d.page.getByRole('button', { name: 'Send my registration' }));
        await d.wait(800);
        await d.ring(d.page.getByLabel('Full name *'));
      } },
      { caption: 'Name, e-mail and phone', say: 'So let us fill it in properly. Your full name, the e-mail address that will become your portal login, and your phone number.', do: async (d) => {
        await d.type(d.page.getByLabel('Full name *'), 'Demo Associate');
        await d.type(d.page.locator('input[name="email"]'), NEW);
        await d.type(d.page.getByLabel('Phone *'), '+91-0000000000');
      } },
      { caption: 'Where you are based', say: 'Then where you are based. India, or outside India. This matters later, for tax: associates in India and abroad are treated differently. Then your country and city.', do: async (d) => {
        await d.select(d.page.locator('select[name="based_in"]'), 'india');
        await d.type(d.page.getByLabel('Country *'), 'India');
        await d.type(d.page.getByLabel('City *'), 'Jaipur');
      } },
      { caption: 'Consent, then send', say: 'Tick the box to agree that NexaCrest may use these details to review your registration, and press Send my registration.', do: async (d) => {
        await d.click(d.page.locator('input[name="consent"]'));
        await d.clickNav(d.page.getByRole('button', { name: 'Send my registration' }));
      } },
      { caption: 'Received!', say: 'Done! The thank-you page explains what happens next. NexaCrest reviews your registration, and once it is accepted, you receive a link to set your password.', do: async (d) => { await d.point('h1', 1500); } },

      { card: 'chapter', title: 'NexaCrest reviews it', subtitle: 'Accept, or reject with a reason.', say: 'Part three. Back at NexaCrest.' },
      { as: 'md', goto: '/associates/registrations', caption: 'Waiting for review', say: 'Your registration now waits in the list, with your name, e-mail, phone, and where you are based.', do: async (d) => { await d.ring(d.page.locator('tr', { hasText: NEW }).first()); await d.wait(1500); } },
      { caption: 'Accept', say: 'The Managing Director can accept it, or reject it with a reason, which is e-mailed to you. Here, we accept. The browser asks, are you sure? And we confirm.', do: async (d) => {
        await d.clickNav(d.page.locator('tr', { hasText: NEW }).getByRole('button', { name: 'Accept' }));
      } },
      { caption: 'You are now an associate (Invited)', say: 'And now you are an associate, with the status Invited. At the same moment, the system e-mails you a link to set your password.', do: async (d) => {
        state.setPw = await d.devLink();
        await d.point('.alert-success', 1500);
      } },

      { card: 'chapter', title: 'Your password and first login', subtitle: 'Strong password, then the 6-digit code.', say: 'Part four. Your password, and your very first login.' },
      { as: 'associate', caption: 'Set your password', say: 'You open the link from the e-mail. It shows your login e-mail, and asks for a new password: at least ten characters, with letters and digits.', do: async (d) => { await d.goto(state.setPw); await d.point('h1', 1000); } },
      { caption: 'Typed twice, checked at once', say: 'You type it twice. If the two do not match, the page tells you immediately, before anything is sent. Let us type it correctly, and press Set password.', do: async (d) => {
        await d.type(d.page.getByLabel(/^New password/), 'TutorialDemo#2026', { delay: 45 });
        await d.type(d.page.getByLabel('Re-enter the new password'), 'TutorialDemo#2026', { delay: 45 });
        await d.clickNav(d.page.getByRole('button', { name: 'Set password' }));
      } },
      { goto: '/associate/login', caption: 'First login', say: 'Now the normal login: e-mail, password, Continue.', do: async (d) => {
        await d.type(d.page.getByLabel('E-mail'), NEW);
        await d.type(d.page.getByLabel('Password'), 'TutorialDemo#2026', { delay: 40 });
        await d.clickNav(d.page.getByRole('button', { name: 'Continue' }));
      } },
      { caption: 'The code from your e-mail', say: 'And the six-digit code from your e-mail.', do: async (d) => {
        await d.type(d.page.getByLabel('Code'), await d.devCode(), { delay: 110 });
        await d.clickNav(d.page.getByRole('button', { name: 'Log in' }));
      } },
      { caption: 'Status: Invited', say: 'Welcome to your portal! Notice the message: your account is invited. It becomes active once NexaCrest has checked your documents and bank details, which you add under Profile. Until then, you can look around, but you cannot invite clients yet.', do: async (d) => { await d.point(d.page.locator('.alert-warning').first(), 2000); } },

      { card: 'chapter', title: 'Door two: added by NexaCrest', subtitle: 'Same result, fewer steps for you.', say: 'Part five. The second door.' },
      { as: 'md', goto: '/associates/new', caption: 'Staff: Associates → Add associate', say: 'Sometimes NexaCrest adds you directly, for example after meeting you at a trade fair. Staff fill in this form with your name, your e-mail for the portal login, and how your commission works.', do: async (d) => {
        await d.type(d.page.getByLabel('Name *'), 'Demo Direct Associate');
        await d.type(d.page.getByLabel('E-mail (portal login)'), DIRECT);
        await d.ring(d.page.locator('select[name="commission_mode"]')); await d.wait(800);
      } },
      { caption: 'Add associate', say: 'They press Add associate, and your record is created.', do: async (d) => {
        await d.select(d.page.locator('select[name="commission_mode"]'), 'ladder');
        await d.clickNav(d.page.getByRole('button', { name: 'Add associate' }));
      } },
      { caption: 'Send portal login link', say: 'Then one button sends you the portal login link. From there, it is exactly the same as door one: set your password, log in with the code, and complete your profile.', do: async (d) => {
        await d.scrollTo(d.page.getByRole('button', { name: 'Send portal login link' }));
        await d.ring(d.page.getByRole('button', { name: 'Send portal login link' })); await d.wait(1500);
      } },

      { card: 'list', kicker: 'Recap', title: 'From link to login', items: [
        'Registration link → short form → NexaCrest accepts → password link by e-mail.',
        'Or NexaCrest adds you directly and sends the password link.',
        'Password: at least 10 characters, letters and digits. Then the 6-digit code.',
        'Status Invited until your documents and bank are checked.',
      ], next: 'Part 2 · Profile, documents and bank', say: 'Let us recap. Door one: a registration link, a short form, NexaCrest accepts, and a password link arrives by e-mail. Door two: NexaCrest adds you and sends the same link. Then your password, the six-digit code, and you are in, with the status Invited. In part two of this video, we complete your profile, documents and bank details, so your account becomes active. See you there!' },
    ];
  },
};
