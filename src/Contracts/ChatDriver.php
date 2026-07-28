<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Contracts;

use ParticleAcademy\TeachersAid\Chat\ChatResponse;
use ParticleAcademy\TeachersAid\Chat\Message;
use ParticleAcademy\TeachersAid\Chat\ToolDefinition;

/**
 * The single seam between TAC and whatever LLM library you use.
 *
 * The agent, its tools and the plan model are written against this package's
 * own message/tool types and never import Prism, the Laravel AI SDK or anything
 * else. Swapping libraries means writing one class, not rewriting the agent.
 *
 * A driver is responsible for three translations:
 *
 *   1. our Message[] -> the library's message shape, including attachments
 *      (native ones as document/image parts, extracted ones as text)
 *   2. our ToolDefinition[] -> the library's tool/function definitions
 *   3. the library's response -> ChatResponse, with any tool calls normalised
 *
 * A driver MUST NOT execute tools. It reports what the model asked for; the
 * agent decides whether to run it. That is what keeps the approval flow
 * enforceable rather than advisory.
 */
interface ChatDriver
{
    /**
     * One model call. No looping — the agent owns the multi-step loop so the
     * step limit and tool policy stay in one place across every library.
     *
     * @param  list<Message>  $messages
     * @param  list<ToolDefinition>  $tools
     */
    public function send(array $messages, array $tools = []): ChatResponse;

    /**
     * MIME types this driver can hand to the model as raw bytes.
     *
     * Anything else gets extracted to text first. Returning [] is valid and
     * simply means "text only" — the pipeline will extract everything.
     *
     * @return list<string>
     */
    public function nativeMimeTypes(): array;
}
