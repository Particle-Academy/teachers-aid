<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Attachments;

use ParticleAcademy\TeachersAid\Chat\Attachment;
use ParticleAcademy\TeachersAid\Contracts\ChatDriver;
use ParticleAcademy\TeachersAid\Contracts\ExtractsText;
use ParticleAcademy\TeachersAid\Exceptions\UnsupportedFileException;

/**
 * Decides, per file, whether the model reads the bytes or reads our text.
 *
 * The driver is asked what it can read natively rather than the list being
 * hard-coded, because it genuinely differs: most providers take PDFs and
 * images, a text-only driver takes neither. Same uploaded file, different
 * treatment, no caller changes.
 */
class AttachmentPipeline
{
    /** @var list<ExtractsText> */
    private array $extractors;

    /**
     * @param  list<ExtractsText>  $extractors
     */
    public function __construct(array $extractors = [])
    {
        $this->extractors = $extractors;
    }

    public function register(ExtractsText $extractor): void
    {
        $this->extractors[] = $extractor;
    }

    /**
     * @throws UnsupportedFileException
     */
    public function prepare(string $path, string $filename, string $mimeType, ChatDriver $driver): Attachment
    {
        if (in_array($mimeType, $driver->nativeMimeTypes(), true)) {
            return Attachment::native($path, $filename, $mimeType);
        }

        $extractor = $this->extractorFor($mimeType, $filename);

        if ($extractor === null) {
            throw new UnsupportedFileException(
                "Nothing can read {$filename} ({$mimeType}). The model does not accept it directly "
                .'and no extractor is registered for it.'
            );
        }

        $text = $extractor->extract($path);
        $limit = (int) config('teachers-aid.uploads.max_text_chars', 200_000);

        if (mb_strlen($text) > $limit) {
            // Truncate loudly. Silently sending half a handbook would have the
            // model confidently build a course from material it never saw.
            $text = mb_substr($text, 0, $limit)
                ."\n\n[TRUNCATED: {$filename} was longer than {$limit} characters. "
                .'Ask the teacher to split it if the rest matters.]';
        }

        return Attachment::extracted($filename, $mimeType, $text);
    }

    private function extractorFor(string $mimeType, string $filename): ?ExtractsText
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        foreach ($this->extractors as $extractor) {
            if (in_array($mimeType, $extractor->mimeTypes(), true)) {
                return $extractor;
            }
        }

        // MIME types on uploads are frequently wrong or generic
        // (application/octet-stream), so fall back to the extension.
        foreach ($this->extractors as $extractor) {
            if ($ext !== '' && in_array($ext, $extractor->extensions(), true)) {
                return $extractor;
            }
        }

        return null;
    }
}
