<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Support;

use JsonException;
use Simtabi\Laranail\Python\Exceptions\InvalidPayloadException;

/**
 * The package's only JSON boundary.
 *
 * Both directions throw rather than returning `null`, because a silent `null`
 * from `json_decode()` is indistinguishable from a legitimate `null` payload,
 * and `json_encode()` returning `false` on an unencodable value would otherwise
 * ship an empty body to a service that then reports a confusing validation
 * error instead of the real problem.
 *
 * Decoding checks the **size before parsing**. A runaway script printing two
 * gigabytes is a problem; calling `json_decode()` on it first is a guaranteed
 * out-of-memory rather than a catchable error.
 */
final class Json
{
    /** Deep enough for any realistic payload, shallow enough to bound recursion. */
    public const int MAX_DEPTH = 64;

    /**
     * @param array<array-key, mixed> $payload
     */
    public static function encode(array $payload): string
    {
        try {
            return json_encode(
                $payload,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $e) {
            throw InvalidPayloadException::notEncodable($e->getMessage());
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function decode(string $json, int $maxBytes = 0, int $depth = self::MAX_DEPTH): array
    {
        $bytes = strlen($json);

        if ($maxBytes > 0 && $bytes > $maxBytes) {
            throw InvalidPayloadException::tooLarge($bytes, $maxBytes);
        }

        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, max(1, $depth), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidPayloadException::notDecodable($e->getMessage());
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }
}
