<?php

declare(strict_types=1);

namespace Simtabi\Laranail\Python\Tasks;

use Illuminate\Contracts\Support\Arrayable;
use Simtabi\Laranail\Python\Enums\TaskStatus;

/**
 * A submitted long-running task.
 *
 * Serializable on purpose: the point of submitting is that the work outlives
 * the request, so the handle has to survive a queue payload or a database
 * column to be worth anything.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class TaskHandle implements Arrayable
{
    public function __construct(
        public string $id,
        public string $target,
        public TaskStatus $status = TaskStatus::Pending,
        public ?string $pollUrl = null,
        public int $submittedAt = 0,
    ) {}

    public function withStatus(TaskStatus $status): self
    {
        return new self($this->id, $this->target, $status, $this->pollUrl, $this->submittedAt);
    }

    public function isFinished(): bool
    {
        return $this->status->isFinished();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'target' => $this->target,
            'status' => $this->status->value,
            'poll_url' => $this->pollUrl,
            'submitted_at' => $this->submittedAt,
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: is_scalar($data['id'] ?? null) ? (string) $data['id'] : '',
            target: is_scalar($data['target'] ?? null) ? (string) $data['target'] : '',
            status: TaskStatus::tryFrom(is_scalar($data['status'] ?? null) ? (string) $data['status'] : '')
                ?? TaskStatus::Pending,
            pollUrl: is_string($data['poll_url'] ?? null) ? $data['poll_url'] : null,
            submittedAt: is_numeric($data['submitted_at'] ?? null) ? (int) $data['submitted_at'] : 0,
        );
    }
}
