// Reads {secret, id, timestamp, signature, body (base64), now} as JSON on stdin; prints valid / invalid.
const { verifyWebhook } = require('../verify.js');

const input = JSON.parse(require('node:fs').readFileSync(0, 'utf8'));
const valid = verifyWebhook(input.secret, input.id, input.timestamp, input.signature, Buffer.from(input.body, 'base64'), input.now);
process.stdout.write(valid ? 'valid' : 'invalid');
