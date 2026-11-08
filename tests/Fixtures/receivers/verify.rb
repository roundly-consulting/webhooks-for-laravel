require 'base64'
require 'openssl'

def verify_webhook(secret, id, timestamp, signature, raw_body, now = Time.now.to_i)
  # "whsec_" + base64 → the decoded bytes; any other secret is used as its own bytes.
  key = secret.start_with?('whsec_') ? Base64.strict_decode64(secret[6..]) : secret

  return false unless timestamp.match?(/\A\d{1,12}\z/) && (now - timestamp.to_i).abs <= 300

  expected = OpenSSL::HMAC.digest('SHA256', key, "#{id}.#{timestamp}.".b + raw_body.b)

  # Several "v1,<base64>" entries during a secret rotation: any one may match.
  signature.split(' ').any? do |entry|
    version, mac = entry.split(',', 2)
    given = Base64.decode64(mac.to_s)

    version == 'v1' && given.bytesize == expected.bytesize && OpenSSL.secure_compare(given, expected)
  end
rescue ArgumentError
  false
end
