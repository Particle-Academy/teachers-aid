<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Contracts;

/**
 * Pulls readable text out of one file format.
 *
 * One implementation per format, registered with the AttachmentPipeline. Adding
 * a format is a new class and a config line — the agent never changes.
 */
interface ExtractsText
{
    /** @return list<string> MIME types this handles. */
    public function mimeTypes(): array;

    /** @return list<string> lower-case extensions, for when the MIME type is unhelpful. */
    public function extensions(): array;

    public function extract(string $path): string;
}
