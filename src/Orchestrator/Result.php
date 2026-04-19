<?php

namespace SrvClick\Scurlv2\Orchestrator;

use SrvClick\Scurlv2\Response;

/**
 * Resultado agregado de una ejecución completa del Orchestrator.
 * Contiene el resultado de cada step, cuál fue el último, y si el
 * flujo terminó correctamente o fue cancelado.
 */
class Result
{
    /** @var array<string, StepResult> */
    protected array $steps = [];

    /** @var string[] orden en el que fueron ejecutados (incluyendo saltos) */
    protected array $executionOrder = [];

    protected ?string $failedAt = null;
    protected bool    $cancelled = false;
    protected bool    $finished  = false;

    public function addStepResult(StepResult $result): void
    {
        $this->steps[$result->name] = $result;
        $this->executionOrder[] = $result->name;
    }

    public function markFailed(string $stepName): void
    {
        $this->failedAt = $stepName;
        $this->cancelled = true;
    }

    public function markFinished(): void
    {
        $this->finished = true;
    }

    public function isSuccess(): bool
    {
        return $this->finished && $this->failedAt === null && !$this->cancelled;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function failedAt(): ?string
    {
        return $this->failedAt;
    }

    /**
     * @return array<string, StepResult>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function get(string $stepName): ?StepResult
    {
        return $this->steps[$stepName] ?? null;
    }

    /**
     * Acceso directo al Response de un step por nombre.
     */
    public function response(string $stepName): ?Response
    {
        return $this->steps[$stepName]->response ?? null;
    }

    public function lastStepResult(): ?StepResult
    {
        if (empty($this->executionOrder)) {
            return null;
        }
        $lastName = end($this->executionOrder);
        return $this->steps[$lastName] ?? null;
    }

    public function lastResponse(): ?Response
    {
        return $this->lastStepResult()?->response;
    }

    /**
     * @return string[]
     */
    public function executionOrder(): array
    {
        return $this->executionOrder;
    }
}
