<?php

declare(strict_types=1);

namespace Coyotito\Ipp;

use Coyotito\Ipp\Enums\DelimiterTag;
use Coyotito\Ipp\Enums\JobState;
use Coyotito\Ipp\Protocol\AttributeGroup;

/**
 * A submitted (or referenced) print job. Returned by {@see PendingPrint::send()}
 * or {@see Printer::job()}. Holds the last known job-attributes and can refresh
 * them, report state, and cancel.
 */
final class Job
{
    public function __construct(
        private readonly Printer $printer,
        public readonly int $id,
        private ?AttributeGroup $attributes = null,
    ) {}

    public function attributes(): ?AttributeGroup
    {
        return $this->attributes;
    }

    /**
     * Re-fetch the job's attributes (Get-Job-Attributes).
     *
     * @param  array<int,string>  $requested
     */
    public function refresh(array $requested = ['job-state', 'job-state-reasons']): self
    {
        $response = $this->printer->exchange(
            $this->printer->operations()->getJobAttributes($this->printer->uri(), $this->id, $requested)
        );

        $this->attributes = $response->group(DelimiterTag::JobAttributes);

        return $this;
    }

    /**
     * Cached job-state (fetches once if not loaded yet).
     */
    public function state(): ?JobState
    {
        if (! $this->attributes instanceof AttributeGroup) {
            $this->refresh();
        }

        $value = $this->attributes?->get('job-state')?->firstValue();

        return is_int($value) ? JobState::tryFrom($value) : null;
    }

    /**
     * Fetch the latest state and return it (the live "what's happening now").
     */
    public function status(): ?JobState
    {
        return $this->refresh()->state();
    }

    /**
     * @return array<int,string>
     */
    public function stateReasons(): array
    {
        $reasons = $this->attributes?->get('job-state-reasons')?->values ?? [];

        return array_values(array_map(static fn (mixed $v): string => (string) $v, $reasons));
    }

    public function isCompleted(): bool
    {
        return $this->state() === JobState::Completed;
    }

    public function isTerminal(): bool
    {
        return $this->state()?->isTerminal() ?? false;
    }

    /**
     * Cancel the job (Cancel-Job). Returns true on a successful status.
     */
    public function cancel(): bool
    {
        return $this->printer
            ->exchange($this->printer->operations()->cancelJob($this->printer->uri(), $this->id))
            ->isSuccessful();
    }
}
