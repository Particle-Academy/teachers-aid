<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid;

use Illuminate\Support\ServiceProvider;
use ParticleAcademy\TeachersAid\Agent\TeachersAid;
use ParticleAcademy\TeachersAid\Attachments\AttachmentPipeline;
use ParticleAcademy\TeachersAid\Attachments\CsvExtractor;
use ParticleAcademy\TeachersAid\Attachments\SlidesExtractor;
use ParticleAcademy\TeachersAid\Attachments\SpreadsheetExtractor;
use ParticleAcademy\TeachersAid\Attachments\WordExtractor;
use ParticleAcademy\TeachersAid\Contracts\ChatDriver;
use ParticleAcademy\TeachersAid\Plan\PlanApplier;

class TeachersAidServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/teachers-aid.php', 'teachers-aid');

        $this->app->singleton(AttachmentPipeline::class, fn () => new AttachmentPipeline([
            new CsvExtractor(),
            new WordExtractor(),
            new SpreadsheetExtractor(),
            new SlidesExtractor(),
        ]));

        $this->app->singleton(PlanApplier::class);

        // No default ChatDriver binding. The host chooses its LLM library, and a
        // wrong guess here would be worse than a clear "you must bind one" —
        // silently talking to the wrong provider is expensive in both senses.
        $this->app->bind(TeachersAid::class, fn ($app) => new TeachersAid(
            $app->make(ChatDriver::class),
            (int) config('teachers-aid.max_steps', 8),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/teachers-aid.php' => config_path('teachers-aid.php'),
            ], 'teachers-aid-config');
        }
    }
}
