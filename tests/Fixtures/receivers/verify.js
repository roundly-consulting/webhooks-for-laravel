const crypto = require('node:crypto');

function verifyWebhook(secret, id, timestamp, signature, rawBody, now = Math.floor(Date.now() / 1000)) {
  // "whsec_" + base64 → the decoded bytes; any other secret is used as its own bytes.
  const key = secret.startsWith('whsec_') ? Buffer.from(secret.slice(6), 'base64') : Buffer.from(secret, 'utf8');

  if (!/^\d{1,12}$/.test(timestamp) || Math.abs(now - Number(timestamp)) > 300) {
    return false;
  }

  const expected = crypto.createHmac('sha256', key).update(`${id}.${timestamp}.`).update(rawBody).digest();

  // Several "v1,<base64>" entries during a secret rotation: any one may match.
  return signature.split(' ').some((entry) => {
    const [version, mac = ''] = entry.split(',');
    const given = Buffer.from(mac, 'base64');

    return version === 'v1' && given.length === expected.length && crypto.timingSafeEqual(given, expected);
  });
}

module.exports = { verifyWebhook };
