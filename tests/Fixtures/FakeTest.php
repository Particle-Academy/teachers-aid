<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class FakeTest extends Model
{
    protected $table = 'tests';

    protected $guarded = [];
}
