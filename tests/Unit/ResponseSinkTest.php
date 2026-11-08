<?php

declare(strict_types=1);

use RoundlyConsulting\Webhooks\Support\ResponseSink;

it('keeps at most its limit and reports every byte written', function (): void {
    $sink = ResponseSink::open(5);

    expect(fwrite($sink, 'abc'))->toBe(3)
        ->and(fwrite($sink, 'defgh'))->toBe(5)
        ->and(fstat($sink)['size'])->toBe(5);

    rewind($sink);
    expect(fread($sink, 2))->toBe('ab')
        ->and(stream_get_contents($sink))->toBe('cde')
        ->and(feof($sink))->toBeTrue()
        ->and(fflush($sink))->toBeTrue();

    expect(fseek($sink, -1, SEEK_END))->toBe(0)->and(fread($sink, 9))->toBe('e')
        ->and(fseek($sink, -2, SEEK_CUR))->toBe(0)->and(fread($sink, 9))->toBe('de')
        ->and(fseek($sink, 6))->toBe(-1)
        ->and(fseek($sink, -1))->toBe(-1);

    fclose($sink);
});

it('opens independent sinks, a zero limit keeping nothing', function (): void {
    $empty = ResponseSink::open(0);
    $other = ResponseSink::open(3);

    fwrite($empty, 'lost');
    fwrite($other, 'kept');
    rewind($empty);
    rewind($other);

    expect(stream_get_contents($empty))->toBe('')->and(stream_get_contents($other))->toBe('kep');
});
