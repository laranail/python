<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Enums;

/**
 * Why a call failed, in terms a caller can branch on without parsing a message.
 */
enum ErrorCode: string
{
    case UnknownService = 'unknown_service';
    case Unreachable = 'unreachable';
    case HttpError = 'http_error';
    case Timeout = 'timeout';
    case ProcessFailed = 'process_failed';
    case InvalidPayload = 'invalid_payload';
    case ResponseTooLarge = 'response_too_large';
    case Disabled = 'disabled';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::Unreachable, self::Timeout => true,
            default => false,
        };
    }
}
