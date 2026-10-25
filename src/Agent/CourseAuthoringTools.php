<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Agent;

use ParticleAcademy\TeachersAid\Chat\ToolDefinition;
use ParticleAcademy\TeachersAid\Contracts\GuardsDeletion;
use ParticleAcademy\TeachersAid\Plan\ChangeOperation;
use ParticleAcademy\TeachersAid\Plan\ChangePlan;
use ParticleAcademy\TeachersAid\Support\EntityCatalog;

/**
 * TAC's hands. Every tool records an intention; none touches the database.
 *
 * That is deliberate and structural: these tools append to a ChangePlan and
 * hold no writer. "Propose, then apply" cannot be bypassed by a confused model
 * or a prompt injection in an uploaded file, because there is no code path
 * from here to a write. PlanApplier is the only writer and a human runs it.
 *
 * They do READ, through EntityCatalog, to check a proposal before recording
 * it: a field that does not exist, an id that is not there or a "$ref" nothing
 * created would otherwise surface only when the teacher pressed Apply, as an
 * error about a plan the model can no longer revise. Refusing here, in the
 * conversation, lets it correct itself on the next step.
 */
class CourseAuthoringTools
{
    public const NAMES = [
        'propose_curriculum', 'propose_course', 'propose_module', 'propose_lesson',
        'propose_test', 'propose_question', 'propose_create', 'propose_update',
        'propose_delete', 'propose_attach', 'propose_detach',
    ];

    /** Never settable through a proposal: identity, bookkeeping, and going live. */
    private const FORBIDDEN = ['id', 'created_at', 'updated_at', 'deleted_at', 'is_published'];

    private readonly EntityCatalog $catalog;

    public function __construct(
        private readonly ChangePlan $plan,
        ?EntityCatalog $catalog = null,
        private readonly ?GuardsDeletion $deletions = null,
    ) {
        $this->catalog = $catalog ?? new EntityCatalog();
    }

    /**
     * @return list<ToolDefinition>
     */
    public function all(): array
    {
        return [
            $this->createCurriculum(),
            $this->createCourse(),
            $this->createModule(),
            $this->createLesson(),
            $this->createTest(),
            $this->createQuestion(),
            $this->createAny(),
            $this->updateEntity(),
            $this->deleteEntity(),
            $this->attach(),
            $this->detach(),
        ];
    }

    private function ref(): array
    {
        return [
            'type' => 'string',
            'description' => 'A short handle for this new record, e.g. "course1", so later '
                .'operations in the same plan can point at it before it has an id. '
                .'Reference it elsewhere as "$course1". Must be unique within the plan.',
        ];
    }

    private function idOrRef(string $what): array
    {
        // A string, not an integer-or-string union: a numeric id passed as a
        // string works everywhere, and a union is the part of JSON Schema a
        // provider is likeliest to reject.
        return ['type' => 'string', 'description' => "Existing {$what} id (e.g. \"12\"), or \"\$ref\" of one proposed in this plan."];
    }

    private function createCurriculum(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_curriculum',
            'Propose a new curriculum — a named programme that groups courses. Add courses to it '
            .'with propose_attach.',
            [
                'title'       => ['type' => 'string', 'description' => 'Learner-facing title.'],
                'description' => ['type' => 'string'],
                'price'       => ['type' => 'number', 'description' => 'Price for the whole programme. Omit if not sold as one.'],
                'currency'    => ['type' => 'string', 'description' => '3-letter code, e.g. USD.'],
                'ref'         => $this->ref(),
            ],
            ['title'],
            fn (array $a) => $this->propose(ChangeOperation::create('curriculum', $this->pick($a, [
                'title', 'description', 'price', 'currency',
            ]), $a['ref'] ?? null)),
        );
    }

    private function createCourse(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_course',
            'Propose a new course. delivery_mode: "online" (lessons and tests count), "in_person" '
            .'(taught live; attendance hours count, online material does not), or "hybrid" (both).',
            [
                'title'             => ['type' => 'string'],
                'description'       => ['type' => 'string'],
                'delivery_mode'     => ['type' => 'string', 'enum' => ['online', 'in_person', 'hybrid']],
                'price'             => ['type' => 'number', 'description' => '0 = free. Omit to leave unpriced ("contact us").'],
                'currency'          => ['type' => 'string', 'description' => '3-letter code, e.g. USD.'],
                'hours'             => ['type' => 'number', 'description' => 'Course length in hours.'],
                'attendance_hours'  => ['type' => 'number', 'description' => 'In-person hours to attend. Hybrid courses only need this.'],
                'is_required'       => ['type' => 'boolean', 'description' => 'A required course for the licence.'],
                'estimated_minutes' => ['type' => 'integer', 'description' => 'Total learner time online.'],
                'highlights'        => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Short selling points.'],
                'ref'               => $this->ref(),
            ],
            ['title'],
            fn (array $a) => $this->propose(ChangeOperation::create('course', $this->pick($a, [
                'title', 'description', 'delivery_mode', 'price', 'currency', 'hours',
                'attendance_hours', 'is_required', 'estimated_minutes', 'highlights',
            ]), $a['ref'] ?? null)),
        );
    }

    private function createModule(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_module',
            'Propose a module — a section of a course that groups lessons.',
            [
                'course_id'   => $this->idOrRef('course'),
                'title'       => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'sort_order'  => ['type' => 'integer'],
                'ref'         => $this->ref(),
            ],
            ['course_id', 'title'],
            fn (array $a) => $this->propose(ChangeOperation::create('module', $this->pick($a, [
                'course_id', 'title', 'description', 'sort_order',
            ]), $a['ref'] ?? null)),
        );
    }

    private function createLesson(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_lesson',
            'Propose a lesson inside a course (optionally inside one of its modules). Write the '
            .'actual teaching content, not a placeholder.',
            [
                'course_id'         => $this->idOrRef('course'),
                'module_id'         => $this->idOrRef('module'),
                'title'             => ['type' => 'string'],
                'content'           => ['type' => 'string', 'description' => 'The lesson body, in Markdown.'],
                'content_type'      => ['type' => 'string', 'enum' => ['text', 'video', 'mixed']],
                'video_url'         => ['type' => 'string'],
                'estimated_minutes' => ['type' => 'integer'],
                'sort_order'        => ['type' => 'integer'],
                'ref'               => $this->ref(),
            ],
            ['course_id', 'title'],
            fn (array $a) => $this->propose(ChangeOperation::create('lesson', $this->pick($a, [
                'course_id', 'module_id', 'title', 'content', 'content_type', 'video_url',
                'estimated_minutes', 'sort_order',
            ]), $a['ref'] ?? null)),
        );
    }

    private function createTest(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_test',
            'Propose a test or quiz. Attach it to a course, a module or a single lesson — all '
            .'three count toward completion.',
            [
                'course_id'     => $this->idOrRef('course'),
                'module_id'     => $this->idOrRef('module'),
                'lesson_id'     => $this->idOrRef('lesson'),
                'title'         => ['type' => 'string'],
                'description'   => ['type' => 'string'],
                'passing_score' => ['type' => 'integer', 'description' => 'Percentage, 0-100.'],
                'is_final'      => ['type' => 'boolean', 'description' => 'True if this is the course\'s final exam.'],
                'ref'           => $this->ref(),
            ],
            ['title'],
            function (array $a): string {
                if (! isset($a['course_id']) && ! isset($a['module_id']) && ! isset($a['lesson_id'])) {
                    return 'Refused: a test needs a course_id, module_id or lesson_id.';
                }

                return $this->propose(ChangeOperation::create('test', $this->pick($a, [
                    'course_id', 'module_id', 'lesson_id', 'title', 'description', 'passing_score', 'is_final',
                ]), $a['ref'] ?? null));
            },
        );
    }

    private function createQuestion(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_question',
            'Propose a question with its options. Exactly one option must be correct for '
            .'multiple_choice; multiple_select may have several.',
            [
                'test_id'     => $this->idOrRef('test'),
                'prompt'      => ['type' => 'string'],
                'type'        => ['type' => 'string', 'enum' => ['multiple_choice', 'multiple_select', 'true_false']],
                'points'      => ['type' => 'number'],
                'explanation' => ['type' => 'string', 'description' => 'Shown after answering.'],
                'options'     => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'label'      => ['type' => 'string'],
                            'is_correct' => ['type' => 'boolean'],
                        ],
                        'required' => ['label', 'is_correct'],
                    ],
                ],
            ],
            ['test_id', 'prompt', 'type', 'options'],
            function (array $a): string {
                $options = array_values((array) ($a['options'] ?? []));
                $correct = count(array_filter($options, fn ($o) => (bool) ($o['is_correct'] ?? false)));

                if ($options === [] || $correct === 0) {
                    return 'Refused: a question needs options and at least one correct one.';
                }

                if (($a['type'] ?? '') !== 'multiple_select' && $correct !== 1) {
                    return 'Refused: only multiple_select may have more than one correct option.';
                }

                $ref = $this->freshRef('q');
                $question = ChangeOperation::create('question', [
                    'test_id'     => $a['test_id'],
                    'prompt'      => $a['prompt'],
                    'type'        => $a['type'],
                    'points'      => $a['points'] ?? 1,
                    'explanation' => $a['explanation'] ?? null,
                ], $ref, "Create question: {$a['prompt']}");

                if (($refusal = $this->check($question)) !== null) {
                    return $refusal;
                }

                $this->plan->add($question);

                foreach ($options as $i => $option) {
                    $this->plan->add(ChangeOperation::create('question_option', [
                        'question_id' => '$'.$ref,
                        'label'       => $option['label'] ?? '',
                        'is_correct'  => (bool) ($option['is_correct'] ?? false),
                        'sort_order'  => $i,
                    ], summary: '  option: '.($option['label'] ?? '')));
                }

                return 'Recorded question and '.count($options).' options. Nothing has been saved — the teacher reviews the plan first.';
            },
        );
    }

    private function createAny(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_create',
            'Propose a new record of any kind not covered by a more specific tool above. Use '
            .'catalog_overview for the fields each kind has.',
            [
                'entity'     => ['type' => 'string', 'enum' => $this->catalog->names()],
                'attributes' => ['type' => 'object', 'description' => 'Field => value.'],
                'ref'        => $this->ref(),
            ],
            ['entity', 'attributes'],
            fn (array $a) => $this->propose(ChangeOperation::create(
                (string) $a['entity'],
                (array) $a['attributes'],
                $a['ref'] ?? null,
            )),
        );
    }

    private function updateEntity(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_update',
            'Propose changes to an EXISTING record. Only include fields that should change. Find '
            .'the id with catalog_search first. Publishing is not possible here — a human publishes.',
            [
                'entity'     => ['type' => 'string', 'enum' => $this->catalog->names()],
                'id'         => ['type' => 'integer'],
                'attributes' => ['type' => 'object', 'description' => 'Field => new value.'],
                'reason'     => ['type' => 'string', 'description' => 'Why, for the reviewer.'],
            ],
            ['entity', 'id', 'attributes'],
            fn (array $a) => $this->propose(ChangeOperation::update(
                (string) $a['entity'],
                (int) $a['id'],
                (array) $a['attributes'],
                $this->withReason("Update {$a['entity']} #{$a['id']}", $a['reason'] ?? null),
            )),
        );
    }

    private function deleteEntity(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_delete',
            'Propose deleting an existing record. Deleting a course or module also removes what is '
            .'inside it. Prefer an update when the record is merely out of date.',
            [
                'entity' => ['type' => 'string', 'enum' => $this->catalog->names()],
                'id'     => ['type' => 'integer'],
                'reason' => ['type' => 'string', 'description' => 'Why, for the reviewer.'],
            ],
            ['entity', 'id', 'reason'],
            fn (array $a) => $this->propose(ChangeOperation::delete(
                (string) $a['entity'],
                (int) $a['id'],
                $this->withReason("Delete {$a['entity']} #{$a['id']}", $a['reason'] ?? null),
            )),
        );
    }

    private function attach(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_attach',
            'Propose linking two records through a relation — e.g. adding a course to a '
            .'curriculum (entity "curriculum", relation "courses"). Relations are listed by '
            .'catalog_overview. Linking one that is already linked updates its pivot.',
            [
                'entity'     => ['type' => 'string', 'enum' => $this->catalog->names()],
                'id'         => $this->idOrRef('record'),
                'relation'   => ['type' => 'string'],
                'related_id' => $this->idOrRef('related record'),
                'pivot'      => ['type' => 'object', 'description' => 'Extra link fields, e.g. {"sort_order": 2, "is_required": true}.'],
            ],
            ['entity', 'id', 'relation', 'related_id'],
            fn (array $a) => $this->propose(ChangeOperation::attach(
                (string) $a['entity'],
                $a['id'],
                (string) $a['relation'],
                $a['related_id'],
                (array) ($a['pivot'] ?? []),
            )),
        );
    }

    private function detach(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_detach',
            'Propose unlinking two records — e.g. removing a course from a curriculum. Neither '
            .'record is deleted.',
            [
                'entity'     => ['type' => 'string', 'enum' => $this->catalog->names()],
                'id'         => $this->idOrRef('record'),
                'relation'   => ['type' => 'string'],
                'related_id' => $this->idOrRef('related record'),
            ],
            ['entity', 'id', 'relation', 'related_id'],
            fn (array $a) => $this->propose(ChangeOperation::detach(
                (string) $a['entity'],
                $a['id'],
                (string) $a['relation'],
                $a['related_id'],
            )),
        );
    }

    /**
     * Check, then record. Refusals go back to the model as the tool result.
     */
    private function propose(ChangeOperation $op): string
    {
        $refusal = $this->check($op);

        if ($refusal !== null) {
            return $refusal;
        }

        $this->plan->add($op);

        return 'Recorded: '.$op->describe().'. Nothing has been saved — the teacher reviews the plan first.';
    }

    /** Null when the operation can be recorded, else why not. */
    private function check(ChangeOperation $op): ?string
    {
        $entity = $op->entity;

        if (! $this->catalog->has($entity)) {
            return "Refused: [{$entity}] is not something you can change.";
        }

        if ($op->ref !== null && in_array($op->ref, $this->refs(), true)) {
            return "Refused: the handle \"{$op->ref}\" is already used in this plan. Pick another.";
        }

        return match ($op->action) {
            ChangeOperation::CREATE, ChangeOperation::UPDATE => $this->checkAttributes($op),
            ChangeOperation::DELETE => $this->checkDelete($op),
            ChangeOperation::ATTACH, ChangeOperation::DETACH => $this->checkLink($op),
            default => "Refused: unknown action [{$op->action}].",
        };
    }

    private function checkAttributes(ChangeOperation $op): ?string
    {
        if ($op->action === ChangeOperation::UPDATE && $this->catalog->find($op->entity, $op->id) === null) {
            return "Refused: there is no {$op->entity} #{$op->id}. Use catalog_search to find the right id.";
        }

        if ($op->action === ChangeOperation::UPDATE && $op->attributes === []) {
            return 'Refused: an update needs at least one field to change.';
        }

        foreach ($op->attributes as $field => $value) {
            if (in_array($field, self::FORBIDDEN, true)) {
                return $field === 'is_published'
                    ? 'Refused: publishing is not something you can propose. A human publishes after review.'
                    : "Refused: [{$field}] cannot be set.";
            }

            if (! $this->catalog->hasColumn($op->entity, (string) $field)) {
                return "Refused: {$op->entity} has no field [{$field}]. Use catalog_overview for field names.";
            }

            if (($refusal = $this->checkReference($value)) !== null) {
                return $refusal;
            }
        }

        return null;
    }

    private function checkDelete(ChangeOperation $op): ?string
    {
        $record = $this->catalog->find($op->entity, $op->id);

        if ($record === null) {
            return "Refused: there is no {$op->entity} #{$op->id}.";
        }

        $refusal = $this->deletions?->refusal($op->entity, $record);

        return $refusal === null ? null : "Refused: {$op->entity} #{$op->id} cannot be deleted — {$refusal}";
    }

    private function checkLink(ChangeOperation $op): ?string
    {
        $relation = (string) ($op->attributes['relation'] ?? '');
        $related = $this->catalog->relatedEntity($op->entity, $relation);

        if ($related === null) {
            return "Refused: [{$relation}] is not a relation of {$op->entity}. Use catalog_overview.";
        }

        foreach ([[$op->entity, $op->attributes['owner'] ?? null], [$related, $op->attributes['related'] ?? null]] as [$entity, $idOrRef]) {
            if (is_string($idOrRef) && str_starts_with($idOrRef, '$')) {
                if (($refusal = $this->checkReference($idOrRef)) !== null) {
                    return $refusal;
                }

                continue;
            }

            if ($this->catalog->find($entity, $idOrRef) === null) {
                return "Refused: there is no {$entity} #{$idOrRef}.";
            }
        }

        foreach (array_keys((array) ($op->attributes['pivot'] ?? [])) as $field) {
            if (in_array($field, self::FORBIDDEN, true)) {
                return "Refused: [{$field}] cannot be set.";
            }
        }

        return null;
    }

    private function checkReference(mixed $value): ?string
    {
        if (! is_string($value) || ! str_starts_with($value, '$')) {
            return null;
        }

        return in_array(substr($value, 1), $this->refs(), true)
            ? null
            : "Refused: {$value} does not name anything proposed earlier in this plan.";
    }

    /** @return list<string> */
    private function refs(): array
    {
        return array_values(array_filter(array_map(
            static fn (ChangeOperation $op): ?string => $op->ref,
            $this->plan->operations(),
        )));
    }

    private function freshRef(string $prefix): string
    {
        $taken = $this->refs();
        $n = $this->plan->count() + 1;

        while (in_array($prefix.$n, $taken, true)) {
            $n++;
        }

        return $prefix.$n;
    }

    /**
     * The fields the model actually sent, nulls dropped — a null for every
     * optional parameter would otherwise read as "clear this field".
     *
     * @param  array<string, mixed>  $args
     * @param  list<string>  $fields
     * @return array<string, mixed>
     */
    private function pick(array $args, array $fields): array
    {
        return array_filter(
            array_intersect_key($args, array_flip($fields)),
            static fn ($v) => $v !== null,
        );
    }

    private function withReason(string $what, ?string $reason): string
    {
        return $reason !== null && $reason !== '' ? "{$what} — {$reason}" : $what;
    }
}
