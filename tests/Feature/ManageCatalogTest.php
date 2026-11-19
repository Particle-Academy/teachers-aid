<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Feature\ManageCatalogTest;

use Illuminate\Database\Eloquent\Model;
use ParticleAcademy\TeachersAid\Agent\TeachersAid;
use ParticleAcademy\TeachersAid\Chat\ToolDefinition;
use ParticleAcademy\TeachersAid\Contracts\GuardsDeletion;
use ParticleAcademy\TeachersAid\Drivers\PrismTools;
use ParticleAcademy\TeachersAid\Exceptions\PlanApplicationException;
use ParticleAcademy\TeachersAid\Plan\ChangePlan;
use ParticleAcademy\TeachersAid\Plan\PlanApplier;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeCourse;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeCurriculum;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeLesson;
use ParticleAcademy\TeachersAid\Tests\TestCase;

/**
 * Managing an EXISTING catalog: seeing it, then proposing changes to it.
 *
 * Before the read tools the agent could only add — it had no way to learn an
 * id, so "rename the Trespass course" became a second Trespass course.
 */

beforeEach(function () {
    $this->plan = new ChangePlan();
    buildTools();
});

function buildTools(): void {
    $tools = [];

    foreach (app()->make(TeachersAid::class)->tools(test()->plan) as $tool) {
        $tools[$tool->name] = $tool;
    }

    test()->tools = $tools;
}

function useTool(string $tool, array $args = []): string {
    return test()->tools[$tool]->run($args);
}

function read(string $tool, array $args = []): array {
    return json_decode(useTool($tool, $args), true, flags: JSON_THROW_ON_ERROR);
}

test('every named tool is offered', function () {
    $this->assertEqualsCanonicalizing(TeachersAid::TOOL_NAMES, array_keys($this->tools));
});

// ── Eyes ────────────────────────────────────────────────────────────

test('the overview lists entities fields and relations', function () {
    FakeCourse::query()->create(['title' => 'Trespass']);

    $overview = read('catalog_overview');

    $this->assertSame(1, $overview['course']['count']);
    $this->assertContains('title', $overview['course']['fields']);
    $this->assertNotContains('created_at', $overview['course']['fields']);
    $this->assertSame(['courses' => 'course'], $overview['curriculum']['relations']);
});

test('search finds by text case insensitively and by field', function () {
    $trespass = FakeCourse::query()->create(['title' => 'Trespass']);
    FakeCourse::query()->create(['title' => 'Crowd Control']);
    FakeLesson::query()->create(['course_id' => $trespass->id, 'title' => 'What counts as trespass']);
    FakeLesson::query()->create(['course_id' => 999, 'title' => 'Elsewhere']);

    $found = read('catalog_search', ['entity' => 'course', 'query' => 'TRESP']);
    $this->assertSame(1, $found['total']);
    $this->assertSame($trespass->id, $found['rows'][0]['id']);

    $lessons = read('catalog_search', ['entity' => 'lesson', 'filters' => ['course_id' => $trespass->id]]);
    $this->assertSame(1, $lessons['total']);
});

test('search refuses a field that does not exist', function () {
    $this->assertStringStartsWith('Refused', useTool('catalog_search', [
        'entity' => 'course', 'filters' => ['password' => 'x'],
    ]));
});

test('get includes linked records with their pivot', function () {
    $curriculum = FakeCurriculum::query()->create(['title' => 'Guard Card']);
    $course = FakeCourse::query()->create(['title' => 'Trespass']);
    $curriculum->courses()->attach($course->id, ['sort_order' => 3]);

    $got = read('catalog_get', ['entity' => 'curriculum', 'id' => $curriculum->id, 'include' => ['courses']]);

    $this->assertSame('Guard Card', $got['title']);
    $this->assertSame('Trespass', $got['courses'][0]['title']);
    $this->assertSame(3, (int) $got['courses'][0]['pivot']['sort_order']);
});

test('get refuses a relation that is not configured', function () {
    $course = FakeCourse::query()->create(['title' => 'Trespass']);

    // A relation name from the model is never called unless configured.
    $this->assertStringStartsWith('Refused', useTool('catalog_get', [
        'entity' => 'course', 'id' => $course->id, 'include' => ['delete'],
    ]));
});

// ── Hands: still only proposals ─────────────────────────────────────

test('an update needs a real id', function () {
    $this->assertStringContainsString('catalog_search', useTool('propose_update', [
        'entity' => 'course', 'id' => 404, 'attributes' => ['title' => 'x'],
    ]));
    $this->assertTrue($this->plan->isEmpty());
});

test('an update refuses unknown fields and publishing', function () {
    $course = FakeCourse::query()->create(['title' => 'Trespass']);

    $this->assertStringContainsString('no field', useTool('propose_update', [
        'entity' => 'course', 'id' => $course->id, 'attributes' => ['colour' => 'red'],
    ]));
    $this->assertStringContainsString('human publishes', useTool('propose_update', [
        'entity' => 'course', 'id' => $course->id, 'attributes' => ['is_published' => true],
    ]));
    $this->assertTrue($this->plan->isEmpty());
});

test('a handle cannot be reused or invented', function () {
    useTool('propose_course', ['title' => 'One', 'ref' => 'c1']);

    $this->assertStringContainsString('already used', useTool('propose_course', ['title' => 'Two', 'ref' => 'c1']));
    $this->assertStringContainsString('does not name', useTool('propose_lesson', ['course_id' => '$nope', 'title' => 'L']));
    $this->assertSame(1, $this->plan->count());
});

test('a new curriculum and course are linked in one plan', function () {
    $existing = FakeCourse::query()->create(['title' => 'Trespass']);

    useTool('propose_curriculum', ['title' => 'Guard Card', 'ref' => 'gc']);
    useTool('propose_course', ['title' => 'Crowd Control', 'ref' => 'cc']);
    $this->assertStringStartsWith('Recorded', useTool('propose_attach', [
        'entity' => 'curriculum', 'id' => '$gc', 'relation' => 'courses', 'related_id' => '$cc',
    ]));
    useTool('propose_attach', [
        'entity' => 'curriculum', 'id' => '$gc', 'relation' => 'courses',
        'related_id' => (string) $existing->id, 'pivot' => ['sort_order' => 2],
    ]);

    // Nothing yet — proposing is not doing.
    $this->assertSame(0, FakeCurriculum::query()->count());

    $this->app->make(PlanApplier::class)->apply($this->plan);

    $curriculum = FakeCurriculum::query()->sole();
    $this->assertSame(['Crowd Control', 'Trespass'], $curriculum->courses()->orderBy('title')->pluck('title')->all());
    $this->assertSame(2, (int) $curriculum->courses()->where('title', 'Trespass')->first()->pivot->sort_order);
    // Created as drafts, as everything a plan creates is.
    $this->assertFalse((bool) $curriculum->is_published);
});

test('detach unlinks without deleting either side', function () {
    $curriculum = FakeCurriculum::query()->create(['title' => 'Guard Card']);
    $course = FakeCourse::query()->create(['title' => 'Trespass']);
    $curriculum->courses()->attach($course->id);

    useTool('propose_detach', [
        'entity' => 'curriculum', 'id' => (string) $curriculum->id, 'relation' => 'courses', 'related_id' => (string) $course->id,
    ]);
    $this->app->make(PlanApplier::class)->apply($this->plan);

    $this->assertSame(0, $curriculum->courses()->count());
    $this->assertSame(1, FakeCourse::query()->count());
});

test('only configured relations can be linked', function () {
    $course = FakeCourse::query()->create(['title' => 'Trespass']);

    $this->assertStringStartsWith('Refused', useTool('propose_attach', [
        'entity' => 'course', 'id' => (string) $course->id, 'relation' => 'lessons', 'related_id' => '1',
    ]));
});

test('a host can veto a delete when proposed and when applied', function () {
    $this->app->instance(GuardsDeletion::class, new class implements GuardsDeletion {
        public function refusal(string $entity, Model $record): ?string
        {
            return $record->title === 'Sold' ? 'people are enrolled in it' : null;
        }
    });
    buildTools();   // with the veto bound

    $sold = FakeCourse::query()->create(['title' => 'Sold']);
    $spare = FakeCourse::query()->create(['title' => 'Spare']);

    $this->assertStringContainsString('people are enrolled', useTool('propose_delete', [
        'entity' => 'course', 'id' => $sold->id, 'reason' => 'old',
    ]));

    useTool('propose_delete', ['entity' => 'course', 'id' => $spare->id, 'reason' => 'duplicate']);
    $this->app->make(PlanApplier::class)->apply($this->plan);
    $this->assertNull(FakeCourse::query()->find($spare->id));

    // The world can change between proposing and applying.
    $late = new ChangePlan();
    $late->add(\ParticleAcademy\TeachersAid\Plan\ChangeOperation::delete('course', $sold->id));

    $this->expectException(PlanApplicationException::class);
    $this->app->make(PlanApplier::class)->apply($late);
});

test('a plan keeps growing across turns', function () {
    useTool('propose_course', ['title' => 'Trespass', 'ref' => 'c1']);

    // Next turn: same pending plan, fresh tools.
    $tools = [];
    foreach ($this->app->make(TeachersAid::class)->tools($this->plan) as $tool) {
        $tools[$tool->name] = $tool;
    }

    $this->assertStringStartsWith('Recorded', $tools['propose_lesson']->run(['course_id' => '$c1', 'title' => 'Basics']));
    $this->assertSame(2, $this->plan->count());
});

test('a stored plan keeps its reviewer notes', function () {
    $course = FakeCourse::query()->create(['title' => 'Old']);
    useTool('propose_delete', ['entity' => 'course', 'id' => $course->id, 'reason' => 'duplicate of #4']);

    // A pending plan is stored between turns; the note has to survive it.
    $restored = ChangePlan::fromArray($this->plan->toArray());

    $this->assertStringContainsString('duplicate of #4', $restored->operations()[0]->describe());
});

test('a live prism tool runs the handler', function () {
    // What a runtime that owns the loop — prism-harness — is handed.
    $tool = PrismTools::live($this->tools['propose_course']);

    $result = $tool->handle(title: 'Radio Procedures', description: 'Two-way radio basics');

    $this->assertStringStartsWith('Recorded', (string) $result);
    $this->assertSame('Two-way radio basics', $this->plan->operations()[0]->attributes['description']);
});
