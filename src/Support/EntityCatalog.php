<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What the agent may see: the configured entities, their columns and their
 * relations. READ-ONLY — it builds queries and never saves, so handing it to
 * a tool gives that tool eyes, not hands. PlanApplier is still the only writer.
 */
final class EntityCatalog
{
    /** entity name => model class */
    private array $entities;

    /** entity name => [relation method => related entity name] */
    private array $relations;

    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(?array $entities = null, ?array $relations = null)
    {
        $this->entities = $entities ?? (array) config('teachers-aid.entities', []);
        $this->relations = $relations ?? (array) config('teachers-aid.relations', []);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->entities);
    }

    public function has(string $entity): bool
    {
        $class = $this->entities[$entity] ?? null;

        return $class !== null && class_exists($class);
    }

    /** @return class-string<Model> */
    public function classOf(string $entity): string
    {
        return $this->entities[$entity];
    }

    public function query(string $entity): Builder
    {
        return $this->classOf($entity)::query();
    }

    public function find(string $entity, int|string|null $id): ?Model
    {
        return $id === null ? null : $this->query($entity)->find($id);
    }

    /** @return list<string> */
    public function columns(string $entity): array
    {
        if (! isset($this->columns[$entity])) {
            /** @var Model $model */
            $model = new ($this->classOf($entity))();

            $this->columns[$entity] = array_values($model->getConnection()
                ->getSchemaBuilder()
                ->getColumnListing($model->getTable()));
        }

        return $this->columns[$entity];
    }

    public function hasColumn(string $entity, string $column): bool
    {
        return in_array($column, $this->columns($entity), true);
    }

    /** @return array<string, string> relation method => related entity */
    public function relationsOf(string $entity): array
    {
        return (array) ($this->relations[$entity] ?? []);
    }

    public function relatedEntity(string $entity, string $relation): ?string
    {
        return $this->relations[$entity][$relation] ?? null;
    }
}
