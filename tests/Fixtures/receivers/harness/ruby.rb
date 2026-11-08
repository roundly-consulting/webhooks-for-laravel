# Reads {secret, id, timestamp, signature, body (base64), now} as JSON on stdin; prints valid / invalid.
require 'json'
require_relative '../verify'

data = JSON.parse($stdin.read)
valid = verify_webhook(data['secret'], data['id'], data['timestamp'], data['signature'], Base64.strict_decode64(data['body']), data['now'])
print(valid ? 'valid' : 'invalid')
