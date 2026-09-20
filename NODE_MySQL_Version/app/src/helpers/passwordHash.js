'use strict';

const bcrypt = require('bcrypt');

/**
 * node's native `bcrypt` package refuses to match PHP's `$2y$`-prefixed
 * hashes (password_hash()'s default) even though `$2y$` is algorithmically
 * identical to `$2b$` for every password length this app uses — it's an
 * OpenBSD historical-bug marker, not a different cipher. seed.sql ships
 * `$2y$` hashes (PHP-generated), so without this normalization every
 * seeded account would fail to log in against a byte-for-byte correct
 * password. Only the prefix is rewritten before compare(); the hash itself,
 * and hashing new passwords going forward (always `$2b$`, bcrypt's Node
 * default), are untouched.
 */
function normalize(hash) {
  if (typeof hash === 'string' && hash.startsWith('$2y$')) {
    return '$2b$' + hash.slice(4);
  }
  return hash;
}

async function verify(password, hash) {
  return bcrypt.compare(password, normalize(hash));
}

async function hash(password, rounds = 12) {
  return bcrypt.hash(password, rounds);
}

module.exports = { verify, hash, normalize };
