<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Plan;

/**
 * What TAC proposes. Ordered, inspectable, and inert until applied.
 *
 * The agent's tools append to one of these instead of writing, which is what
 * makes "propose, review, apply" enforceable rather than a promise: the agent
 * has no path to the database at all.
 */
final class ChangePlan
{
    /** @var list<ChangeOperation> */
    private array $operations = [];

    public function add(ChangeOperation $operation): void
    {
        $this->operations[] = $operation;
    }

    /**
     * @return list<ChangeOperation>
     */
    public function operations(): array
    {
        return $this->operations;
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    public function count(): int
    {
        return count($this->operations);
    }

    /**
     * Counts per entity/action, for a review header like
     * "3 lessons created, 1 test updated".
     *
     * @return array<string, int>
     */
    public function summary(): array
    {
        $out = [];

        foreach ($this->operations as $op) {
            $key = "{$op->action} {$op->entity}";
            $out[$key] = ($out[$key] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operations' => array_map(static fn (ChangeOperation $o) => $o->toArray(), $this->operations),
            'summary'    => $this->summary(),
            'count'      => $this->count(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $plan = new self();

        foreach ((array) ($data['operations'] ?? []) as $op) {
            $plan->add(ChangeOperation::fromArray((array) $op));
        }

        return $plan;
    }
}
