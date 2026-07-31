<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Drivers;

use ParticleAcademy\TeachersAid\Chat\Attachment;
use ParticleAcademy\TeachersAid\Chat\ChatResponse;
use ParticleAcademy\TeachersAid\Chat\Message;
use ParticleAcademy\TeachersAid\Chat\ToolCall;
use ParticleAcademy\TeachersAid\Chat\ToolDefinition;
use ParticleAcademy\TeachersAid\Contracts\ChatDriver;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool as PrismTool;
use Prism\Prism\ValueObjects\Media\Document;
use Prism\Prism\ValueObjects\Media\Image;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall as PrismToolCall;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Prism behind the ChatDriver seam. The only file in this package that knows
 * Prism exists.
 *
 * Three translations, per the contract: our messages -> Prism's, our tool
 * definitions -> Prism Tools, and Prism's response -> ChatResponse.
 *
 * ---------------------------------------------------------------------------
 * WHY THE TOOL CLOSURES ARE EMPTY — do not "fix" this
 * ---------------------------------------------------------------------------
 * Prism owns tool execution: its handler calls callTools() on every tool_use
 * response, BEFORE it checks maxSteps, so there is no way through the public
 * API to have it report a tool call without running the closure.
 *
 * So the closures we hand Prism are deliberately inert. The real tools are run
 * by the agent, from ChatResponse::$toolCalls, after this method returns.
 *
 * Putting real behaviour in these closures would execute tools inside the
 * driver, which is exactly what the ChatDriver contract forbids — and it would
 * silently defeat the propose-then-approve guarantee, because the agent's
 * ChangePlan would no longer be the only record of what the model asked for.
 * If you need a tool to do something, give it a handler on the ToolDefinition.
 *
 * withMaxSteps(1) keeps Prism's own agentic loop out of the way: the agent owns
 * the loop so that behaviour is identical across every driver. One send() is
 * one model round-trip.
 */
class PrismChatDriver implements ChatDriver
{
    /**
     * What each provider will accept as raw bytes. Anything absent is extracted
     * to text by the AttachmentPipeline before it ever reaches this class.
     */
    private const PROVIDER_NATIVE = [
        'anthropic' => ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'],
        'openai'    => ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'],
        'gemini'    => ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp'],
    ];

    /** @var list<string>|null */
    private readonly ?array $native;

    /**
     * @param  list<string>|null  $nativeMimeTypes  Overrides the provider default.
     */
    public function __construct(
        private readonly string $provider = 'anthropic',
        private readonly string $model = 'claude-sonnet-5',
        ?array $nativeMimeTypes = null,
        private readonly ?int $maxTokens = null,
    ) {
        $this->native = $nativeMimeTypes;
    }

    public function send(array $messages, array $tools = []): ChatResponse
    {
        $request = Prism::text()
            ->using($this->provider, $this->model)
            // See the class docblock: the agent owns the loop, not Prism.
            ->withMaxSteps(1);

        if ($this->maxTokens !== null) {
            $request->withMaxTokens($this->maxTokens);
        }

        // Providers reject system turns mixed into the message array, so they
        // travel separately rather than as messages.
        $system = $this->systemPrompt($messages);

        if ($system !== '') {
            $request->withSystemPrompt($system);
        }

        $request->withMessages($this->mapMessages($messages));

        if ($tools !== []) {
            $request->withTools(array_map(
                fn (ToolDefinition $tool): PrismTool => $this->mapTool($tool),
                array_values($tools),
            ));
        }

        $response = $request->asText();

        return new ChatResponse(
            $response->text ?? '',
            array_map(
                fn (PrismToolCall $call): ToolCall => new ToolCall(
                    (string) $call->id,
                    $call->name,
                    $call->arguments(),
                ),
                $response->toolCalls,
            ),
        );
    }

    public function nativeMimeTypes(): array
    {
        return $this->native
            ?? self::PROVIDER_NATIVE[strtolower($this->provider)]
            // An unrecognised provider gets text-only rather than a guess:
            // extracting a PDF we could have sent natively costs some fidelity,
            // but sending bytes a provider rejects costs the whole turn.
            ?? [];
    }

    /**
     * @param  list<Message>  $messages
     */
    private function systemPrompt(array $messages): string
    {
        $parts = [];

        foreach ($messages as $message) {
            if ($message->role === Message::ROLE_SYSTEM) {
                $parts[] = $message->content;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  list<Message>  $messages
     * @return list<UserMessage|AssistantMessage|ToolResultMessage>
     */
    private function mapMessages(array $messages): array
    {
        // A tool result has to carry the name and arguments of the call it
        // answers, which only the assistant turn knows. Index them first.
        $callsById = [];

        foreach ($messages as $message) {
            foreach ($message->toolCalls as $call) {
                $callsById[$call->id] = $call;
            }
        }

        $mapped = [];

        foreach ($messages as $message) {
            $mapped[] = match ($message->role) {
                Message::ROLE_SYSTEM => null, // Sent via withSystemPrompt().
                Message::ROLE_USER => new UserMessage(
                    $message->content,
                    $this->mapAttachments($message->attachments),
                ),
                Message::ROLE_ASSISTANT => new AssistantMessage(
                    $message->content,
                    array_map(
                        fn (ToolCall $call): PrismToolCall => new PrismToolCall(
                            $call->id,
                            $call->name,
                            $call->arguments,
                        ),
                        $message->toolCalls,
                    ),
                ),
                Message::ROLE_TOOL => $this->mapToolResult($message, $callsById),
                default => null,
            };
        }

        return array_values(array_filter($mapped));
    }

    /**
     * @param  array<string, ToolCall>  $callsById
     */
    private function mapToolResult(Message $message, array $callsById): ToolResultMessage
    {
        $id = (string) $message->toolCallId;
        $call = $callsById[$id] ?? null;

        return new ToolResultMessage([
            new ToolResult(
                toolCallId: $id,
                // Falling back to the id keeps a malformed history from throwing;
                // the model reads the result content, not this label.
                toolName: $call->name ?? $id,
                args: $call->arguments ?? [],
                result: $message->content,
            ),
        ]);
    }

    /**
     * @param  list<Attachment>  $attachments
     * @return list<Document|Image>
     */
    private function mapAttachments(array $attachments): array
    {
        $mapped = [];

        foreach ($attachments as $attachment) {
            if (! $attachment->isNative()) {
                // Already extracted upstream. It travels as a titled text
                // document so the model can tell one source file from another.
                $mapped[] = Document::fromText((string) $attachment->text, $attachment->filename);

                continue;
            }

            $mapped[] = str_starts_with($attachment->mimeType, 'image/')
                ? Image::fromLocalPath((string) $attachment->path, $attachment->mimeType)
                : Document::fromLocalPath((string) $attachment->path, $attachment->filename);
        }

        return $mapped;
    }

    /**
     * Our JSON Schema, unchanged, as a Prism tool.
     *
     * RawSchema is what makes this lossless: Prism's fluent withStringParameter()
     * builders cannot express everything JSON Schema can (nested arrays of
     * objects, for one, which propose_question needs).
     */
    private function mapTool(ToolDefinition $definition): PrismTool
    {
        $tool = (new PrismTool())
            ->as($definition->name)
            ->for($definition->description)
            // Inert on purpose. See the class docblock.
            ->using(static fn (): string => 'recorded');

        $properties = (array) ($definition->parameters['properties'] ?? []);
        $required = (array) ($definition->parameters['required'] ?? []);

        foreach ($properties as $name => $schema) {
            $tool->withParameter(
                new RawSchema((string) $name, (array) $schema),
                in_array($name, $required, true),
            );
        }

        return $tool;
    }
}
