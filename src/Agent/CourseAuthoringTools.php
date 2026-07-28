<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Agent;

use ParticleAcademy\TeachersAid\Chat\ToolDefinition;
use ParticleAcademy\TeachersAid\Plan\ChangeOperation;
use ParticleAcademy\TeachersAid\Plan\ChangePlan;

/**
 * TAC's tools. Every one records an intention; none touches the database.
 *
 * That is deliberate and structural: the agent is handed a ChangePlan and has
 * no repository, no model, no connection. "Propose, then apply" cannot be
 * bypassed by a confused model or a prompt injection in an uploaded file,
 * because there is no code path from here to a write.
 */
class CourseAuthoringTools
{
    public function __construct(private readonly ChangePlan $plan)
    {
    }

    /**
     * @return list<ToolDefinition>
     */
    public function all(): array
    {
        return [
            $this->createCurriculum(),
            $this->createCourse(),
            $this->createLesson(),
            $this->createTest(),
            $this->createQuestion(),
            $this->updateEntity(),
        ];
    }

    private function ref(): array
    {
        return [
            'type' => 'string',
            'description' => 'A short handle for this new record, e.g. "course1", so later '
                .'operations in the same plan can point at it before it has an id. '
                .'Reference it elsewhere as "$course1".',
        ];
    }

    private function createCurriculum(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_curriculum',
            'Propose a new curriculum — a named programme that groups courses.',
            [
                'title'       => ['type' => 'string', 'description' => 'Learner-facing title.'],
                'description' => ['type' => 'string'],
                'ref'         => $this->ref(),
            ],
            ['title'],
            fn (array $a) => $this->record(ChangeOperation::create('curriculum', [
                'title'       => $a['title'],
                'description' => $a['description'] ?? null,
            ], $a['ref'] ?? null)),
        );
    }

    private function createCourse(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_course',
            'Propose a new course.',
            [
                'title'             => ['type' => 'string'],
                'description'       => ['type' => 'string'],
                'estimated_minutes' => ['type' => 'integer', 'description' => 'Total learner time.'],
                'ref'               => $this->ref(),
            ],
            ['title'],
            fn (array $a) => $this->record(ChangeOperation::create('course', [
                'title'             => $a['title'],
                'description'       => $a['description'] ?? null,
                'estimated_minutes' => $a['estimated_minutes'] ?? null,
            ], $a['ref'] ?? null)),
        );
    }

    private function createLesson(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_lesson',
            'Propose a lesson inside a course. Write the actual teaching content, not a placeholder.',
            [
                'course_id'         => ['type' => 'string', 'description' => 'Existing course id, or "$ref" of a course proposed in this plan.'],
                'title'             => ['type' => 'string'],
                'content'           => ['type' => 'string', 'description' => 'The lesson body, in Markdown.'],
                'content_type'      => ['type' => 'string', 'enum' => ['text', 'video', 'mixed']],
                'video_url'         => ['type' => 'string'],
                'estimated_minutes' => ['type' => 'integer'],
                'sort_order'        => ['type' => 'integer'],
                'ref'               => $this->ref(),
            ],
            ['course_id', 'title'],
            fn (array $a) => $this->record(ChangeOperation::create('lesson', [
                'course_id'         => $a['course_id'],
                'title'             => $a['title'],
                'content'           => $a['content'] ?? null,
                'content_type'      => $a['content_type'] ?? 'text',
                'video_url'         => $a['video_url'] ?? null,
                'estimated_minutes' => $a['estimated_minutes'] ?? null,
                'sort_order'        => $a['sort_order'] ?? 0,
            ], $a['ref'] ?? null)),
        );
    }

    private function createTest(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_test',
            'Propose a test or quiz for a course.',
            [
                'course_id'     => ['type' => 'string', 'description' => 'Existing course id, or "$ref".'],
                'title'         => ['type' => 'string'],
                'description'   => ['type' => 'string'],
                'passing_score' => ['type' => 'integer', 'description' => 'Percentage, 0-100.'],
                'is_final'      => ['type' => 'boolean', 'description' => 'True if this is the course\'s final exam.'],
                'ref'           => $this->ref(),
            ],
            ['course_id', 'title'],
            fn (array $a) => $this->record(ChangeOperation::create('test', [
                'course_id'     => $a['course_id'],
                'title'         => $a['title'],
                'description'   => $a['description'] ?? null,
                'passing_score' => $a['passing_score'] ?? null,
                'is_final'      => $a['is_final'] ?? false,
            ], $a['ref'] ?? null)),
        );
    }

    private function createQuestion(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_question',
            'Propose a question with its options. Exactly one option must be correct for '
            .'multiple_choice; multiple_select may have several.',
            [
                'test_id'     => ['type' => 'string', 'description' => 'Existing test id, or "$ref".'],
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
            function (array $a) {
                $ref = 'q'.($this->plan->count() + 1);

                $this->plan->add(ChangeOperation::create('question', [
                    'test_id'     => $a['test_id'],
                    'prompt'      => $a['prompt'],
                    'type'        => $a['type'],
                    'points'      => $a['points'] ?? 1,
                    'explanation' => $a['explanation'] ?? null,
                ], $ref, "Create question: {$a['prompt']}"));

                foreach ((array) ($a['options'] ?? []) as $i => $option) {
                    $this->plan->add(ChangeOperation::create('question_option', [
                        'question_id' => '$'.$ref,
                        'label'       => $option['label'] ?? '',
                        'is_correct'  => (bool) ($option['is_correct'] ?? false),
                        'sort_order'  => $i,
                    ], summary: '  option: '.($option['label'] ?? '')));
                }

                return 'Recorded question and '.count((array) ($a['options'] ?? [])).' options.';
            },
        );
    }

    private function updateEntity(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_update',
            'Propose changes to an EXISTING record. Only include fields that should change. '
            .'Publishing is not possible here — a human publishes.',
            [
                'entity'     => ['type' => 'string', 'enum' => ['curriculum', 'course', 'lesson', 'test', 'question']],
                'id'         => ['type' => 'integer'],
                'attributes' => ['type' => 'object', 'description' => 'Field => new value.'],
                'reason'     => ['type' => 'string', 'description' => 'Why, for the reviewer.'],
            ],
            ['entity', 'id', 'attributes'],
            fn (array $a) => $this->record(ChangeOperation::update(
                $a['entity'],
                (int) $a['id'],
                (array) $a['attributes'],
                $a['reason'] ?? null,
            )),
        );
    }

    private function record(ChangeOperation $op): string
    {
        $this->plan->add($op);

        return 'Recorded: '.$op->describe().'. Nothing has been saved — the teacher reviews the plan first.';
    }
}
