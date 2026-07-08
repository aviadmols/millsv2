<?php

namespace App\Modules\MillsSubscriptions\Concerns;

use App\Modules\MillsSubscriptions\Exceptions\IllegalTransitionException;
use App\Modules\MillsSubscriptions\Support\Timeline;
use BackedEnum;

/**
 * Guarded state machine (ARCHITECTURE.md §3, CLAUDE.md law #4). Only transitions
 * in the model's allowed table are legal; anything else throws (fail loud). Every
 * accepted move writes a Timeline (activity_events) row. `status` is
 * mass-assignment-guarded on consuming models, so transitionTo() is the ONLY
 * legal way to change status; the initial value is set via forceFill/insert.
 *
 * The consuming model MUST implement:
 *   - statusColumn(): string                       column holding the status
 *   - allowedTransitions(): array<string,list<BackedEnum>>
 *   - currentStatus(): BackedEnum
 *   - timelineSubscriptionId(): ?int
 *   - timelineCustomerId(): ?int
 */
trait HasGuardedStatus
{
    /**
     * Attempt a guarded transition. Same-state is an idempotent no-op; an illegal
     * move throws. On success the column is written, saved, and recorded.
     *
     * @param  array<string, mixed>  $context  extra detail for the Timeline event
     */
    public function transitionTo(BackedEnum $to, array $context = [], string $actor = Timeline::ACTOR_SYSTEM): static
    {
        $from = $this->currentStatus();

        if ($from->value === $to->value) {
            return $this;
        }

        $legal = $this->allowedTransitions()[$from->value] ?? [];

        $isLegal = false;
        foreach ($legal as $candidate) {
            if ($candidate->value === $to->value) {
                $isLegal = true;
                break;
            }
        }

        if (! $isLegal) {
            throw new IllegalTransitionException($this, $from, $to);
        }

        $this->{$this->statusColumn()} = $to->value;
        $this->save();

        Timeline::record(
            kind: Timeline::KIND_STATUS_CHANGED,
            details: array_merge([
                'model' => class_basename($this),
                'from' => $from->value,
                'to' => $to->value,
            ], $context),
            subscriptionId: $this->timelineSubscriptionId(),
            customerId: $this->timelineCustomerId(),
            actor: $actor,
        );

        return $this;
    }
}
