<?php

declare(strict_types=1);

// The local receiver: logs every request (method, path, headers, body) as one JSON line, then
// answers with the status the path asks for (`/status/500`) — 200 otherwise.
$log = getenv('WEBHOOKS_SERVER_LOG');
$headers = function_exists('getallheaders') ? getallheaders() : [];
$body = (string) file_get_contents('php://input');

if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'method' => $_SERVER['REQUEST_METHOD'] ?? '',
        'path' => $_SERVER['REQUEST_URI'] ?? '',
        'headers' => array_change_key_case($headers, CASE_LOWER),
        'body' => $body,
    ])."\n", FILE_APPEND | LOCK_EX);
}

$status = preg_match('#^/status/(\d{3})#', $_SERVER['REQUEST_URI'] ?? '', $match) === 1 ? (int) $match[1] : 200;
http_response_code($status);
header('Content-Type: text/plain');
echo $status === 200 ? 'received' : 'status '.$status;
