<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Support;

use Illuminate\Database\Eloquent\Model;
use ParticleAcademy\TeachersAid\Contracts\GuardsDeletion;

/** The default: no veto. Bind your own GuardsDeletion to protect records. */
final class AllowAllDeletes implements GuardsDeletion
{
    public function refusal(string $entity, Model $record): ?string
    {
        return null;
    }
}
