<?php

declare(strict_types=1);

use ParticleAcademy\LaravelCourses\Models\Course;
use ParticleAcademy\LaravelCourses\Models\Curriculum;
use ParticleAcademy\LaravelCourses\Models\Lesson;
use ParticleAcademy\LaravelCourses\Models\Question;
use ParticleAcademy\LaravelCourses\Models\QuestionOption;
use ParticleAcademy\LaravelCourses\Models\Test;

return [
    /*
    |--------------------------------------------------------------------------
    | The agent
    |--------------------------------------------------------------------------
    |
    | Name it whatever suits the host — it is what learners' teachers will call
    | it, and it appears in the system prompt and the chat header.
    |
    */
    'name' => env('TEACHERS_AID_NAME', 'TAC'),

    'description' => env(
        'TEACHERS_AID_DESCRIPTION',
        'Teachers Aid Chat — reads your course material and drafts curriculums, lessons and tests for you to review.',
    ),

    /*
    |--------------------------------------------------------------------------
    | Chat driver
    |--------------------------------------------------------------------------
    |
    | The LLM library behind the agent. Ships with `prism`; implement
    | ParticleAcademy\TeachersAid\Contracts\ChatDriver to use any other —
    | the agent itself has no knowledge of the library.
    |
    */
    'driver' => env('TEACHERS_AID_DRIVER', 'prism'),

    'drivers' => [
        'prism' => [
            'provider' => env('TEACHERS_AID_PROVIDER', 'anthropic'),
            'model'    => env('TEACHERS_AID_MODEL', 'claude-sonnet-5'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agent loop
    |--------------------------------------------------------------------------
    |
    | How many model round-trips one turn may take. A turn ends when the model
    | stops asking for tools; the cap stops a confused model looping forever.
    |
    */
    'max_steps' => (int) env('TEACHERS_AID_MAX_STEPS', 8),

    /*
    |--------------------------------------------------------------------------
    | What a plan may touch
    |--------------------------------------------------------------------------
    |
    | Entity name (as the model refers to it) => model class. Anything absent
    | cannot be proposed at all, so this doubles as the blast radius.
    |
    */
    'entities' => [
        'curriculum'      => Curriculum::class,
        'course'          => Course::class,
        'lesson'          => Lesson::class,
        'test'            => Test::class,
        'question'        => Question::class,
        'question_option' => QuestionOption::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    */
    'uploads' => [
        // Anything the driver cannot read natively is extracted to text.
        'max_bytes'      => (int) env('TEACHERS_AID_MAX_UPLOAD_BYTES', 20 * 1024 * 1024),
        'max_text_chars' => (int) env('TEACHERS_AID_MAX_TEXT_CHARS', 200_000),
    ],
];
