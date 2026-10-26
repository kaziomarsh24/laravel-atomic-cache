<?php

namespace KaziOmar\AtomicCache\Exceptions;

use RuntimeException;
use Throwable;

class ChunkRestorationException extends RuntimeException
{
    public function __construct(
        string $message,
        protected ?string $generation = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getGeneration(): ?string
    {
        return $this->generation;
    }
}
