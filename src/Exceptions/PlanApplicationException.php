<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Exceptions;

use RuntimeException;

/** A plan could not be applied. The transaction has already rolled back. */
class PlanApplicationException extends RuntimeException
{
}
