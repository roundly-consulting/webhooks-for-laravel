<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Http;

use Illuminate\Http\JsonResponse;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;

/**
 * RFC 9457 problem details for a refused inbound request: a translated title, the status and a
 * stable code — no internal detail, no class names. A disabled endpoint answers exactly like an
 * unknown one.
 *
 * @internal
 */
final class Problem
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function response(RejectionReason $reason, array $headers = []): JsonResponse
    {
        $code = $reason->publicCode();
        $title = trans('webhooks::problems.'.$code);

        return new JsonResponse([
            'title' => is_string($title) ? $title : $code,
            'status' => $reason->status(),
            'code' => $code,
        ], $reason->status(), ['Content-Type' => 'application/problem+json', ...$headers]);
    }

    public static function accepted(string $id): JsonResponse
    {
        return new JsonResponse(['status' => 'accepted', 'id' => $id], 202);
    }

    public static function duplicate(): JsonResponse
    {
        return new JsonResponse(['status' => 'duplicate'], 200);
    }
}
