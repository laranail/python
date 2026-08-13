<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Callbacks;

use Simtabi\Laranail\Python\Enums\TaskStatus;

/**
 * A verified inbound callback.
 *
 * Only the verifier constructs one, so holding an envelope IS the proof that
 * the signature, the timestamp window and the replay check all passed. Nothing
 * downstream has to re-check, and nothing downstream can forget to.
 */
final readonly class CallbackEnvelope
{
    /**
     * @param array<array-key, mixed> $payload
     */
    public function __construct(
        public string $id,
        public int $timestamp,
        public array $payload,
        public ?string $taskId = null,
        public TaskStatus $status = TaskStatus::Succeeded,
    ) {}

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }
}
