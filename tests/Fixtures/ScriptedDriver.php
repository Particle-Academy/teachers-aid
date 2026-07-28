<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Fixtures;

use ParticleAcademy\TeachersAid\Chat\ChatResponse;
use ParticleAcademy\TeachersAid\Contracts\ChatDriver;

/**
 * A driver that replays a script instead of calling a model.
 *
 * This existing at all is the point of the ChatDriver seam: the whole agent —
 * loop, tools, plan — is testable with no provider, no key and no network.
 */
class ScriptedDriver implements ChatDriver
{
    /** @var list<ChatResponse> */
    private array $script;

    /** @var list<array{messages: array, tools: array}> */
    public array $calls = [];

    /**
     * @param  list<ChatResponse>  $script
     * @param  list<string>  $native
     */
    public function __construct(array $script, private readonly array $native = ['application/pdf', 'image/png'])
    {
        $this->script = $script;
    }

    public function send(array $messages, array $tools = []): ChatResponse
    {
        $this->calls[] = ['messages' => $messages, 'tools' => $tools];

        // Running past the script means the agent looped more than expected —
        // return a plain reply so the loop terminates and the test can assert.
        return array_shift($this->script) ?? new ChatResponse('(script exhausted)');
    }

    public function nativeMimeTypes(): array
    {
        return $this->native;
    }
}
