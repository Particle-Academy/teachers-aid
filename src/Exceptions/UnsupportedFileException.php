<?php

declare(strict_types=1);

namespace ParticleAcademy\TeachersAid\Exceptions;

use RuntimeException;

/** A file arrived that neither the model nor any extractor can read. */
class UnsupportedFileException extends RuntimeException
{
}
