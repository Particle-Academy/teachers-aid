<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class FakeQuestionOption extends Model
{
    protected $table = 'question_options';

    protected $guarded = [];
}
