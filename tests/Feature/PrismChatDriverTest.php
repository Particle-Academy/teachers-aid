<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Feature;

use ParticleAcademy\TeachersAid\Agent\TeachersAid;
use ParticleAcademy\TeachersAid\Chat\Attachment;
use ParticleAcademy\TeachersAid\Chat\Message;
use ParticleAcademy\TeachersAid\Chat\ToolDefinition;
use ParticleAcademy\TeachersAid\Drivers\PrismChatDriver;
use ParticleAcademy\TeachersAid\Tests\TestCase;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall as PrismToolCall;

/**
 * The driver is the one place Prism exists. These tests pin the three
 * translations the ChatDriver contract asks for.
 */
class PrismChatDriverTest extends TestCase
{
    private function tool(): ToolDefinition
    {
        return ToolDefinition::make(
            'propose_question',
            'Propose a test question.',
            [
                'prompt'  => ['type' => 'string', 'description' => 'The question'],
                // Nested array-of-objects: the case Prism's fluent
                // withStringParameter() builders cannot express.
                'options' => [
                    'type'  => 'array',
                    'items' => [
                        'type'       => 'object',
                        'properties' => [
                            'label'      => ['type' => 'string'],
                            'is_correct' => ['type' => 'boolean'],
                        ],
                    ],
                ],
            ],
            ['prompt'],
            static fn (array $args): string => 'recorded',
        );
    }

    public function test_system_turns_travel_as_a_system_prompt_not_a_message(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        (new PrismChatDriver())->send([
            Message::system('You are TAC.'),
            Message::user('Hello'),
        ]);

        // Providers reject a system turn inside the messages array — Anthropic
        // throws outright. Getting this wrong breaks every single call.
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame('You are TAC.', $requests[0]->systemPrompts()[0]->content);

            foreach ($requests[0]->messages() as $message) {
                $this->assertNotInstanceOf(\Prism\Prism\ValueObjects\Messages\SystemMessage::class, $message);
            }
        });
    }

    public function test_the_json_schema_reaches_prism_intact(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        (new PrismChatDriver())->send([Message::user('Add a question.')], [$this->tool()]);

        $fake->assertRequest(function (array $requests): void {
            $tool = $requests[0]->tools()[0];

            $this->assertSame('propose_question', $tool->name());
            $this->assertSame(['prompt'], $tool->requiredParameters());

            $params = $tool->parametersAsArray();

            // The nested shape survives, which is the whole reason for RawSchema.
            $this->assertSame('array', $params['options']['type']);
            $this->assertSame('boolean', $params['options']['items']['properties']['is_correct']['type']);
        });
    }

    public function test_prism_is_never_allowed_to_run_its_own_agent_loop(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        (new PrismChatDriver())->send([Message::user('go')], [$this->tool()]);

        // One send() is one round-trip. The agent owns the loop so that TAC
        // behaves identically on every driver.
        $fake->assertRequest(function (array $requests): void {
            $this->assertSame(1, $requests[0]->maxSteps());
        });
    }

    public function test_tool_calls_come_back_normalised(): void
    {
        Prism::fake([
            TextResponseFake::make()->withToolCalls([
                new PrismToolCall('call_1', 'propose_course', ['title' => 'Patrol Basics']),
            ]),
        ]);

        $response = (new PrismChatDriver())->send([Message::user('go')], [$this->tool()]);

        $this->assertTrue($response->wantsTools());
        $this->assertSame('call_1', $response->toolCalls[0]->id);
        $this->assertSame('propose_course', $response->toolCalls[0]->name);
        $this->assertSame(['title' => 'Patrol Basics'], $response->toolCalls[0]->arguments);
    }

    public function test_an_extracted_file_travels_as_text_and_a_native_one_as_bytes(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        $pdf = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('tac_', true).'.pdf';
        file_put_contents($pdf, '%PDF-1.4');

        (new PrismChatDriver())->send([
            Message::user('Two files.', [
                Attachment::extracted('bank.csv', 'text/csv', 'prompt,answer'),
                Attachment::native($pdf, 'handbook.pdf', 'application/pdf'),
            ]),
        ]);

        $fake->assertRequest(function (array $requests): void {
            /** @var UserMessage $message */
            $message = $requests[0]->messages()[0];

            $documents = array_values(array_filter(
                $message->additionalContent,
                static fn ($part): bool => $part instanceof Document,
            ));

            $this->assertCount(2, $documents);
            // The extracted one is titled so the model can tell sources apart.
            $this->assertSame('bank.csv', $documents[0]->documentTitle());
            $this->assertSame('handbook.pdf', $documents[1]->documentTitle());
        });

        @unlink($pdf);
    }

    public function test_an_image_travels_as_an_image_part(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        $png = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('tac_', true).'.png';
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAAAAAA6fptVAAAACklEQVR4nGNiAAAABgADNjd8qAAAAABJRU5ErkJggg=='));

        (new PrismChatDriver())->send([
            Message::user('A diagram.', [Attachment::native($png, 'diagram.png', 'image/png')]),
        ]);

        $fake->assertRequest(function (array $requests): void {
            /** @var UserMessage $message */
            $message = $requests[0]->messages()[0];

            $this->assertCount(1, $message->images());
            $this->assertInstanceOf(Image::class, $message->images()[0]);
        });

        @unlink($png);
    }

    public function test_a_tool_result_carries_the_name_of_the_call_it_answers(): void
    {
        $fake = Prism::fake([TextResponseFake::make()->withText('ok')]);

        (new PrismChatDriver())->send([
            Message::user('go'),
            Message::assistant('', [new \ParticleAcademy\TeachersAid\Chat\ToolCall('c1', 'propose_course', ['title' => 'X'])]),
            Message::toolResult('c1', 'Proposed: Create course: X'),
        ]);

        $fake->assertRequest(function (array $requests): void {
            $messages = $requests[0]->messages();

            $this->assertInstanceOf(AssistantMessage::class, $messages[1]);
            $this->assertInstanceOf(ToolResultMessage::class, $messages[2]);

            // Providers match a result to its call by id, but reject a result
            // whose tool name is missing. Only the assistant turn knows it.
            $result = $messages[2]->toolResults[0];

            $this->assertSame('c1', $result->toolCallId);
            $this->assertSame('propose_course', $result->toolName);
            $this->assertSame(['title' => 'X'], $result->args);
        });
    }

    public function test_native_formats_follow_the_provider(): void
    {
        $this->assertContains('application/pdf', (new PrismChatDriver('anthropic'))->nativeMimeTypes());

        // An unknown provider gets text-only rather than a guess: sending bytes
        // a provider rejects costs the whole turn.
        $this->assertSame([], (new PrismChatDriver('something-new'))->nativeMimeTypes());

        // And the host can always override.
        $this->assertSame(
            ['image/png'],
            (new PrismChatDriver('something-new', 'm', ['image/png']))->nativeMimeTypes(),
        );
    }

    public function test_the_agent_runs_end_to_end_on_this_driver(): void
    {
        Prism::fake([
            TextResponseFake::make()->withToolCalls([
                new PrismToolCall('c1', 'propose_course', ['title' => 'Situational Awareness', 'ref' => 'c1']),
            ]),
            TextResponseFake::make()->withText('I have drafted a course for your review.'),
        ]);

        $result = (new TeachersAid(new PrismChatDriver(), maxSteps: 4))
            ->respondTo(Message::user('Build me a course.'));

        $this->assertSame('I have drafted a course for your review.', $result->reply);
        $this->assertTrue($result->hasProposals());
        $this->assertSame('course', $result->plan->operations()[0]->entity);
    }
}
