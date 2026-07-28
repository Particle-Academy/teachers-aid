<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Chat;

/**
 * A file the teacher handed to TAC.
 *
 * Two kinds, and the distinction is the whole point:
 *
 *  - NATIVE   the model reads the bytes itself (PDF, images). The driver passes
 *             them through as a document/image part.
 *  - EXTRACTED  the model cannot read the format (docx, xlsx, csv, pptx), so we
 *             extract text first and send that. `text` is populated and the
 *             bytes are never sent.
 *
 * Which one a file is depends on the driver's capabilities, so the extraction
 * pipeline decides — not the caller.
 */
final class Attachment
{
    public const KIND_NATIVE = 'native';
    public const KIND_EXTRACTED = 'extracted';

    private function __construct(
        public readonly string $kind,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly ?string $path = null,
        public readonly ?string $text = null,
    ) {
    }

    /** Bytes go to the model as-is (PDF, PNG, JPEG…). */
    public static function native(string $path, string $filename, string $mimeType): self
    {
        return new self(self::KIND_NATIVE, $filename, $mimeType, $path);
    }

    /** Only the extracted text goes to the model. */
    public static function extracted(string $filename, string $mimeType, string $text): self
    {
        return new self(self::KIND_EXTRACTED, $filename, $mimeType, null, $text);
    }

    public function isNative(): bool
    {
        return $this->kind === self::KIND_NATIVE;
    }

    /**
     * How an extracted file reads in the prompt. Named so the model knows this
     * is a teacher's file rather than an instruction.
     */
    public function asPromptText(): string
    {
        return "--- FILE: {$this->filename} ---\n{$this->text}\n--- END FILE ---";
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'kind'      => $this->kind,
            'filename'  => $this->filename,
            'mime_type' => $this->mimeType,
            'path'      => $this->path,
            'text'      => $this->text === null ? null : mb_substr($this->text, 0, 200),
        ], static fn ($v) => $v !== null);
    }
}
