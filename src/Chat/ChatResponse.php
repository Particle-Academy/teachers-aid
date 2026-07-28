<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Chat;

/** What a driver hands back for one model call. */
final class ChatResponse
{
    /**
     * @param  list<ToolCall>  $toolCalls
     */
    public function __construct(
        public readonly string $text = '',
        public readonly array $toolCalls = [],
    ) {
    }

    public function wantsTools(): bool
    {
        return $this->toolCalls !== [];
    }
}
