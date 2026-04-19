<?php

namespace SrvClick\Scurlv2;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use SrvClick\Scurlv2\Orchestrator\Result;
use SrvClick\Scurlv2\Orchestrator\Step;
use SrvClick\Scurlv2\Orchestrator\StepResult;
use Throwable;

/**
 * Orquestador de múltiples peticiones Scurl con control de flujo,
 * validación de respuesta, reintentos y recuperación ante fallos.
 *
 * Principios:
 *   - Por defecto usa UNA sola instancia de Scurl durante todo el flujo,
 *     manteniendo sesión (cookies, headers, proxy, config).
 *   - Un step puede marcarse con ->fresh() para usar una instancia nueva
 *     solo para ese paso, sin afectar el Scurl principal.
 *   - Cada step declara qué espera (status, body, JSON) y qué hacer si falla.
 */
class Orchestrator
{
    protected Scurl $scurl;

    /** @var array<string, Step> en orden de declaración */
    protected array $steps = [];

    /** Hooks globales opcionales */
    protected ?Closure $onStepSuccess = null;
    protected ?Closure $onStepFailure = null;

    public function __construct(?Scurl $scurl = null)
    {
        $this->scurl = $scurl ?? new Scurl();
    }

    /**
     * Inyecta un Scurl ya preconfigurado (headers globales, proxy, auth).
     */
    public function scurl(Scurl $scurl): self
    {
        $this->scurl = $scurl;
        return $this;
    }

    public function getScurl(): Scurl
    {
        return $this->scurl;
    }

    /**
     * Declara un nuevo paso. El nombre sirve tanto para referenciarlo en
     * next()/onFail() como para leer su respuesta desde Result.
     *
     * Si el nombre ya existe, se reutiliza (idempotente).
     */
    public function step(string $name): Step
    {
        if (isset($this->steps[$name])) {
            return $this->steps[$name];
        }
        $step = new Step($name, $this);
        $this->steps[$name] = $step;
        return $step;
    }

    public function hasStep(string $name): bool
    {
        return isset($this->steps[$name]);
    }

    /**
     * @return array<string, Step>
     */
    public function steps(): array
    {
        return $this->steps;
    }

    public function onStepSuccess(callable $fn): self
    {
        $this->onStepSuccess = Closure::fromCallable($fn);
        return $this;
    }

    public function onStepFailure(callable $fn): self
    {
        $this->onStepFailure = Closure::fromCallable($fn);
        return $this;
    }

    /**
     * Ejecuta el flujo.
     *
     * @param string|null $startAt  Nombre del paso por el cual empezar (default: el primero)
     */
    public function run(?string $startAt = null): Result
    {
        if (empty($this->steps)) {
            throw new RuntimeException('Orchestrator: no hay steps declarados.');
        }

        $result = new Result();
        $stepNames = array_keys($this->steps);

        $currentName = $startAt ?? $this->findFirstInFlowStep($stepNames);
        if ($currentName === null) {
            throw new RuntimeException('Orchestrator: todos los steps están marcados como offFlow, no hay punto de entrada natural.');
        }
        if (!isset($this->steps[$currentName])) {
            throw new InvalidArgumentException("Orchestrator: startAt '{$currentName}' no existe.");
        }

        // Límite defensivo de saltos para evitar loops infinitos por onFail mal configurado.
        $maxTransitions = count($this->steps) * 50;
        $transitions = 0;

        while ($currentName !== null) {
            if (++$transitions > $maxTransitions) {
                throw new RuntimeException(
                    "Orchestrator: se excedió el máximo de transiciones ({$maxTransitions}). Posible loop en onFail/next."
                );
            }

            $step = $this->steps[$currentName] ?? null;
            if ($step === null) {
                throw new InvalidArgumentException("Orchestrator: step '{$currentName}' no existe.");
            }

            $stepResult = $this->executeStep($step, $result);
            $result->addStepResult($stepResult);

            if ($stepResult->passed) {
                if ($this->onStepSuccess) {
                    ($this->onStepSuccess)($stepResult, $this, $result);
                }
                $currentName = $this->resolveNextOnSuccess($step, $stepNames);
                continue;
            }

            // ---- Paso falló después de agotar reintentos ----
            if ($this->onStepFailure) {
                ($this->onStepFailure)($stepResult, $this, $result);
            }

            $decision = $this->resolveOnFailDecision($step, $stepResult, $result);

            switch (true) {
                case $decision === 'cancel':
                    $result->markFailed($step->getName());
                    $result->markFinished();
                    return $result;

                case $decision === 'continue':
                    $currentName = $this->resolveNextOnSuccess($step, $stepNames);
                    break;

                case is_string($decision) && isset($this->steps[$decision]):
                    $currentName = $decision;
                    break;

                default:
                    throw new RuntimeException(
                        "Orchestrator: onFail de '{$step->getName()}' retornó un valor inválido: " . var_export($decision, true)
                    );
            }
        }

        $result->markFinished();
        return $result;
    }

    // =========================================================
    // INTERNO
    // =========================================================

    protected function executeStep(Step $step, Result $partialResult): StepResult
    {
        $scurl = $step->isFresh() ? new Scurl() : $this->scurl;

        $maxAttempts = 1 + $step->getMaxRetries();
        $attempt = 0;
        $response = null;
        $reason   = null;
        $exception = null;
        $passed   = false;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                // 1) Configurar Scurl con los callbacks encolados del Step
                $step->applyConfig($scurl, $partialResult);

                // 2) Hook pre-send
                if ($hook = $step->getBeforeSend()) {
                    $hook($scurl, $partialResult);
                }

                // 3) Disparar la petición
                $response = $scurl->send();

                // 4) Hook post-send
                if ($hook = $step->getAfterSend()) {
                    $hook($response, $scurl, $partialResult);
                }

                // 5) Validar expectations
                $reason = $step->validate($response);
                if ($reason === null) {
                    $passed = true;
                    break;
                }
            } catch (Throwable $e) {
                $exception = $e;
                $reason = 'Excepción: ' . $e->getMessage();
                // Si Scurl lanzó con config exceptions=true no tenemos response.
                // Dejamos $response como el último conocido (puede ser null).
            }

            if ($attempt < $maxAttempts && $step->getRetryDelayMs() > 0) {
                usleep($step->getRetryDelayMs() * 1000);
            }
        }

        return new StepResult(
            name:              $step->getName(),
            passed:            $passed,
            attempts:          $attempt,
            response:          $response,
            failureReason:     $passed ? null : $reason,
            exception:         $passed ? null : $exception,
            usedFreshInstance: $step->isFresh(),
        );
    }

    /**
     * Determina el siguiente step tras un éxito (o tras un onFail='continue').
     *
     * @param string[] $stepNames
     */
    protected function resolveNextOnSuccess(Step $step, array $stepNames): ?string
    {
        $explicit = $step->getNextStep();
        if ($explicit !== null) {
            if (!isset($this->steps[$explicit])) {
                throw new InvalidArgumentException(
                    "Orchestrator: step '{$step->getName()}' apunta a un next() inexistente: '{$explicit}'."
                );
            }
            return $explicit;
        }

        $idx = array_search($step->getName(), $stepNames, true);
        if ($idx === false) {
            return null;
        }
        // En progresión natural saltamos steps marcados como offFlow.
        for ($i = $idx + 1; $i < count($stepNames); $i++) {
            $candidate = $this->steps[$stepNames[$i]];
            if (!$candidate->isOffFlow()) {
                return $stepNames[$i];
            }
        }
        return null; // fin del flujo
    }

    /**
     * Primer step "en flujo" según el orden de declaración.
     *
     * @param string[] $stepNames
     */
    protected function findFirstInFlowStep(array $stepNames): ?string
    {
        foreach ($stepNames as $name) {
            if (!$this->steps[$name]->isOffFlow()) {
                return $name;
            }
        }
        return null;
    }

    /**
     * Resuelve la decisión onFail (string directo o callable).
     */
    protected function resolveOnFailDecision(Step $step, StepResult $stepResult, Result $partialResult): string
    {
        $action = $step->getOnFail();

        if ($action instanceof Closure) {
            $decision = $action($stepResult, $this, $partialResult);
            if (!is_string($decision)) {
                throw new RuntimeException(
                    "Orchestrator: onFail callable de '{$step->getName()}' debe retornar string ('cancel'|'continue'|'<stepName>')."
                );
            }
            return $decision;
        }

        return $action;
    }
}
