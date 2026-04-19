<?php
namespace SrvClick\Scurlv2;

class Response
{
    protected string $body;
    protected int $statusCode = 0;
    protected bool $isJson = false;

    protected array $config = [
        'auto_json' => true,
        'exceptions' => false,
    ];

    protected ?string $cookieFileName = null;

    protected array $responseHeaders = [];

    public function setConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);
    }
    public function setCookieFileName(?string $cookieFileName): void
    {
        $this->cookieFileName = $cookieFileName;
    }


    public function getCookieFileName() : string
    {
        return $this->cookieFileName ?? '';
    }

    public function setResponseHeaders(array $headers): void
    {
        $this->responseHeaders = $headers;
    }
    public function getResponseHeaders() : array
    {
        return $this->responseHeaders;
    }

    public function getHeader(string $name, $default = null): mixed
    {
        $name = strtolower($name);
        if (empty($this->responseHeaders)) {
            return $default;
        }
        return $this->responseHeaders[$name] ?? $default;
    }

    public function getCookie(string $cookieName, $default = null): mixed
    {
        if (
            !isset($this->responseHeaders['set-cookie']) ||
            !is_array($this->responseHeaders['set-cookie'])
        ) {
            return $default;
        }
        foreach ($this->responseHeaders['set-cookie'] as $cookieLine) {
            $parts = explode(';', $cookieLine);
            if (count($parts) > 0) {
                $nv = explode('=', trim($parts[0]), 2);
                if (count($nv) === 2) {
                    list($name, $value) = $nv;

                    if (trim($name) === $cookieName) {
                        return trim($value);
                    }
                }
            }
        }
        return $default;
    }

    public function setBody(?string $body): void
    {
        $this->body = $body ?? '';
        $this->isJson = $this->body !== '' && json_validate($this->body);
    }
    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    public function body(): string
    {
        return $this->body;
    }

    public function statuscode(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        return $this->responseHeaders;
    }

    public function isJson(): bool
    {
        return $this->isJson;
    }

    /**
     * @deprecated Usa array() en su lugar. Este método será removido en una versión futura.
     *             Su nombre es engañoso ya que retorna un array PHP, no una cadena JSON.
     */
    public function json(): ?array
    {
        return $this->array();
    }

    /**
     * Decodifica el body de la respuesta como un array PHP asociativo.
     * Reemplazo recomendado de json(), que tenía un nombre engañoso.
     */
    public function array(): ?array
    {
        return $this->isJson ? json_decode($this->body, true) : null;
    }

    public function isOk(): bool
    {
        return $this->statuscode() >= 200 && $this->statuscode() < 300;
    }

    // =========================================================
    // ACCESO A JSON CON DOT NOTATION
    // =========================================================

    /**
     * Obtiene un valor del JSON del body usando dot-notation.
     *
     * Equivalente a:
     *   $arr = $response->array();
     *   $arr['data']['user']['id'] ?? $default;
     *
     * Soporta índices numéricos: 'data.roles.0' accede a $arr['data']['roles'][0].
     * Si el body no es JSON válido o la ruta no existe, retorna $default.
     *
     *   $response->get('data.user.id');              // 42
     *   $response->get('no.existe', 'fallback');     // 'fallback'
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $arr = $this->array();
        if ($arr === null) {
            return $default;
        }

        $cur = $arr;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return $default;
            }
            $cur = $cur[$key];
        }
        return $cur;
    }

    /**
     * Indica si una ruta dot-notation existe en el JSON del body.
     *
     * A diferencia de get(), distingue "clave ausente" de "clave presente con valor null":
     *
     *   // body: {"user": {"name": null}}
     *   $response->has('user.name');     // true  (la clave existe)
     *   $response->get('user.name');     // null  (valor es null)
     *   $response->has('user.age');      // false (la clave no existe)
     */
    public function has(string $path): bool
    {
        $arr = $this->array();
        if ($arr === null) {
            return false;
        }

        $cur = $arr;
        foreach (explode('.', $path) as $key) {
            if (!is_array($cur) || !array_key_exists($key, $cur)) {
                return false;
            }
            $cur = $cur[$key];
        }
        return true;
    }

    /**
     * Verifica si el valor en la ruta dot-notation es ESTRICTAMENTE igual al esperado.
     *
     * Misma semántica que el expectJson() del Orchestrator:
     * - Comparación con === (no hace casts, 1 !== "1").
     * - Clave ausente se trata como null (expectJson('no.existe', null) → true).
     *
     *   $response->expectJson('success', true);
     *   $response->expectJson('data.user.id', 42);
     */
    public function expectJson(string $path, mixed $expected): bool
    {
        return $this->get($path) === $expected;
    }

    /**
     * Atajo invocable: permite usar $response() como función.
     *
     * - Con un argumento → alias de get().
     * - Con dos argumentos → alias de expectJson() (comparación estricta).
     *
     *   $id    = $response('data.user.id');           // get()
     *   $match = $response('data.user.id', 42);       // expectJson()
     *
     * Útil dentro de condicionales cortos:
     *
     *   if ($response->isOk() && $response('success', true)) { ... }
     *
     * Nota: se usa func_num_args() para distinguir entre "no pasó el segundo
     * argumento" y "lo pasó con valor null" (ambos darían igual con un default
     * normal). Esto permite validar explícitamente contra null:
     *
     *   $response('data.error', null);   // ¿data.error es exactamente null?
     */
    public function __invoke(string $path, mixed $expected = null): mixed
    {
        if (func_num_args() === 1) {
            return $this->get($path);
        }
        return $this->expectJson($path, $expected);
    }
}
