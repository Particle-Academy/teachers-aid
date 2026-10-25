<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class FakeQuestion extends Model
{
    protected $table = 'questions';

    protected $guarded = [];
}
