<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Feature;

use ParticleAcademy\TeachersAid\Agent\TeachersAid;
use ParticleAcademy\TeachersAid\Chat\ChatResponse;
use ParticleAcademy\TeachersAid\Chat\Message;
use ParticleAcademy\TeachersAid\Chat\ToolCall;
use ParticleAcademy\TeachersAid\Exceptions\PlanApplicationException;
use ParticleAcademy\TeachersAid\Plan\ChangeOperation;
use ParticleAcademy\TeachersAid\Plan\ChangePlan;
use ParticleAcademy\TeachersAid\Plan\PlanApplier;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeCourse;
use ParticleAcademy\TeachersAid\Tests\Fixtures\FakeLesson;
use ParticleAcademy\TeachersAid\Tests\Fixtures\ScriptedDriver;
use ParticleAcademy\TeachersAid\Tests\TestCase;

/**
 * The core promise: TAC proposes, a human applies, and nothing reaches learners
 * without someone deciding it should.
 */
class ProposeThenApplyTest extends TestCase
{
    private function agent(array $script): TeachersAid
    {
        return new TeachersAid(new ScriptedDriver($script), maxSteps: 8);
    }

    public function test_the_agent_writes_nothing_when_it_proposes(): void
    {
        $agent = $this->agent([
            new ChatResponse('', [new ToolCall('1', 'propose_course', [
                'title' => 'Situational Awareness', 'ref' => 'c1',
            ])]),
            new ChatResponse('I have drafted a course for your review.'),
        ]);

        $result = $agent->respondTo(Message::user('Build me a course on situational awareness.'));

        $this->assertTrue($result->hasProposals());
        $this->assertSame(1, $result->plan->count());

        // The headline property: proposing touched nothing.
        $this->assertSame(0, FakeCourse::query()->count());
    }

    public function test_applying_a_plan_creates_the_records(): void
    {
        $plan = new ChangePlan();
        $plan->add(ChangeOperation::create('course', ['title' => 'Patrol Basics'], 'c1'));

        $refs = (new PlanApplier())->apply($plan);

        $this->assertSame(1, FakeCourse::query()->count());
        $this->assertArrayHasKey('c1', $refs);
        $this->assertSame('Patrol Basics', FakeCourse::query()->sole()->title);
    }

    public function test_forward_references_resolve_across_a_plan(): void
    {
        // A course and its lessons proposed in one turn, before any id exists.
        $plan = new ChangePlan();
        $plan->add(ChangeOperation::create('course', ['title' => 'Patrol Basics'], 'c1'));
        $plan->add(ChangeOperation::create('lesson', ['course_id' => '$c1', 'title' => 'Lesson one']));
        $plan->add(ChangeOperation::create('lesson', ['course_id' => '$c1', 'title' => 'Lesson two']));

        (new PlanApplier())->apply($plan);

        $course = FakeCourse::query()->sole();

        $this->assertSame(2, FakeLesson::query()->where('course_id', $course->id)->count());
    }

    public function test_an_unresolvable_reference_fails_the_whole_plan(): void
    {
        $plan = new ChangePlan();
        $plan->add(ChangeOperation::create('course', ['title' => 'Created first'], 'c1'));
        $plan->add(ChangeOperation::create('lesson', ['course_id' => '$nope', 'title' => 'Orphan']));

        $this->expectException(PlanApplicationException::class);

        try {
            (new PlanApplier())->apply($plan);
        } finally {
            // All or nothing: the course from operation 1 must be rolled back,
            // because a course with missing lessons is worse than no course.
            $this->assertSame(0, FakeCourse::query()->count());
            $this->assertSame(0, FakeLesson::query()->count());
        }
    }

    public function test_applying_never_publishes_even_if_the_plan_says_so(): void
    {
        $plan = new ChangePlan();
        $plan->add(ChangeOperation::create('course', ['title' => 'Sneaky', 'is_published' => true]));

        (new PlanApplier())->apply($plan);

        // A human publishes. Approving a plan is not the same decision.
        $this->assertFalse((bool) FakeCourse::query()->sole()->is_published);
    }

    public function test_an_entity_without_an_is_published_column_still_applies(): void
    {
        // Not every entity has something to publish — lessons, questions and
        // options do not. Forcing the attribute anyway fails the insert on a
        // column that does not exist, which reads as "the plan was bad" when
        // the plan was fine.
        $plan = new ChangePlan();
        $plan->add(ChangeOperation::create('course', ['title' => 'Patrol Basics'], 'c1'));
        $plan->add(ChangeOperation::create('lesson', ['course_id' => '$c1', 'title' => 'Observation']));

        (new PlanApplier())->apply($plan);

        $this->assertSame('Observation', FakeLesson::query()->sole()->title);
    }

    public function test_an_update_cannot_publish_either(): void
    {
        $course = FakeCourse::query()->create(['title' => 'Existing', 'is_published' => false]);

        $plan = new ChangePlan();
        $plan->add(ChangeOperation::update('course', $course->id, [
            'title' => 'Renamed', 'is_published' => true,
        ]));

        (new PlanApplier())->apply($plan);

        $course->refresh();

        $this->assertSame('Renamed', $course->title);
        $this->assertFalse((bool) $course->is_published);
    }

    public function test_an_unknown_entity_is_refused_rather_than_guessed(): void
    {
        $plan = new ChangePlan();
        $plan->add(ChangeOperation::create('certificate', ['title' => 'Not in config']));

        $this->expectException(PlanApplicationException::class);

        (new PlanApplier())->apply($plan);
    }

    public function test_a_question_and_its_options_are_linked_in_one_proposal(): void
    {
        $agent = $this->agent([
            new ChatResponse('', [new ToolCall('1', 'propose_question', [
                'test_id' => '7',
                'prompt'  => 'What is the first step?',
                'type'    => 'multiple_choice',
                'options' => [
                    ['label' => 'Observe', 'is_correct' => true],
                    ['label' => 'Engage', 'is_correct' => false],
                ],
            ])]),
            new ChatResponse('Proposed one question.'),
        ]);

        $plan = $agent->respondTo(Message::user('Add a question.'))->plan;

        // One question plus its two options, with the options pointing at the
        // question by reference.
        $this->assertSame(3, $plan->count());

        $ops = $plan->operations();

        $this->assertSame('question', $ops[0]->entity);
        $this->assertSame('question_option', $ops[1]->entity);
        $this->assertSame('$'.$ops[0]->ref, $ops[1]->attributes['question_id']);
        $this->assertTrue($ops[1]->attributes['is_correct']);
    }

    public function test_the_loop_stops_at_the_step_limit(): void
    {
        // A model that keeps asking for tools forever.
        $script = array_fill(0, 20, new ChatResponse('', [
            new ToolCall('x', 'propose_course', ['title' => 'Again']),
        ]));

        $result = (new TeachersAid(new ScriptedDriver($script), maxSteps: 3))
            ->respondTo(Message::user('go'));

        $this->assertSame(3, $result->steps);
        $this->assertTrue($result->hitStepLimit);
    }

    public function test_an_unknown_tool_is_reported_to_the_model_not_thrown(): void
    {
        $agent = $this->agent([
            new ChatResponse('', [new ToolCall('1', 'delete_everything', [])]),
            new ChatResponse('Sorry, I cannot do that.'),
        ]);

        $result = $agent->respondTo(Message::user('go'));

        // Recoverable: the model usually corrects itself on the next step.
        $this->assertSame('Sorry, I cannot do that.', $result->reply);
        $this->assertFalse($result->hasProposals());
    }
}
