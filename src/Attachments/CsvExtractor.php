<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Attachments;

use ParticleAcademy\TeachersAid\Contracts\ExtractsText;

/**
 * CSV, with no dependency — it is already text.
 *
 * Rendered as pipe-delimited rows rather than raw CSV so the model reads it as
 * a table; raw commas inside quoted fields are easy for it to misparse.
 */
class CsvExtractor implements ExtractsText
{
    public function mimeTypes(): array
    {
        return ['text/csv', 'application/csv', 'text/plain'];
    }

    public function extensions(): array
    {
        return ['csv', 'txt'];
    }

    public function extract(string $path): string
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $lines = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lines[] = implode(' | ', array_map(static fn ($c) => trim((string) $c), $row));
        }

        fclose($handle);

        return implode("\n", $lines);
    }
}
