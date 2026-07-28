<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use ParticleAcademy\TeachersAid\TeachersAidServiceProvider;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeCourse;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeLesson;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [TeachersAidServiceProvider::class];
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
            'course' => FakeCourse::class,
            'lesson' => FakeLesson::class,
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

        Schema::create('lessons', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('course_id');
            $t->string('title');
            $t->longText('content')->nullable();
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_published')->default(false);
            $t->timestamps();
        });
    }
}
