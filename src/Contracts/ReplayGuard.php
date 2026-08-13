<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Contracts;

/**
 * Remembers delivery ids long enough to refuse a second delivery.
 *
 * A timestamp window alone bounds replay; it does not stop it. Anyone holding a
 * captured request can send it again inside the window, and the signature is
 * still perfectly valid — that is the step most implementations skip.
 */
interface ReplayGuard
{
    /**
     * Claim an id. Returns false when it has already been seen.
     */
    public function claim(string $id, int $ttlSeconds): bool;
}
