<?php

namespace SrvClick\Scurlv2\Orchestrator;

use SrvClick\Scurlv2\Response;
use Throwable;

/**
 * Resultado de la ejecución de un Step individual dentro del Orchestrator.
 * Es inmutable y contiene toda la información necesaria para diagnosticar
 * qué pasó en ese paso.
 */
class StepResult
{
    public function __construct(
        public readonly string     $name,
        public readonly bool       $passed,
        public readonly int        $attempts,
        public readonly ?Response  $response,
        public readonly ?string    $failureReason = null,
        public readonly ?Throwable $exception = null,
        public readonly bool       $usedFreshInstance = false,
    ) {}

    public function isOk(): bool
    {
        return $this->passed;
    }

    public function response(): ?Response
    {
        return $this->response;
    }

    public function failureReason(): ?string
    {
        return $this->failureReason;
    }

    public function exception(): ?Throwable
    {
        return $this->exception;
    }
}
