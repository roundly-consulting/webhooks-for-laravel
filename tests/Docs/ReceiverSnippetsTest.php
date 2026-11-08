<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Tests\Support\LocalReceiver;

/*
 * The receiver guide's verification snippets (docs/packages/webhooks-for-laravel/technical/
 * receiving-our-webhooks.md copies tests/Fixtures/receivers/verify.* verbatim), run: PHP in
 * process, Node / Python / Go / Ruby as real programs, against the contract vectors V-1…V-3 and a
 * delivery this package really sent to a local receiver. A missing runtime skips locally and
 * fails on CI (CI=true).
 */

/**
 * @return array<string, array{0: list<string>, 1: string}> runtime => [command, version probe]
 */
function receiverRuntimes(): array
{
    $dir = realpath(__DIR__.'/../Fixtures/receivers');

    return [
        'node' => [['node', $dir.'/harness/node.js'], 'node --version'],
        'python' => [['python3', '-I', '-B', $dir.'/harness/python.py'], 'python3 --version'],
        'ruby' => [['ruby', $dir.'/harness/ruby.rb'], "ruby -ropenssl -e 'exit(OpenSSL.respond_to?(:secure_compare) ? 0 : 1)'"],
        'go' => [['go', 'run', 'main.go', 'verify.go'], 'go version'],
    ];
}

/**
 * @param  array{secret: string, id: string, timestamp: string, signature: string, body: string, now: int}  $case
 */
function runReceiver(string $runtime, array $case): string
{
    if ($runtime === 'php') {
        require_once __DIR__.'/../Fixtures/receivers/verify.php';

        return verifyWebhook($case['secret'], $case['id'], $case['timestamp'], $case['signature'], $case['body'], $case['now']) ? 'valid' : 'invalid';
    }

    [$command, $probe] = receiverRuntimes()[$runtime];

    if (! Process::run($probe)->successful()) {
        if (getenv('CI') === 'true') {
            test()->fail("The {$runtime} receiver snippet must run on CI: the runtime is missing.");
        }

        test()->markTestSkipped("{$runtime} is not installed (or too old)");
    }

    $cwd = null;

    if ($runtime === 'go') {
        $cwd = sys_get_temp_dir().'/webhooks-go-receiver-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($cwd);
        File::copy(__DIR__.'/../Fixtures/receivers/harness/go/main.go', $cwd.'/main.go');
        File::copy(__DIR__.'/../Fixtures/receivers/verify.go', $cwd.'/verify.go');
    }

    try {
        $result = Process::path($cwd ?? __DIR__)->timeout(120)
            ->input(json_encode([...$case, 'body' => base64_encode($case['body'])], JSON_THROW_ON_ERROR))
            ->run($command);
    } finally {
        if ($cwd !== null) {
            File::deleteDirectory($cwd);
        }
    }

    expect($result->errorOutput())->toBe('');

    return trim($result->output());
}

/**
 * @return list<array{0: string, 1: array{secret: string, id: string, timestamp: string, signature: string, body: string, now: int}, 2: string}>
 */
function receiverCases(): array
{
    $contract = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/contract/default-scheme.json'), true, flags: JSON_THROW_ON_ERROR);
    $cases = [];

    foreach ($contract['vectors'] as $vector) {
        $case = ['id' => $vector['id'], 'timestamp' => $vector['timestamp'], 'signature' => $vector['signature'], 'body' => $vector['body'], 'now' => (int) $vector['timestamp']];

        foreach ($vector['secrets'] as $secret) {
            $cases[] = [$vector['name'], [...$case, 'secret' => $secret], 'valid'];
        }

        $secret = $vector['secrets'][0];
        $cases[] = [$vector['name'].' tampered', [...$case, 'secret' => $secret, 'body' => $vector['body'].' '], 'invalid'];
        $cases[] = [$vector['name'].' 301 s later', [...$case, 'secret' => $secret, 'now' => (int) $vector['timestamp'] + 301], 'invalid'];
        $cases[] = [$vector['name'].' 300 s early', [...$case, 'secret' => $secret, 'now' => (int) $vector['timestamp'] - 300], 'valid'];
    }

    // A plain (imported) secret is used as its own bytes.
    $plain = ['id' => 'msg_plain', 'timestamp' => '1794139200', 'body' => '{"a":"ü"}', 'now' => 1794139200, 'secret' => 'plain-shared-secret'];
    $cases[] = ['plain secret', [...$plain, 'signature' => 'v1,'.base64_encode(hash_hmac('sha256', 'msg_plain.1794139200.{"a":"ü"}', 'plain-shared-secret', true))], 'valid'];
    $cases[] = ['unknown version only', [...$plain, 'signature' => 'v1a,'.base64_encode(hash_hmac('sha256', 'msg_plain.1794139200.{"a":"ü"}', 'plain-shared-secret', true))], 'invalid'];
    $cases[] = ['not a timestamp', [...$plain, 'timestamp' => '17941392OO', 'signature' => 'v1,x'], 'invalid'];

    return $cases;
}

it('every receiver snippet verifies the contract vectors', function (string $runtime): void {
    foreach (receiverCases() as [$name, $case, $expected]) {
        expect(runReceiver($runtime, $case))->toBe($expected, "{$runtime}: {$name}");
    }
})->with(['php', 'node', 'python', 'ruby', 'go']);

it('every receiver snippet verifies a delivery this package sent', function (string $runtime): void {
    if (! LocalReceiver::available()) {
        $this->markTestSkipped('needs proc_open and ext-curl');
    }

    Queue::fake();
    config()->set('webhooks.outbound.allow_http', true);
    config()->set('webhooks.ssrf.allowed_ranges', ['127.0.0.0/8']);
    publicDns(['hooks.invalid' => '127.0.0.1']);
    $receiver = LocalReceiver::start();

    try {
        $created = Webhooks::outbound()->create("http://hooks.invalid:{$receiver->port}/in");
        Webhooks::outbound()->rotateSecret($created->endpoint);   // two v1 entries, as during a rotation
        attempt(Webhooks::send($created->endpoint, 'invoice.paid', ['invoice' => 42, 'note' => 'ünïcode / "quotes"']));
        [$request] = $receiver->requests();
    } finally {
        $receiver->stop();
    }

    $case = [
        'secret' => $created->secret,   // the previous secret: still valid during the grace
        'id' => $request['headers']['webhook-id'],
        'timestamp' => $request['headers']['webhook-timestamp'],
        'signature' => $request['headers']['webhook-signature'],
        'body' => $request['body'],
        'now' => (int) $request['headers']['webhook-timestamp'],
    ];

    expect(substr_count($case['signature'], 'v1,'))->toBe(2)
        ->and(runReceiver($runtime, $case))->toBe('valid')
        ->and(runReceiver($runtime, [...$case, 'secret' => 'whsec_'.base64_encode(str_repeat('x', 32))]))->toBe('invalid');
})->with(['php', 'node', 'python', 'ruby', 'go']);
