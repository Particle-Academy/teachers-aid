<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Chat;

/**
 * One turn of conversation, in this package's own shape.
 *
 * Deliberately not a Prism message, nor a Laravel AI SDK one. The agent and its
 * tools are written against these types only, so swapping the LLM library means
 * writing one driver rather than touching the agent.
 */
final class Message
{
    public const ROLE_SYSTEM = 'system';
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL = 'tool';

    /**
     * @param  list<Attachment>  $attachments
     * @param  list<ToolCall>  $toolCalls   Assistant turns only.
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content = '',
        public readonly array $attachments = [],
        public readonly array $toolCalls = [],
        public readonly ?string $toolCallId = null,
    ) {
    }

    /**
     * @param  list<Attachment>  $attachments
     */
    public static function user(string $content, array $attachments = []): self
    {
        return new self(self::ROLE_USER, $content, $attachments);
    }

    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public static function assistant(string $content = '', array $toolCalls = []): self
    {
        return new self(self::ROLE_ASSISTANT, $content, [], $toolCalls);
    }

    /** The result handed back to the model after a tool ran. */
    public static function toolResult(string $toolCallId, string $content): self
    {
        return new self(self::ROLE_TOOL, $content, [], [], $toolCallId);
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'role'         => $this->role,
            'content'      => $this->content,
            'attachments'  => array_map(static fn (Attachment $a) => $a->toArray(), $this->attachments),
            'tool_calls'   => array_map(static fn (ToolCall $c) => $c->toArray(), $this->toolCalls),
            'tool_call_id' => $this->toolCallId,
        ], static fn ($v) => $v !== null && $v !== [] && $v !== '');
    }
}
