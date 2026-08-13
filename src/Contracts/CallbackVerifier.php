<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Contracts;

use Illuminate\Http\Request;
use Simtabi\Laranail\Python\Callbacks\CallbackEnvelope;
use Simtabi\Laranail\Python\Exceptions\CallbackVerificationException;

/**
 * Proves an inbound callback really came from a service holding the secret.
 */
interface CallbackVerifier
{
    /**
     * @throws CallbackVerificationException
     */
    public function verify(Request $request): CallbackEnvelope;

    /**
     * Sign a body the way a caller must, so the shipped scaffold and the
     * verifier cannot disagree about the string being signed.
     */
    public function sign(string $body, int $timestamp): string;
}
