<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Drivers;

use ParticleAcademy\TeachersAid\Chat\ToolDefinition;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool as PrismTool;

/**
 * Our JSON Schema tools as Prism tools.
 *
 * Two flavours, because there are two loops:
 *
 *  - inert(): for PrismChatDriver, where TeachersAid runs the loop and calls the
 *    handler itself. Prism must not run it too.
 *  - live(): for a runtime that owns the loop — prism-harness — where Prism
 *    calls the tool and the handler has to do the work.
 *
 * Either way the handler is the same ToolDefinition, so what a tool may do does
 * not depend on which loop ran it.
 */
final class PrismTools
{
    public static function inert(ToolDefinition $definition): PrismTool
    {
        return self::build($definition, static fn (): string => 'recorded');
    }

    public static function live(ToolDefinition $definition): PrismTool
    {
        // Prism calls the handler with the arguments as NAMED parameters, and a
        // variadic collects named arguments under their names — exactly the
        // array a ToolDefinition takes.
        return self::build($definition, static fn (mixed ...$arguments): string => $definition->run($arguments));
    }

    /**
     * RawSchema is what makes this lossless: Prism's fluent withStringParameter()
     * builders cannot express everything JSON Schema can (nested arrays of
     * objects, for one, which propose_question needs).
     */
    private static function build(ToolDefinition $definition, callable $handler): PrismTool
    {
        $tool = (new PrismTool())
            ->as($definition->name)
            ->for($definition->description)
            ->using($handler);

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
