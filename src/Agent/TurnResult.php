<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Agent;

use ParticleAcademy\TeachersAid\Plan\ChangePlan;

/** What one turn produced: something to read, and something to review. */
final class TurnResult
{
    public function __construct(
        public readonly string $reply,
        public readonly ChangePlan $plan,
        public readonly int $steps = 0,
        public readonly bool $hitStepLimit = false,
    ) {
    }

    public function hasProposals(): bool
    {
        return ! $this->plan->isEmpty();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reply'          => $this->reply,
            'plan'           => $this->plan->toArray(),
            'steps'          => $this->steps,
            'hit_step_limit' => $this->hitStepLimit,
        ];
    }
}
