package main

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"regexp"
	"strconv"
	"strings"
	"time"
)

var unixSeconds = regexp.MustCompile(`^\d{1,12}$`)

func VerifyWebhook(secret, id, timestamp, signature string, body []byte, now time.Time) bool {
	// "whsec_" + base64 → the decoded bytes; any other secret is used as its own bytes.
	key := []byte(secret)
	if strings.HasPrefix(secret, "whsec_") {
		decoded, err := base64.StdEncoding.DecodeString(secret[6:])
		if err != nil {
			return false
		}
		key = decoded
	}

	if !unixSeconds.MatchString(timestamp) {
		return false
	}
	signedAt, _ := strconv.ParseInt(timestamp, 10, 64)
	if age := now.Unix() - signedAt; age > 300 || age < -300 {
		return false
	}

	mac := hmac.New(sha256.New, key)
	mac.Write([]byte(id + "." + timestamp + "."))
	mac.Write(body)
	expected := mac.Sum(nil)

	// Several "v1,<base64>" entries during a secret rotation: any one may match.
	for _, entry := range strings.Split(signature, " ") {
		version, encoded, _ := strings.Cut(entry, ",")
		given, err := base64.StdEncoding.DecodeString(encoded)
		if version == "v1" && err == nil && hmac.Equal(given, expected) {
			return true
		}
	}

	return false
}
