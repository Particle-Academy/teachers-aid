<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FakeCurriculum extends Model
{
    protected $table = 'curriculums';

    protected $guarded = [];

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(FakeCourse::class, 'curriculum_course', 'curriculum_id', 'course_id')
            ->withPivot(['sort_order']);
    }
}
