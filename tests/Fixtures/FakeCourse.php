<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class FakeCourse extends Model
{
    protected $table = 'courses';

    protected $guarded = [];
}
