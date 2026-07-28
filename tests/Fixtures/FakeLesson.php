<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class FakeLesson extends Model
{
    protected $table = 'lessons';

    protected $guarded = [];
}
