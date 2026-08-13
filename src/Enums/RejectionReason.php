<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Enums;

/**
 * Why an inbound callback was refused.
 *
 * This never reaches the wire. The HTTP response is a bare 401 with no detail,
 * because telling a caller *which* check failed tells an attacker which knob to
 * turn next. The reason travels on the PythonCallbackRejected event instead, so
 * the application can alert on it.
 */
enum RejectionReason: string
{
    case NoSecretConfigured = 'no_secret_configured';
    case MissingSignature = 'missing_signature';
    case MissingTimestamp = 'missing_timestamp';
    case SignatureMismatch = 'signature_mismatch';
    case TimestampOutOfRange = 'timestamp_out_of_range';
    case Replayed = 'replayed';
    case BodyTooLarge = 'body_too_large';
}
