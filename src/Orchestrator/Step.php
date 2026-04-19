<?php

namespace SrvClick\Scurlv2\Orchestrator;

use Closure;
use SrvClick\Scurlv2\Orchestrator;
use SrvClick\Scurlv2\Response;
use SrvClick\Scurlv2\Scurl;

/**
 * Representa un paso dentro de un flujo del Orchestrator.
 *
 * El Step se construye de forma fluida y replica la estructura de Scurl:
 * cualquier método no declarado explícitamente aquí se reenvía a Scurl
 * vía __call y se difiere su aplicación hasta el momento de ejecución.
 *
 * Métodos de Scurl soportados vía __call (listado no exhaustivo, para IDE):
 *
 * @method self url(string $url)
 * @method self target(string $url)
 * @method self get()
 * @method self post()
 * @method self put()
 * @method self delete()
 * @method self patch()
 * @method self head()
 * @method self options_method()
 * @method self method(string $method)
 * @method self body(mixed $parameters)
 * @method self parameters(mixed $parameters)
 * @method self json()
 * @method self headers(array $headers)
 * @method self timeout(int $seconds)
 * @method self useragent(string $ua)
 * @method self upload(string $path)
 * @method self getHeaders()
 * @method self options(array $options)
 * @method self acceptStatus(int $group)
 * @method self proxy(string|array $proxy)
 * @method self cookie()
 * @method self cookieFile(?string $file = null)
 * @method self addCookie(string $name, string $value, string $domain, string $path = '/', bool $secure = false, int $expires = 0)
 * @method self replaceCookie(string $name, string $value, string $domain, string $path = '/', bool $secure = false, int $expires = 0)
 * @method self deleteCookie(string $name, ?string $domain = null)
 * @method self deleteCookieCompletely(string $name)
 * @method self config(array $config)
 */
class Step
{
    // ---- Identidad ----
    protected string       $name;
    protected Orchestrator $orchestrator;

    // ---- Configuración diferida a Scurl ----
    /** @var array<int, Closure> lista de callables (Scurl, Result) => void */
    protected array $configCallbacks = [];
    protected bool  $useFreshInstance = false;
    protected bool  $offFlow          = false;

    // ---- Expectations ----
    /** @var int[]|null null = cualquier 2xx cuenta como ok (delegado a Response::isOk) */
    protected ?array $expectedStatuses = null;
    /** @var string[] */
    protected array $expectedBodyContains = [];
    /** @var array<int, array{string, mixed}> pares [path_dot_notation, valor_esperado] */
    protected array   $expectedJsonPaths = [];
    protected ?Closure $customValidator = null;

    // ---- Control de flujo ----
    protected int $maxRetries   = 0;
    protected int $retryDelayMs = 0;

    /** @var string|Closure 'cancel' | 'continue' | '<step>' | callable */
    protected string|Closure $onFail = 'cancel';

    protected ?string $nextStep = null;

    // ---- Hooks ----
    protected ?Closure $beforeSend = null;
    protected ?Closure $afterSend  = null;

    public function __construct(string $name, Orchestrator $orchestrator)
    {
        $this->name = $name;
        $this->orchestrator = $orchestrator;
    }

    // =========================================================
    // PROXY DINÁMICO A SCURL
    // =========================================================

    /**
     * Cualquier método de Scurl se puede llamar sobre el Step.
     * Se encola para ejecutarse justo antes de send() en tiempo de ejecución.
     */
    public function __call(string $method, array $args): self
    {
        $this->configCallbacks[] = function (Scurl $scurl) use ($method, $args) {
            if (!method_exists($scurl, $method)) {
                throw new \BadMethodCallException(
                    "Scurl no tiene el método '{$method}'. Llamado desde Step '{$this->name}'."
                );
            }
            $scurl->{$method}(...$args);
        };
        return $this;
    }

    /**
     * Configurador avanzado con acceso al Scurl y al Result acumulado.
     * Útil cuando un paso depende de datos de pasos previos.
     *
     * Ejemplo:
     *   ->request(fn($scurl, $result) => $scurl->headers([
     *        'Authorization' => 'Bearer ' . $result->response('login')->getCookie('token'),
     *   ]))
     */
    public function request(callable $configurator): self
    {
        $this->configCallbacks[] = Closure::fromCallable($configurator);
        return $this;
    }

    // =========================================================
    // AISLAMIENTO DE INSTANCIA
    // =========================================================

    /**
     * Usar una instancia nueva de Scurl solo para este paso.
     * El Scurl principal (sesión, cookies, headers globales) NO se toca
     * y se restaura en el siguiente paso.
     */
    public function fresh(bool $enable = true): self
    {
        $this->useFreshInstance = $enable;
        return $this;
    }

    public function isFresh(): bool
    {
        return $this->useFreshInstance;
    }

    /**
     * Marca el step como "fuera del flujo natural": NO se alcanzará por orden
     * de declaración. Solo se ejecuta si otro step lo invoca vía onFail()
     * o via next() explícito.
     *
     * Útil para pasos de rescate/recuperación que no deben correr en el
     * camino feliz.
     *
     * Ejemplo:
     *   $orch->step('login')->...->onFail('reLogin');
     *   $orch->step('fetch')->...;
     *   $orch->step('reLogin')->offFlow()->...->next('fetch');  // solo cuando login falla
     */
    public function offFlow(bool $enable = true): self
    {
        $this->offFlow = $enable;
        return $this;
    }

    public function isOffFlow(): bool
    {
        return $this->offFlow;
    }

    // =========================================================
    // EXPECTATIONS
    // =========================================================

    /**
     * Estado(s) HTTP aceptados. Si no se declara, se considera ok cualquier 2xx.
     *
     * @param int|int[] $status
     */
    public function expectStatus(int|array $status): self
    {
        $this->expectedStatuses ??= [];
        foreach ((array) $status as $s) {
            if (!in_array($s, $this->expectedStatuses, true)) {
                $this->expectedStatuses[] = $s;
            }
        }
        return $this;
    }

    /**
     * El body debe contener este substring (o todos los declarados).
     * Case-sensitive.
     */
    public function expectBodyContains(string|array $needle): self
    {
        foreach ((array) $needle as $n) {
            $this->expectedBodyContains[] = $n;
        }
        return $this;
    }

    /**
     * Valor esperado en una ruta JSON (dot notation).
     *
     *   ->expectJson('success', true)
     *   ->expectJson('data.user.id', 42)
     */
    public function expectJson(string $path, mixed $expected): self
    {
        $this->expectedJsonPaths[] = [$path, $expected];
        return $this;
    }

    /**
     * Validador libre: recibe Response, retorna:
     *   - true / null           → pasa
     *   - false                 → falla con razón genérica
     *   - string                → falla con esa razón
     */
    public function expect(callable $validator): self
    {
        $this->customValidator = Closure::fromCallable($validator);
        return $this;
    }

    // =========================================================
    // CONTROL DE FLUJO
    // =========================================================

    public function retries(int $n, int $delayMs = 0): self
    {
        $this->maxRetries   = max(0, $n);
        $this->retryDelayMs = max(0, $delayMs);
        return $this;
    }

    /**
     * Comportamiento cuando el paso falla después de agotar reintentos.
     *
     *   ->onFail('cancel')     // detiene el flujo (default)
     *   ->onFail('continue')   // sigue con el siguiente paso
     *   ->onFail('reLogin')    // salta a un step específico
     *   ->onFail(fn($stepResult, $orch, $result) => 'cancel'|'continue'|'<stepName>')
     */
    public function onFail(string|callable $action): self
    {
        $this->onFail = is_string($action) ? $action : Closure::fromCallable($action);
        return $this;
    }

    /**
     * Define explícitamente el siguiente paso (si se omite, se usa el orden de declaración).
     */
    public function next(string $stepName): self
    {
        $this->nextStep = $stepName;
        return $this;
    }

    // =========================================================
    // HOOKS
    // =========================================================

    /**
     * Se ejecuta justo antes de send().
     * Firma: fn(Scurl $scurl, Result $partialResult): void
     */
    public function beforeSend(callable $fn): self
    {
        $this->beforeSend = Closure::fromCallable($fn);
        return $this;
    }

    /**
     * Se ejecuta justo después de send(), antes de evaluar expectations.
     * Firma: fn(Response $response, Scurl $scurl, Result $partialResult): void
     */
    public function afterSend(callable $fn): self
    {
        $this->afterSend = Closure::fromCallable($fn);
        return $this;
    }

    // =========================================================
    // FLUENT BRIDGES HACIA EL ORCHESTRATOR
    // =========================================================

    public function step(string $name): self
    {
        return $this->orchestrator->step($name);
    }

    public function run(?string $startAt = null): Result
    {
        return $this->orchestrator->run($startAt);
    }

    // =========================================================
    // API INTERNA (consumida por Orchestrator)
    // =========================================================

    public function getName(): string { return $this->name; }
    public function getMaxRetries(): int { return $this->maxRetries; }
    public function getRetryDelayMs(): int { return $this->retryDelayMs; }
    public function getOnFail(): string|Closure { return $this->onFail; }
    public function getNextStep(): ?string { return $this->nextStep; }
    public function getBeforeSend(): ?Closure { return $this->beforeSend; }
    public function getAfterSend(): ?Closure { return $this->afterSend; }

    public function applyConfig(Scurl $scurl, Result $result): void
    {
        foreach ($this->configCallbacks as $cb) {
            $cb($scurl, $result);
        }
    }

    /**
     * Evalúa las expectations contra la respuesta.
     * Retorna null si todo pasa, o un string describiendo la primera falla.
     */
    public function validate(Response $response): ?string
    {
        // 1) Status HTTP
        if ($this->expectedStatuses !== null) {
            if (!in_array($response->statuscode(), $this->expectedStatuses, true)) {
                return "Status {$response->statuscode()} no está en [" . implode(',', $this->expectedStatuses) . "]";
            }
        } else {
            // Sin expectativa explícita → exigimos 2xx por defecto
            if (!$response->isOk()) {
                return "Status {$response->statuscode()} no es 2xx (sin expectStatus explícito)";
            }
        }

        // 2) Substrings en el body
        foreach ($this->expectedBodyContains as $needle) {
            if (!str_contains($response->body(), $needle)) {
                return "Body no contiene '{$needle}'";
            }
        }

        // 3) Campos JSON
        if (!empty($this->expectedJsonPaths)) {
            $arr = $response->array();
            if ($arr === null) {
                return "Se esperaba JSON en la respuesta pero el body no es JSON válido";
            }
            foreach ($this->expectedJsonPaths as [$path, $expected]) {
                $actual = $this->getByDotPath($arr, $path);
                if ($actual !== $expected) {
                    $a = $this->dump($actual);
                    $e = $this->dump($expected);
                    return "JSON path '{$path}' = {$a}, se esperaba {$e}";
                }
            }
        }

        // 4) Validador libre
        if ($this->customValidator !== null) {
            $r = ($this->customValidator)($response);
            if ($r === true || $r === null) {
                return null;
            }
            if ($r === false) {
                return "Validación personalizada falló";
            }
            if (is_string($r)) {
                return $r;
            }
            return "Validación personalizada retornó un valor inesperado";
        }

        return null;
    }

    protected function getByDotPath(array $arr, string $path): mixed
    {
        $keys = explode('.', $path);
        $cur = $arr;
        foreach ($keys as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return null;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }

    protected function dump(mixed $v): string
    {
        if (is_scalar($v) || $v === null) {
            return var_export($v, true);
        }
        if (is_array($v)) {
            return 'array(' . count($v) . ')';
        }
        return get_debug_type($v);
    }
}
