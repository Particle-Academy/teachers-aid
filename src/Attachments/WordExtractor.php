<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Attachments;

use ParticleAcademy\TeachersAid\Contracts\ExtractsText;
use ParticleAcademy\TeachersAid\Exceptions\UnsupportedFileException;

/**
 * .docx via particle-academy/last-word.
 *
 * The dependency is optional (it is in `suggest`, not `require`), so this fails
 * with an actionable message rather than a class-not-found if a teacher uploads
 * a Word file to a host that never installed it.
 */
class WordExtractor implements ExtractsText
{
    public function mimeTypes(): array
    {
        return ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
    }

    public function extensions(): array
    {
        return ['docx'];
    }

    public function extract(string $path): string
    {
        $reader = '\\ParticleAcademy\\LastWord\\Reader';

        if (! class_exists($reader)) {
            throw new UnsupportedFileException(
                'Reading .docx needs particle-academy/last-word. Run: composer require particle-academy/last-word'
            );
        }

        return (string) $reader::toMarkdown($path);
    }
}
