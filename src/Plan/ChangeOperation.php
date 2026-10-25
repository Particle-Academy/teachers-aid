<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Plan;

/**
 * One proposed change. Data only — nothing here touches the database.
 *
 * `ref` is what makes a multi-entity plan possible before anything exists: the
 * model can say "create a course as $course1, then add a lesson to $course1"
 * in a single turn, and PlanApplier resolves the reference once the real id
 * is known.
 */
final class ChangeOperation
{
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const ATTACH = 'attach';
    public const DETACH = 'detach';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly string $action,
        public readonly string $entity,
        public readonly array $attributes = [],
        public readonly ?int $id = null,
        public readonly ?string $ref = null,
        public readonly ?string $summary = null,
    ) {
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(string $entity, array $attributes, ?string $ref = null, ?string $summary = null): self
    {
        return new self(self::CREATE, $entity, $attributes, null, $ref, $summary);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function update(string $entity, int $id, array $attributes, ?string $summary = null): self
    {
        return new self(self::UPDATE, $entity, $attributes, $id, null, $summary);
    }

    public static function delete(string $entity, int $id, ?string $summary = null): self
    {
        return new self(self::DELETE, $entity, [], $id, null, $summary);
    }

    /**
     * Link two records through a many-to-many relation — a course into a
     * curriculum, say. Either side may be a "$ref" to a record created earlier
     * in the plan, which is why the owner travels in the attributes rather than
     * in `id`: an id is only ever a real one.
     *
     * @param  array<string, mixed>  $pivot
     */
    public static function attach(string $entity, int|string $owner, string $relation, int|string $related, array $pivot = [], ?string $summary = null): self
    {
        return new self(self::ATTACH, $entity, array_filter([
            'owner'    => $owner,
            'relation' => $relation,
            'related'  => $related,
            'pivot'    => $pivot,
        ], static fn ($v) => $v !== []), null, null, $summary);
    }

    public static function detach(string $entity, int|string $owner, string $relation, int|string $related, ?string $summary = null): self
    {
        return new self(self::DETACH, $entity, [
            'owner'    => $owner,
            'relation' => $relation,
            'related'  => $related,
        ], null, null, $summary);
    }

    /** A one-line description for the review UI. */
    public function describe(): string
    {
        if ($this->summary !== null) {
            return $this->summary;
        }

        $what = $this->attributes['title'] ?? $this->attributes['prompt'] ?? $this->attributes['label'] ?? null;

        return match ($this->action) {
            self::CREATE => "Create {$this->entity}".($what ? ": {$what}" : ''),
            self::UPDATE => "Update {$this->entity} #{$this->id}".($what ? ": {$what}" : ''),
            self::DELETE => "Delete {$this->entity} #{$this->id}",
            self::ATTACH => "Add {$this->relationTarget()} to {$this->entity} {$this->label($this->attributes['owner'] ?? null)}",
            self::DETACH => "Remove {$this->relationTarget()} from {$this->entity} {$this->label($this->attributes['owner'] ?? null)}",
            default      => "{$this->action} {$this->entity}",
        };
    }

    private function relationTarget(): string
    {
        return ($this->attributes['relation'] ?? 'record').' '.$this->label($this->attributes['related'] ?? null);
    }

    private function label(mixed $idOrRef): string
    {
        return is_string($idOrRef) && str_starts_with($idOrRef, '$') ? $idOrRef : '#'.$idOrRef;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'action'      => $this->action,
            'entity'      => $this->entity,
            'attributes'  => $this->attributes,
            'id'          => $this->id,
            'ref'         => $this->ref,
            // The raw summary as well as the rendered description: fromArray()
            // reads `summary`, and without it a stored plan came back with
            // every reviewer note ("— duplicate of #4") silently dropped.
            'summary'     => $this->summary,
            'description' => $this->describe(),
        ], static fn ($v) => $v !== null && $v !== []);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            action: (string) ($data['action'] ?? self::CREATE),
            entity: (string) ($data['entity'] ?? ''),
            attributes: (array) ($data['attributes'] ?? []),
            id: isset($data['id']) ? (int) $data['id'] : null,
            ref: $data['ref'] ?? null,
            summary: $data['summary'] ?? null,
        );
    }
}
