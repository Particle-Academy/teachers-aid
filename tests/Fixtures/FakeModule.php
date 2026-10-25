<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class FakeModule extends Model
{
    protected $table = 'modules';

    protected $guarded = [];
}
