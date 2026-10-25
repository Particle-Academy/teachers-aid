<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * The host's veto over a proposed delete.
 *
 * This package does not know what hangs off a record in your schema — a
 * course with enrollments, a curriculum somebody holds a certificate for —
 * and a reviewer skimming a plan may not either. Bind your own to refuse
 * deletes that would orphan something; the shipped binding allows every
 * delete, which is what the applier always did.
 *
 * Asked twice: when the agent proposes the delete, so it can say so in the
 * conversation, and again when the plan is applied, because the world may
 * have changed in between.
 */
interface GuardsDeletion
{
    /** Null to allow, or the reason it must not be deleted. */
    public function refusal(string $entity, Model $record): ?string;
}
