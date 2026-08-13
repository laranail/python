<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Exceptions;

class InvalidPayloadException extends PythonException
{
    public static function notEncodable(string $reason): self
    {
        return new self(
            message: "The payload could not be encoded as JSON: {$reason}",
            code: 3004,
        );
    }

    public static function notDecodable(string $reason): self
    {
        return new self(
            message: "The response was not valid JSON: {$reason}",
            code: 3005,
        );
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self(
            message: "The response is {$bytes} bytes, over the {$limit}-byte limit. "
                . 'Raise laranail.python.defaults.max_response_bytes if this is expected.',
            code: 3006,
            context: ['bytes' => $bytes, 'limit' => $limit],
        );
    }
}
