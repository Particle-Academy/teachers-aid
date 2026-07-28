<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Chat;

/**
 * A tool, described once in JSON Schema and translated by each driver.
 *
 * Every mainstream LLM library ultimately wants name + description + a JSON
 * Schema object for the parameters, so that is what we store. Prism's fluent
 * `withStringParameter()` builders, the Laravel AI SDK's arrays and the Vercel
 * AI SDK's Zod schemas are all reachable from here; the reverse is not true.
 */
final class ToolDefinition
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema (type: object).
     * @param  callable(array<string, mixed>): string  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameters,
        public readonly mixed $handler,
    ) {
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function run(array $arguments): string
    {
        return ($this->handler)($arguments);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(): array
    {
        return [
            'name'        => $this->name,
            'description' => $this->description,
            'parameters'  => $this->parameters,
        ];
    }

    /**
     * Convenience for the common shape: an object with typed properties.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @param  callable(array<string, mixed>): string  $handler
     */
    public static function make(
        string $name,
        string $description,
        array $properties,
        array $required,
        callable $handler,
    ): self {
        return new self($name, $description, [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
        ], $handler);
    }
}
