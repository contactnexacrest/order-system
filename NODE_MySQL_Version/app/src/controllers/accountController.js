'use strict';

const flash = require('../helpers/flash');
const userRepository = require('../repositories/userRepository');

/** docs/schema.sql Section AI — self-service staff account page (email signature today; a natural home for other self-service settings later). */

async function edit(req, res) {
  const user = await userRepository.findById(req.user.id);
  res.renderView('account/edit', { user }, 'layout/base');
}

async function updateSignature(req, res) {
  const signature = String(req.body.email_signature || '').trim();
  await userRepository.updateSignature(req.user.id, signature !== '' ? signature : null);
  flash.set(req, 'success', 'Signature saved — it will be used on every email you send through the system from now on.');
  res.redirect('/account');
}

module.exports = { edit, updateSignature };
