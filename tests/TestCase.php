<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use ParticleAcademy\TeachersAid\TeachersAidServiceProvider;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeCourse;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeCurriculum;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeModule;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeQuestion;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeQuestionOption;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeTest;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeLesson;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [
            \Prism\Prism\PrismServiceProvider::class,
            TeachersAidServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]);

        // Stand-in entities, so the suite does not depend on laravel-courses'
        // schema — the same substitution a host makes via config.
        $app['config']->set('teachers-aid.entities', [
            'curriculum'      => FakeCurriculum::class,
            'course'          => FakeCourse::class,
            'module'          => FakeModule::class,
            'lesson'          => FakeLesson::class,
            'test'            => FakeTest::class,
            'question'        => FakeQuestion::class,
            'question_option' => FakeQuestionOption::class,
        ]);

        $app['config']->set('teachers-aid.relations', [
            'curriculum' => ['courses' => 'course'],
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('courses', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->boolean('is_published')->default(false);
            $t->timestamps();
        });

        // Deliberately WITHOUT is_published, mirroring laravel-courses: a lesson
        // is published by publishing its course. An applier that assumes every
        // entity has the column dies on the insert, so the fixture has to be as
        // uneven as the real schema.
        Schema::create('lessons', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('course_id');
            $t->string('title');
            $t->unsignedBigInteger('module_id')->nullable();
            $t->longText('content')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('curriculums', function (Blueprint $t): void {
            $t->id();
            $t->string('title');
            $t->text('description')->nullable();
            $t->boolean('is_published')->default(false);
            $t->timestamps();
        });

        Schema::create('curriculum_course', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('curriculum_id');
            $t->unsignedBigInteger('course_id');
            $t->unsignedInteger('sort_order')->default(0);
            $t->unique(['curriculum_id', 'course_id']);
        });

        Schema::create('modules', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('course_id');
            $t->string('title');
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('tests', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('course_id')->nullable();
            $t->unsignedBigInteger('module_id')->nullable();
            $t->unsignedBigInteger('lesson_id')->nullable();
            $t->string('title');
            $t->text('description')->nullable();
            $t->unsignedTinyInteger('passing_score')->nullable();
            $t->boolean('is_final')->default(false);
            $t->timestamps();
        });

        Schema::create('questions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('test_id');
            $t->text('prompt');
            $t->string('type');
            $t->decimal('points', 6, 2)->default(1);
            $t->text('explanation')->nullable();
            $t->timestamps();
        });

        Schema::create('question_options', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('question_id');
            $t->string('label');
            $t->boolean('is_correct')->default(false);
            $t->unsignedInteger('sort_order')->default(0);
            $t->timestamps();
        });
    }
}
