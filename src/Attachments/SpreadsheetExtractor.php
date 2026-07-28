<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Attachments;

use ParticleAcademy\TeachersAid\Contracts\ExtractsText;
use ParticleAcademy\TeachersAid\Exceptions\UnsupportedFileException;

/**
 * .xlsx via particle-academy/holy-sheet. Optional dependency — see WordExtractor.
 */
class SpreadsheetExtractor implements ExtractsText
{
    public function mimeTypes(): array
    {
        return ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    }

    public function extensions(): array
    {
        return ['xlsx'];
    }

    public function extract(string $path): string
    {
        $reader = '\\ParticleAcademy\\HolySheet\\Reader';

        if (! class_exists($reader)) {
            throw new UnsupportedFileException(
                'Reading .xlsx needs particle-academy/holy-sheet. Run: composer require particle-academy/holy-sheet'
            );
        }

        return (string) $reader::toMarkdown($path);
    }
}
