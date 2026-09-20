'use strict';

const env = require('../config/env');

// Port of App\Services\SmsService — optional/pluggable, same contract:
// isAvailable() is the single gate every caller checks. Wire a real
// provider's HTTP call inside send() once one is chosen (Twilio, MSG91,
// etc.) using SMS_GATEWAY_PROVIDER / SMS_GATEWAY_API_KEY / SMS_GATEWAY_SENDER_ID.

function isAvailable() {
  return !!env.get('SMS_GATEWAY_API_KEY');
}

async function send(toPhone, message) {
  if (!isAvailable()) return false;
  console.error(`[SMS NOT SENT — gateway integration not yet implemented] To: ${toPhone} | Message: ${message}`);
  return false;
}

module.exports = { isAvailable, send };
