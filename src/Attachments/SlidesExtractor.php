<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Attachments;

use ParticleAcademy\TeachersAid\Contracts\ExtractsText;
use ParticleAcademy\TeachersAid\Exceptions\UnsupportedFileException;

/**
 * .pptx via particle-academy/dark-slide. Optional dependency — see WordExtractor.
 */
class SlidesExtractor implements ExtractsText
{
    public function mimeTypes(): array
    {
        return ['application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    }

    public function extensions(): array
    {
        return ['pptx'];
    }

    public function extract(string $path): string
    {
        $reader = '\\ParticleAcademy\\DarkSlide\\Reader';

        if (! class_exists($reader)) {
            throw new UnsupportedFileException(
                'Reading .pptx needs particle-academy/dark-slide. Run: composer require particle-academy/dark-slide'
            );
        }

        return (string) $reader::toMarkdown($path);
    }
}
