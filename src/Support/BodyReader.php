<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use Psr\Http\Message\StreamInterface;
use RoundlyConsulting\Webhooks\DataTransferObjects\BodyExcerpt;

/**
 * A receiver's answer as storable text: reads at most `$cap + 1` bytes of the body stream (never
 * the whole body) and keeps at most `$cap` bytes cut at a character boundary — valid
 * UTF-8 (invalid sequences replaced), no NUL bytes. One byte past the cap says it was longer.
 *
 * @internal
 */
final class BodyReader
{
    private const int CHUNK = 8192;

    public static function read(StreamInterface $stream, int $cap): BodyExcerpt
    {
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $bytes = '';

        while (strlen($bytes) <= $cap && ! $stream->eof()) {
            $chunk = $stream->read(min(self::CHUNK, $cap + 1 - strlen($bytes)));

            if ($chunk === '') {
                break;
            }

            $bytes .= $chunk;
        }

        if ($bytes === '') {
            return new BodyExcerpt(null, false);
        }

        $text = mb_convert_encoding(mb_strcut($bytes, 0, $cap, 'UTF-8'), 'UTF-8', 'UTF-8');

        return new BodyExcerpt(str_replace("\0", "\u{FFFD}", $text), strlen($bytes) > $cap);
    }
}
