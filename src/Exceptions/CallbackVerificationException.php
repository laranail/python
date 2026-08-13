<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Exceptions;

use Simtabi\Laranail\Python\Enums\RejectionReason;

/**
 * An inbound callback failed verification.
 *
 * The reason is carried structurally so the application can alert on it. It is
 * deliberately never rendered into the HTTP response.
 */
class CallbackVerificationException extends PythonException
{
    public function __construct(public readonly RejectionReason $reason, string $message)
    {
        parent::__construct($message, 3401, context: ['reason' => $reason->value]);
    }

    public static function because(RejectionReason $reason): self
    {
        return new self($reason, match ($reason) {
            RejectionReason::NoSecretConfigured => 'No callback secret is configured.',
            RejectionReason::MissingSignature => 'The request carried no signature header.',
            RejectionReason::MissingTimestamp => 'The request carried no timestamp header.',
            RejectionReason::SignatureMismatch => 'The signature did not match.',
            RejectionReason::TimestampOutOfRange => 'The timestamp is outside the tolerance window.',
            RejectionReason::Replayed => 'This delivery id has already been seen.',
            RejectionReason::BodyTooLarge => 'The request body is over the configured limit.',
        });
    }
}
