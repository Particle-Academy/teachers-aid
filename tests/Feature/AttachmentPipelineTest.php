<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Feature;

use ParticleAcademy\TeachersAid\Attachments\AttachmentPipeline;
use ParticleAcademy\TeachersAid\Attachments\CsvExtractor;
use ParticleAcademy\TeachersAid\Attachments\WordExtractor;
use ParticleAcademy\TeachersAid\Exceptions\UnsupportedFileException;
use ParticleAcademy\TeachersAid\Tests\Fixtures\ScriptedDriver;
use ParticleAcademy\TeachersAid\Tests\TestCase;

class AttachmentPipelineTest extends TestCase
{
    private function tempFile(string $name, string $contents): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('tac_', true).'_'.$name;
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_a_format_the_model_reads_is_passed_through_untouched(): void
    {
        $pipeline = new AttachmentPipeline([new CsvExtractor()]);
        $driver = new ScriptedDriver([], native: ['application/pdf']);

        $path = $this->tempFile('handbook.pdf', '%PDF-1.4');

        $attachment = $pipeline->prepare($path, 'handbook.pdf', 'application/pdf', $driver);

        $this->assertTrue($attachment->isNative());
        $this->assertSame($path, $attachment->path);
        $this->assertNull($attachment->text);
    }

    public function test_a_format_the_model_cannot_read_is_extracted_to_text(): void
    {
        $pipeline = new AttachmentPipeline([new CsvExtractor()]);
        $driver = new ScriptedDriver([], native: ['application/pdf']);

        $path = $this->tempFile('questions.csv', "prompt,answer\nWhat is a patrol?,A route\n");

        $attachment = $pipeline->prepare($path, 'questions.csv', 'text/csv', $driver);

        $this->assertFalse($attachment->isNative());
        $this->assertStringContainsString('What is a patrol?', (string) $attachment->text);
        // Bytes are never sent for an extracted file.
        $this->assertNull($attachment->path);
    }

    public function test_the_same_file_is_treated_differently_by_a_text_only_driver(): void
    {
        $pipeline = new AttachmentPipeline([new CsvExtractor()]);

        // A driver that accepts nothing natively — the pipeline must adapt
        // rather than the caller having to know.
        $textOnly = new ScriptedDriver([], native: []);
        $path = $this->tempFile('notes.txt', 'Patrol basics');

        $attachment = $pipeline->prepare($path, 'notes.txt', 'text/plain', $textOnly);

        $this->assertFalse($attachment->isNative());
    }

    public function test_extraction_falls_back_to_the_extension_when_the_mime_type_is_useless(): void
    {
        $pipeline = new AttachmentPipeline([new CsvExtractor()]);
        $driver = new ScriptedDriver([], native: []);

        // Browsers frequently send this for a CSV.
        $path = $this->tempFile('bank.csv', "a,b\n1,2\n");

        $attachment = $pipeline->prepare($path, 'bank.csv', 'application/octet-stream', $driver);

        $this->assertFalse($attachment->isNative());
        $this->assertStringContainsString('a | b', (string) $attachment->text);
    }

    public function test_long_extractions_are_truncated_loudly(): void
    {
        config(['teachers-aid.uploads.max_text_chars' => 50]);

        $pipeline = new AttachmentPipeline([new CsvExtractor()]);
        $driver = new ScriptedDriver([], native: []);

        $path = $this->tempFile('big.csv', str_repeat("some,long,row\n", 200));

        $attachment = $pipeline->prepare($path, 'big.csv', 'text/csv', $driver);

        // Silently truncating would have the model build a course from material
        // it never saw, and sound confident about it.
        $this->assertStringContainsString('TRUNCATED', (string) $attachment->text);
    }

    public function test_an_unreadable_format_is_refused_with_a_reason(): void
    {
        $pipeline = new AttachmentPipeline([new CsvExtractor()]);
        $driver = new ScriptedDriver([], native: []);

        $path = $this->tempFile('clip.mp4', 'not really a video');

        $this->expectException(UnsupportedFileException::class);

        $pipeline->prepare($path, 'clip.mp4', 'video/mp4', $driver);
    }

    public function test_an_office_format_without_its_optional_package_says_which_to_install(): void
    {
        $pipeline = new AttachmentPipeline([new WordExtractor()]);
        $driver = new ScriptedDriver([], native: []);

        $path = $this->tempFile('course.docx', 'PK');

        $this->expectException(UnsupportedFileException::class);
        $this->expectExceptionMessageMatches('/last-word/');

        $pipeline->prepare($path, 'course.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $driver);
    }
}
