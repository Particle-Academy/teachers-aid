<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Feature\AttachmentPipelineTest;

use ParticleAcademy\TeachersAid\Attachments\AttachmentPipeline;
use ParticleAcademy\TeachersAid\Attachments\CsvExtractor;
use ParticleAcademy\TeachersAid\Attachments\WordExtractor;
use ParticleAcademy\TeachersAid\Exceptions\UnsupportedFileException;
use ParticleAcademy\TeachersAid\Tests\Fixtures\ScriptedDriver;
use ParticleAcademy\TeachersAid\Tests\TestCase;

function tempFile(string $name, string $contents): string {
    $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('tac_', true).'_'.$name;
    file_put_contents($path, $contents);

    return $path;
}

test('a format the model reads is passed through untouched', function () {
    $pipeline = new AttachmentPipeline([new CsvExtractor()]);
    $driver = new ScriptedDriver([], native: ['application/pdf']);

    $path = tempFile('handbook.pdf', '%PDF-1.4');

    $attachment = $pipeline->prepare($path, 'handbook.pdf', 'application/pdf', $driver);

    $this->assertTrue($attachment->isNative());
    $this->assertSame($path, $attachment->path);
    $this->assertNull($attachment->text);
});

test('a format the model cannot read is extracted to text', function () {
    $pipeline = new AttachmentPipeline([new CsvExtractor()]);
    $driver = new ScriptedDriver([], native: ['application/pdf']);

    $path = tempFile('questions.csv', "prompt,answer\nWhat is a patrol?,A route\n");

    $attachment = $pipeline->prepare($path, 'questions.csv', 'text/csv', $driver);

    $this->assertFalse($attachment->isNative());
    $this->assertStringContainsString('What is a patrol?', (string) $attachment->text);
    // Bytes are never sent for an extracted file.
    $this->assertNull($attachment->path);
});

test('the same file is treated differently by a text only driver', function () {
    $pipeline = new AttachmentPipeline([new CsvExtractor()]);

    // A driver that accepts nothing natively — the pipeline must adapt
    // rather than the caller having to know.
    $textOnly = new ScriptedDriver([], native: []);
    $path = tempFile('notes.txt', 'Patrol basics');

    $attachment = $pipeline->prepare($path, 'notes.txt', 'text/plain', $textOnly);

    $this->assertFalse($attachment->isNative());
});

test('extraction falls back to the extension when the mime type is useless', function () {
    $pipeline = new AttachmentPipeline([new CsvExtractor()]);
    $driver = new ScriptedDriver([], native: []);

    // Browsers frequently send this for a CSV.
    $path = tempFile('bank.csv', "a,b\n1,2\n");

    $attachment = $pipeline->prepare($path, 'bank.csv', 'application/octet-stream', $driver);

    $this->assertFalse($attachment->isNative());
    $this->assertStringContainsString('a | b', (string) $attachment->text);
});

test('long extractions are truncated loudly', function () {
    config(['teachers-aid.uploads.max_text_chars' => 50]);

    $pipeline = new AttachmentPipeline([new CsvExtractor()]);
    $driver = new ScriptedDriver([], native: []);

    $path = tempFile('big.csv', str_repeat("some,long,row\n", 200));

    $attachment = $pipeline->prepare($path, 'big.csv', 'text/csv', $driver);

    // Silently truncating would have the model build a course from material
    // it never saw, and sound confident about it.
    $this->assertStringContainsString('TRUNCATED', (string) $attachment->text);
});

test('an unreadable format is refused with a reason', function () {
    $pipeline = new AttachmentPipeline([new CsvExtractor()]);
    $driver = new ScriptedDriver([], native: []);

    $path = tempFile('clip.mp4', 'not really a video');

    $this->expectException(UnsupportedFileException::class);

    $pipeline->prepare($path, 'clip.mp4', 'video/mp4', $driver);
});

test('an office format without its optional package says which to install', function () {
    $pipeline = new AttachmentPipeline([new WordExtractor()]);
    $driver = new ScriptedDriver([], native: []);

    $path = tempFile('course.docx', 'PK');

    $this->expectException(UnsupportedFileException::class);
    $this->expectExceptionMessageMatches('/last-word/');

    $pipeline->prepare($path, 'course.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', $driver);
});
