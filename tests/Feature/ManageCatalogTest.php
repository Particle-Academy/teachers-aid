<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Feature;

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
class ManageCatalogTest extends TestCase
{
    private ChangePlan $plan;

    /** @var array<string, ToolDefinition> */
    private array $tools;

    protected function setUp(): void
    {
        parent::setUp();

        $this->plan = new ChangePlan();
        $this->buildTools();
    }

    private function buildTools(): void
    {
        $this->tools = [];

        foreach ($this->app->make(TeachersAid::class)->tools($this->plan) as $tool) {
            $this->tools[$tool->name] = $tool;
        }
    }

    private function use(string $tool, array $args = []): string
    {
        return $this->tools[$tool]->run($args);
    }

    private function read(string $tool, array $args = []): array
    {
        return json_decode($this->use($tool, $args), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_every_named_tool_is_offered(): void
    {
        $this->assertEqualsCanonicalizing(TeachersAid::TOOL_NAMES, array_keys($this->tools));
    }

    // ── Eyes ────────────────────────────────────────────────────────────

    public function test_the_overview_lists_entities_fields_and_relations(): void
    {
        FakeCourse::query()->create(['title' => 'Trespass']);

        $overview = $this->read('catalog_overview');

        $this->assertSame(1, $overview['course']['count']);
        $this->assertContains('title', $overview['course']['fields']);
        $this->assertNotContains('created_at', $overview['course']['fields']);
        $this->assertSame(['courses' => 'course'], $overview['curriculum']['relations']);
    }

    public function test_search_finds_by_text_case_insensitively_and_by_field(): void
    {
        $trespass = FakeCourse::query()->create(['title' => 'Trespass']);
        FakeCourse::query()->create(['title' => 'Crowd Control']);
        FakeLesson::query()->create(['course_id' => $trespass->id, 'title' => 'What counts as trespass']);
        FakeLesson::query()->create(['course_id' => 999, 'title' => 'Elsewhere']);

        $found = $this->read('catalog_search', ['entity' => 'course', 'query' => 'TRESP']);
        $this->assertSame(1, $found['total']);
        $this->assertSame($trespass->id, $found['rows'][0]['id']);

        $lessons = $this->read('catalog_search', ['entity' => 'lesson', 'filters' => ['course_id' => $trespass->id]]);
        $this->assertSame(1, $lessons['total']);
    }

    public function test_search_refuses_a_field_that_does_not_exist(): void
    {
        $this->assertStringStartsWith('Refused', $this->use('catalog_search', [
            'entity' => 'course', 'filters' => ['password' => 'x'],
        ]));
    }

    public function test_get_includes_linked_records_with_their_pivot(): void
    {
        $curriculum = FakeCurriculum::query()->create(['title' => 'Guard Card']);
        $course = FakeCourse::query()->create(['title' => 'Trespass']);
        $curriculum->courses()->attach($course->id, ['sort_order' => 3]);

        $got = $this->read('catalog_get', ['entity' => 'curriculum', 'id' => $curriculum->id, 'include' => ['courses']]);

        $this->assertSame('Guard Card', $got['title']);
        $this->assertSame('Trespass', $got['courses'][0]['title']);
        $this->assertSame(3, (int) $got['courses'][0]['pivot']['sort_order']);
    }

    public function test_get_refuses_a_relation_that_is_not_configured(): void
    {
        $course = FakeCourse::query()->create(['title' => 'Trespass']);

        // A relation name from the model is never called unless configured.
        $this->assertStringStartsWith('Refused', $this->use('catalog_get', [
            'entity' => 'course', 'id' => $course->id, 'include' => ['delete'],
        ]));
    }

    // ── Hands: still only proposals ─────────────────────────────────────

    public function test_an_update_needs_a_real_id(): void
    {
        $this->assertStringContainsString('catalog_search', $this->use('propose_update', [
            'entity' => 'course', 'id' => 404, 'attributes' => ['title' => 'x'],
        ]));
        $this->assertTrue($this->plan->isEmpty());
    }

    public function test_an_update_refuses_unknown_fields_and_publishing(): void
    {
        $course = FakeCourse::query()->create(['title' => 'Trespass']);

        $this->assertStringContainsString('no field', $this->use('propose_update', [
            'entity' => 'course', 'id' => $course->id, 'attributes' => ['colour' => 'red'],
        ]));
        $this->assertStringContainsString('human publishes', $this->use('propose_update', [
            'entity' => 'course', 'id' => $course->id, 'attributes' => ['is_published' => true],
        ]));
        $this->assertTrue($this->plan->isEmpty());
    }

    public function test_a_handle_cannot_be_reused_or_invented(): void
    {
        $this->use('propose_course', ['title' => 'One', 'ref' => 'c1']);

        $this->assertStringContainsString('already used', $this->use('propose_course', ['title' => 'Two', 'ref' => 'c1']));
        $this->assertStringContainsString('does not name', $this->use('propose_lesson', ['course_id' => '$nope', 'title' => 'L']));
        $this->assertSame(1, $this->plan->count());
    }

    public function test_a_new_curriculum_and_course_are_linked_in_one_plan(): void
    {
        $existing = FakeCourse::query()->create(['title' => 'Trespass']);

        $this->use('propose_curriculum', ['title' => 'Guard Card', 'ref' => 'gc']);
        $this->use('propose_course', ['title' => 'Crowd Control', 'ref' => 'cc']);
        $this->assertStringStartsWith('Recorded', $this->use('propose_attach', [
            'entity' => 'curriculum', 'id' => '$gc', 'relation' => 'courses', 'related_id' => '$cc',
        ]));
        $this->use('propose_attach', [
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
    }

    public function test_detach_unlinks_without_deleting_either_side(): void
    {
        $curriculum = FakeCurriculum::query()->create(['title' => 'Guard Card']);
        $course = FakeCourse::query()->create(['title' => 'Trespass']);
        $curriculum->courses()->attach($course->id);

        $this->use('propose_detach', [
            'entity' => 'curriculum', 'id' => (string) $curriculum->id, 'relation' => 'courses', 'related_id' => (string) $course->id,
        ]);
        $this->app->make(PlanApplier::class)->apply($this->plan);

        $this->assertSame(0, $curriculum->courses()->count());
        $this->assertSame(1, FakeCourse::query()->count());
    }

    public function test_only_configured_relations_can_be_linked(): void
    {
        $course = FakeCourse::query()->create(['title' => 'Trespass']);

        $this->assertStringStartsWith('Refused', $this->use('propose_attach', [
            'entity' => 'course', 'id' => (string) $course->id, 'relation' => 'lessons', 'related_id' => '1',
        ]));
    }

    public function test_a_host_can_veto_a_delete_when_proposed_and_when_applied(): void
    {
        $this->app->instance(GuardsDeletion::class, new class implements GuardsDeletion {
            public function refusal(string $entity, Model $record): ?string
            {
                return $record->title === 'Sold' ? 'people are enrolled in it' : null;
            }
        });
        $this->buildTools();   // with the veto bound

        $sold = FakeCourse::query()->create(['title' => 'Sold']);
        $spare = FakeCourse::query()->create(['title' => 'Spare']);

        $this->assertStringContainsString('people are enrolled', $this->use('propose_delete', [
            'entity' => 'course', 'id' => $sold->id, 'reason' => 'old',
        ]));

        $this->use('propose_delete', ['entity' => 'course', 'id' => $spare->id, 'reason' => 'duplicate']);
        $this->app->make(PlanApplier::class)->apply($this->plan);
        $this->assertNull(FakeCourse::query()->find($spare->id));

        // The world can change between proposing and applying.
        $late = new ChangePlan();
        $late->add(\ParticleAcademy\TeachersAid\Plan\ChangeOperation::delete('course', $sold->id));

        $this->expectException(PlanApplicationException::class);
        $this->app->make(PlanApplier::class)->apply($late);
    }

    public function test_a_plan_keeps_growing_across_turns(): void
    {
        $this->use('propose_course', ['title' => 'Trespass', 'ref' => 'c1']);

        // Next turn: same pending plan, fresh tools.
        $tools = [];
        foreach ($this->app->make(TeachersAid::class)->tools($this->plan) as $tool) {
            $tools[$tool->name] = $tool;
        }

        $this->assertStringStartsWith('Recorded', $tools['propose_lesson']->run(['course_id' => '$c1', 'title' => 'Basics']));
        $this->assertSame(2, $this->plan->count());
    }

    public function test_a_stored_plan_keeps_its_reviewer_notes(): void
    {
        $course = FakeCourse::query()->create(['title' => 'Old']);
        $this->use('propose_delete', ['entity' => 'course', 'id' => $course->id, 'reason' => 'duplicate of #4']);

        // A pending plan is stored between turns; the note has to survive it.
        $restored = ChangePlan::fromArray($this->plan->toArray());

        $this->assertStringContainsString('duplicate of #4', $restored->operations()[0]->describe());
    }

    public function test_a_live_prism_tool_runs_the_handler(): void
    {
        // What a runtime that owns the loop — prism-harness — is handed.
        $tool = PrismTools::live($this->tools['propose_course']);

        $result = $tool->handle(title: 'Radio Procedures', description: 'Two-way radio basics');

        $this->assertStringStartsWith('Recorded', (string) $result);
        $this->assertSame('Two-way radio basics', $this->plan->operations()[0]->attributes['description']);
    }
}
