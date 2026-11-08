// Reads {secret, id, timestamp, signature, body (base64), now} as JSON on stdin; prints valid / invalid.
// Run with the snippet beside it: go run main.go verify.go (the test copies verify.go here first).
package main

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"time"
)

type input struct {
	Secret    string `json:"secret"`
	ID        string `json:"id"`
	Timestamp string `json:"timestamp"`
	Signature string `json:"signature"`
	Body      string `json:"body"`
	Now       int64  `json:"now"`
}

func main() {
	var data input
	if err := json.NewDecoder(os.Stdin).Decode(&data); err != nil {
		fmt.Print("error")
		os.Exit(1)
	}
	body, _ := base64.StdEncoding.DecodeString(data.Body)
	if VerifyWebhook(data.Secret, data.ID, data.Timestamp, data.Signature, body, time.Unix(data.Now, 0)) {
		fmt.Print("valid")
	} else {
		fmt.Print("invalid")
	}
}
