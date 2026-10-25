<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Plan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ParticleAcademy\TeachersAid\Contracts\GuardsDeletion;
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

    /** entity name => [relation method => related entity name] */
    private array $relations;

    /** @var array<string, bool> table => has an is_published column */
    private array $publishable = [];

    public function __construct(
        ?array $entities = null,
        ?array $relations = null,
        private readonly ?GuardsDeletion $deletions = null,
    ) {
        $this->entities = $entities ?? (array) config('teachers-aid.entities', []);
        $this->relations = $relations ?? (array) config('teachers-aid.relations', []);
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
            ChangeOperation::ATTACH,
            ChangeOperation::DETACH => $this->link($class, $op, $attributes),
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
        /** @var Model $model */
        $model = new $class();

        // Draft, always — but only where "published" is a thing. Lessons,
        // questions and options have no is_published column; forcing the
        // attribute on them fails the insert on a column that does not exist.
        if ($this->isPublishable($model)) {
            $attributes['is_published'] = false;
        }

        $model = $class::query()->create($attributes);

        if ($op->ref !== null) {
            $refs[$op->ref] = (int) $model->getKey();
        }
    }

    /**
     * Whether this entity has something to publish.
     *
     * Memoised per table: the applier runs inside one transaction and a schema
     * lookup per operation would make a large plan noticeably slower.
     */
    private function isPublishable(Model $model): bool
    {
        $table = $model->getTable();

        return $this->publishable[$table] ??= $model->getConnection()
            ->getSchemaBuilder()
            ->hasColumn($table, 'is_published');
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

        if ($model === null) {
            // Already gone is the desired end state, so this is not an error.
            return;
        }

        // Asked again here, not only when proposed: an enrollment may have
        // arrived between the proposal and the click.
        $refusal = $this->deletions?->refusal($op->entity, $model);

        if ($refusal !== null) {
            throw new PlanApplicationException("{$op->entity} #{$op->id} cannot be deleted: {$refusal}");
        }

        $model->delete();
    }

    /**
     * Attach or detach through a many-to-many relation.
     *
     * Only relations named in `teachers-aid.relations` are reachable, for the
     * same reason only configured entities are: the config is the blast
     * radius. A relation name from a plan is never called otherwise.
     *
     * @param  class-string<Model>  $class
     * @param  array<string, mixed>  $attributes  owner/related already resolved from refs
     */
    private function link(string $class, ChangeOperation $op, array $attributes): void
    {
        $relation = (string) ($attributes['relation'] ?? '');
        $relatedEntity = $this->relations[$op->entity][$relation] ?? null;

        if ($relatedEntity === null) {
            throw new PlanApplicationException(
                "[{$op->entity}.{$relation}] is not a configured relation. Add it to config('teachers-aid.relations')."
            );
        }

        $owner = $class::query()->find($attributes['owner'] ?? null);

        if ($owner === null) {
            throw new PlanApplicationException("{$op->entity} #".($attributes['owner'] ?? '?').' no longer exists.');
        }

        $relatedClass = $this->entities[$relatedEntity] ?? null;
        $relatedId = $attributes['related'] ?? null;

        if ($relatedClass === null || ! $relatedClass::query()->whereKey($relatedId)->exists()) {
            throw new PlanApplicationException("{$relatedEntity} #{$relatedId} no longer exists.");
        }

        if ($op->action === ChangeOperation::DETACH) {
            $owner->{$relation}()->detach($relatedId);

            return;
        }

        // syncWithoutDetaching: adding a course that is already there updates
        // its pivot rather than failing on the unique key or duplicating it.
        $owner->{$relation}()->syncWithoutDetaching([$relatedId => (array) ($attributes['pivot'] ?? [])]);
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
