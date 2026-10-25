<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Agent;

use Illuminate\Database\Eloquent\Model;
use ParticleAcademy\TeachersAid\Chat\ToolDefinition;
use ParticleAcademy\TeachersAid\Support\EntityCatalog;

/**
 * The agent's eyes: look at the catalog as it is now.
 *
 * Without these it could only add. Updating, deleting or linking needs an id,
 * and it had no way to learn one — so every "change the Trespass course" turned
 * into a proposal to create a second Trespass course.
 *
 * These READ and never write. A prompt injection that reaches them can make the
 * agent look at records an admin can already see in the admin, and nothing
 * else; changing anything still needs a proposal and a human pressing Apply.
 */
class CatalogTools
{
    public const NAMES = ['catalog_overview', 'catalog_search', 'catalog_get'];

    /** Search results and related rows truncate long text to this. */
    private const SNIPPET = 160;

    /** A single record's own long text (a lesson body) truncates to this. */
    private const FULL = 8000;

    private const HIDDEN = ['created_at', 'updated_at', 'deleted_at'];

    public function __construct(private readonly EntityCatalog $catalog)
    {
    }

    /** @return list<ToolDefinition> */
    public function all(): array
    {
        return [$this->overview(), $this->search(), $this->get()];
    }

    private function overview(): ToolDefinition
    {
        return ToolDefinition::make(
            'catalog_overview',
            'What exists: every kind of record you can work with, its fields, how many there are, '
            .'and which relations link them. Call this first when you need field names.',
            [],
            [],
            function (): string {
                $out = [];

                foreach ($this->catalog->names() as $entity) {
                    if (! $this->catalog->has($entity)) {
                        continue;
                    }

                    $out[$entity] = [
                        'count'     => $this->catalog->query($entity)->count(),
                        'fields'    => array_values(array_diff($this->catalog->columns($entity), self::HIDDEN)),
                        'relations' => $this->catalog->relationsOf($entity) ?: null,
                    ];
                }

                return $this->json($out);
            },
        );
    }

    private function search(): ToolDefinition
    {
        return ToolDefinition::make(
            'catalog_search',
            'Find existing records. Filter on exact field values (e.g. {"course_id": 12} for a '
            .'course\'s lessons, {"delivery_mode": "in_person"}) and/or match text in the title. '
            .'Returns ids you can then pass to catalog_get or a proposal.',
            [
                'entity'  => ['type' => 'string', 'enum' => $this->catalog->names()],
                'query'   => ['type' => 'string', 'description' => 'Case-insensitive text to look for in the title/name/prompt.'],
                'filters' => ['type' => 'object', 'description' => 'Field => exact value.'],
                'limit'   => ['type' => 'integer', 'description' => 'Default 25, at most 100.'],
                'offset'  => ['type' => 'integer'],
            ],
            ['entity'],
            function (array $a): string {
                $entity = (string) ($a['entity'] ?? '');

                if (! $this->catalog->has($entity)) {
                    return "Refused: unknown entity [{$entity}].";
                }

                $query = $this->catalog->query($entity);

                foreach ((array) ($a['filters'] ?? []) as $field => $value) {
                    if (! $this->catalog->hasColumn($entity, (string) $field)) {
                        return "Refused: {$entity} has no field [{$field}]. Use catalog_overview for field names.";
                    }

                    if (! is_scalar($value) && $value !== null) {
                        return "Refused: filter [{$field}] must be a single value.";
                    }

                    $query->where((string) $field, $value);
                }

                $text = trim((string) ($a['query'] ?? ''));

                if ($text !== '') {
                    $fields = array_values(array_intersect(['title', 'name', 'prompt', 'label', 'slug'], $this->catalog->columns($entity)));

                    if ($fields === []) {
                        return "Refused: {$entity} has no text field to search. Use filters instead.";
                    }

                    $needle = '%'.mb_strtolower($text).'%';
                    $query->where(function ($q) use ($fields, $needle): void {
                        foreach ($fields as $field) {
                            // lower() on both sides: LIKE is case-sensitive on
                            // PostgreSQL and not on SQLite or MySQL.
                            $q->orWhereRaw('lower('.$q->getGrammar()->wrap($field).') like ?', [$needle]);
                        }
                    });
                }

                $total = (clone $query)->count();
                $limit = max(1, min(100, (int) ($a['limit'] ?? 25)));
                $offset = max(0, (int) ($a['offset'] ?? 0));

                $order = $this->catalog->hasColumn($entity, 'sort_order') ? 'sort_order' : null;
                if ($order !== null) {
                    $query->orderBy($order);
                }

                $rows = $query->orderBy($query->getModel()->getKeyName())->offset($offset)->limit($limit)->get();

                return $this->json([
                    'total'  => $total,
                    'offset' => $offset,
                    'rows'   => $rows->map(fn (Model $m): array => $this->compact($m, self::SNIPPET))->all(),
                ]);
            },
        );
    }

    private function get(): ToolDefinition
    {
        return ToolDefinition::make(
            'catalog_get',
            'Read one record in full, optionally with records linked to it through a relation '
            .'(e.g. a curriculum\'s "courses"). For child records such as a course\'s lessons, use '
            .'catalog_search with a filter instead.',
            [
                'entity'  => ['type' => 'string', 'enum' => $this->catalog->names()],
                'id'      => ['type' => 'integer'],
                'include' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Relation names from catalog_overview.'],
            ],
            ['entity', 'id'],
            function (array $a): string {
                $entity = (string) ($a['entity'] ?? '');

                if (! $this->catalog->has($entity)) {
                    return "Refused: unknown entity [{$entity}].";
                }

                $record = $this->catalog->find($entity, $a['id'] ?? null);

                if ($record === null) {
                    return "Not found: {$entity} #".($a['id'] ?? '?').'.';
                }

                $out = $this->compact($record, self::FULL);

                foreach ((array) ($a['include'] ?? []) as $relation) {
                    $relation = (string) $relation;

                    if ($this->catalog->relatedEntity($entity, $relation) === null) {
                        return "Refused: [{$relation}] is not a relation of {$entity}.";
                    }

                    $out[$relation] = $record->{$relation}()->limit(200)->get()
                        ->map(fn (Model $m): array => $this->compact($m, self::SNIPPET))
                        ->all();
                }

                return $this->json($out);
            },
        );
    }

    /** @return array<string, mixed> */
    private function compact(Model $model, int $maxChars): array
    {
        $out = [];

        foreach ($model->attributesToArray() as $key => $value) {
            if (in_array($key, self::HIDDEN, true) || $value === null) {
                continue;
            }

            if (is_string($value) && mb_strlen($value) > $maxChars) {
                $value = mb_substr($value, 0, $maxChars).'… ['.mb_strlen($value).' chars total]';
            }

            $out[$key] = $value;
        }

        // A pivot row (a course's place in a curriculum) is part of the answer.
        if ($model->relationLoaded('pivot')) {
            $pivot = array_diff_key((array) $model->pivot?->attributesToArray(), array_flip(self::HIDDEN));
            if ($pivot !== []) {
                $out['pivot'] = $pivot;
            }
        }

        return $out;
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
