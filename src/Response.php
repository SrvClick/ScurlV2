<?php
namespace SrvClick\Scurlv2;

/**
 * Response — representa la respuesta devuelta por {@see Scurl::send()}.
 *
 * Encapsula el body crudo, el status HTTP, los headers de respuesta (si se
 * capturaron con `getHeaders()`) y el cookie-jar usado. Ofrece helpers para
 * detectar JSON ({@see isJson()}), decodificarlo ({@see array()}) y navegar
 * por el JSON con dot-notation ({@see get()}, {@see has()}, {@see expectJson()},
 * {@see __invoke()}).
 *
 * Esta clase no se construye directamente por el usuario — la emite Request
 * tras ejecutar la petición.
 */
class Response
{
    protected string $body = '';
    protected int $statusCode = 0;
    protected bool $isJson = false;

    protected array $config = [
        'auto_json' => true,
        'exceptions' => false,
    ];

    protected ?string $cookieFileName = null;

    protected array $responseHeaders = [];

    /**
     * Inyecta configuración del Request para que Response pueda decidir
     * comportamientos. Uso interno: lo llama `Request::send()`.
     *
     * @param array $config Mapa parcial; se mezcla con los defaults.
     */
    public function setConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);
    }

    /**
     * Guarda la ruta del cookie-jar que se usó en esta petición.
     * Uso interno: lo llama `Request::send()` para trazabilidad.
     */
    public function setCookieFileName(?string $cookieFileName): void
    {
        $this->cookieFileName = $cookieFileName;
    }


    /**
     * Devuelve la ruta del cookie-jar usado en la petición (cadena vacía si
     * no se activaron cookies).
     */
    public function getCookieFileName() : string
    {
        return $this->cookieFileName ?? '';
    }

    /**
     * Asigna el mapa de headers de respuesta (indexado en minúsculas).
     *
     * Uso interno: lo llama `Request::send()` cuando `CURLOPT_HEADER` estuvo
     * activo (es decir, cuando se pidió captura con `$curl->getHeaders()`).
     */
    public function setResponseHeaders(array $headers): void
    {
        $this->responseHeaders = $headers;
    }

    /**
     * Devuelve todos los headers de respuesta capturados.
     *
     * Claves en minúsculas. `set-cookie` es un array (múltiples valores
     * posibles); el resto son strings.
     */
    public function getResponseHeaders() : array
    {
        return $this->responseHeaders;
    }

    /**
     * Obtiene un header de respuesta por nombre (case-insensitive).
     *
     * @param string $name    Nombre del header (p.ej. "Content-Type").
     * @param mixed  $default Valor a retornar si el header no está presente.
     *
     * @return mixed Valor del header o $default.
     */
    public function getHeader(string $name, $default = null): mixed
    {
        $name = strtolower($name);
        if (empty($this->responseHeaders)) {
            return $default;
        }
        return $this->responseHeaders[$name] ?? $default;
    }

    /**
     * Extrae el valor de una cookie específica desde los `Set-Cookie` de la
     * respuesta (no desde el jar en disco).
     *
     * Requiere haber activado captura de headers con `$curl->getHeaders()`.
     * No decodifica URL-encoding.
     *
     * @param string $cookieName Nombre exacto de la cookie.
     * @param mixed  $default    Retorno si no se encuentra.
     */
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

    /**
     * Asigna el body crudo recibido y detecta si es JSON válido.
     *
     * Uso interno: lo llama `Request::send()` tras ejecutar cURL.
     * `null` se normaliza a cadena vacía. La detección de JSON usa
     * `json_validate()` sobre el body completo (no heurística de prefijo).
     */
    public function setBody(?string $body): void
    {
        $this->body = $body ?? '';
        $this->isJson = $this->body !== '' && json_validate($this->body);
    }

    /**
     * Asigna el código HTTP de la respuesta. Uso interno.
     */
    public function setStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    /**
     * Devuelve el body crudo de la respuesta como string.
     *
     * Siempre retorna string (nunca null), incluso en respuestas sin body
     * (HEAD, 204, etc.) — en esos casos es cadena vacía.
     */
    public function body(): string
    {
        return $this->body;
    }

    /**
     * Código HTTP de la respuesta (p.ej. 200, 404, 500). 0 si la petición
     * no llegó a ejecutarse o cURL falló en el transporte.
     */
    public function statuscode(): int
    {
        return $this->statusCode;
    }

    /**
     * Alias de {@see getResponseHeaders()} con nombre más corto.
     */
    public function headers(): array
    {
        return $this->responseHeaders;
    }

    /**
     * Indica si el body de la respuesta es JSON válido (verificado con
     * `json_validate()` en el momento de `setBody()`).
     */
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

    /**
     * Indica si la respuesta terminó en un status 2xx (200-299).
     *
     * Es el indicador más común de "la petición funcionó". Para casos más
     * finos ver {@see statuscode()}.
     */
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
