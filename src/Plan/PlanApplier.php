<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Plan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ParticleAcademy\TeachersAid\Exceptions\PlanApplicationException;

/**
 * Turns an approved plan into rows. The only thing in this package that writes.
 *
 * Three properties that matter:
 *
 *  - ALL OR NOTHING. One transaction. A plan that fails halfway leaves nothing
 *    behind, because a half-built course is worse than no course.
 *  - NEVER PUBLISHES. is_published is forced to false on create and stripped on
 *    update, whatever the plan says. A human publishes. The agent proposed this
 *    content and an approver skim-read it; neither is a substitute for someone
 *    deciding it should go live.
 *  - REFERENCES RESOLVE FORWARD. "$course1" in a later operation becomes the id
 *    of the entity created earlier in the same plan.
 */
class PlanApplier
{
    /** entity name => model class */
    private array $entities;

    public function __construct(?array $entities = null)
    {
        $this->entities = $entities ?? (array) config('teachers-aid.entities', []);
    }

    /**
     * @return array<string, int>  ref => created id, for the caller to link to
     *
     * @throws PlanApplicationException
     */
    public function apply(ChangePlan $plan): array
    {
        if ($plan->isEmpty()) {
            return [];
        }

        return DB::transaction(function () use ($plan): array {
            $refs = [];

            foreach ($plan->operations() as $i => $op) {
                try {
                    $this->applyOne($op, $refs);
                } catch (PlanApplicationException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    throw new PlanApplicationException(
                        "Operation {$i} ({$op->describe()}) failed: {$e->getMessage()}",
                        previous: $e,
                    );
                }
            }

            return $refs;
        });
    }

    /**
     * @param  array<string, int>  $refs
     */
    private function applyOne(ChangeOperation $op, array &$refs): void
    {
        $class = $this->entities[$op->entity] ?? null;

        if ($class === null || ! class_exists($class)) {
            throw new PlanApplicationException(
                "Unknown entity [{$op->entity}]. Add it to config('teachers-aid.entities')."
            );
        }

        $attributes = $this->resolveRefs($op->attributes, $refs);

        match ($op->action) {
            ChangeOperation::CREATE => $this->create($class, $op, $attributes, $refs),
            ChangeOperation::UPDATE => $this->update($class, $op, $attributes),
            ChangeOperation::DELETE => $this->delete($class, $op),
            default => throw new PlanApplicationException("Unknown action [{$op->action}]."),
        };
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int>  $refs
     */
    private function create(string $class, ChangeOperation $op, array $attributes, array &$refs): void
    {
        // Draft, always. See the class docblock.
        $attributes['is_published'] = false;

        /** @var Model $model */
        $model = $class::query()->create($attributes);

        if ($op->ref !== null) {
            $refs[$op->ref] = (int) $model->getKey();
        }
    }

    /**
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $attributes
     */
    private function update(string $class, ChangeOperation $op, array $attributes): void
    {
        // Publishing is not something an approved plan can do — that is a
        // separate, deliberate human action.
        unset($attributes['is_published']);

        if ($attributes === []) {
            return;
        }

        $model = $class::query()->find($op->id);

        if ($model === null) {
            throw new PlanApplicationException("{$op->entity} #{$op->id} no longer exists.");
        }

        $model->fill($attributes)->save();
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function delete(string $class, ChangeOperation $op): void
    {
        $model = $class::query()->find($op->id);

        // Already gone is the desired end state, so this is not an error.
        $model?->delete();
    }

    /**
     * Replace "$ref" values with the ids created earlier in this plan.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, int>  $refs
     * @return array<string, mixed>
     */
    private function resolveRefs(array $attributes, array $refs): array
    {
        foreach ($attributes as $key => $value) {
            if (! is_string($value) || ! str_starts_with($value, '$')) {
                continue;
            }

            $name = substr($value, 1);

            if (! array_key_exists($name, $refs)) {
                throw new PlanApplicationException(
                    "Reference \${$name} was used before anything created it."
                );
            }

            $attributes[$key] = $refs[$name];
        }

        return $attributes;
    }
}
