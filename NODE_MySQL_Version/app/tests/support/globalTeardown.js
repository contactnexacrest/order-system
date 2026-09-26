'use strict';

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');
const dotenv = require('dotenv');
const { DB_NAME } = require('./globalSetup');

module.exports = async function globalTeardown() {
  const realEnv = dotenv.parse(fs.readFileSync(path.join(__dirname, '..', '..', '.env')));
  execFileSync(
    'mysql',
    ['-h', realEnv.DB_HOST || '127.0.0.1', '-P', realEnv.DB_PORT || '3306', '-u', realEnv.DB_USERNAME, `-p${realEnv.DB_PASSWORD || ''}`],
    { stdio: ['pipe', 'pipe', 'pipe'], input: `DROP DATABASE IF EXISTS \`${DB_NAME}\`;` }
  );
};
