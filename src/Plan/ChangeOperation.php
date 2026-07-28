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
            default      => "{$this->action} {$this->entity}",
        };
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
