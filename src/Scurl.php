<?php
namespace SrvClick\Scurlv2;
/*
 * Scurl v2
 * Fecha de inicio de desarrollo: 2025-07-02
 */

use CURLFile;
use Exception;
use InvalidArgumentException;

/**
 * Scurl — fachada fluida sobre cURL para PHP 8.3+.
 *
 * Esta clase es la API pública de la librería. Expone un builder
 * encadenable que termina en {@see send()} devolviendo un {@see Response}.
 *
 * Flujo típico:
 *
 *     $response = (new Scurl)
 *         ->url('https://api.example.com/users')
 *         ->headers(['Authorization' => 'Bearer '.$token])
 *         ->get()
 *         ->send();
 *
 *     if ($response->isOk()) {
 *         $users = $response->array();
 *     }
 *
 * La lógica de bajo nivel vive en {@see Request}; esta clase solo la
 * orquesta y expone convenciones amigables para el usuario final.
 */
class Scurl
{
    protected Request $request;

    /**
     * Crea una nueva instancia lista para encadenar llamadas.
     *
     * Internamente construye un {@see Request} con los defaults de la
     * librería (SSL verify activado, timeout 30s, follow-location, etc).
     */
    public function __construct()
    {
        $this->request = new Request();
    }

    /**
     * Asigna la URL destino de la petición.
     *
     * Debe ser una URL absoluta (con scheme http/https). No se valida el
     * formato aquí; cURL la rechazará en {@see send()} si es inválida.
     *
     * @param string $url URL absoluta (p.ej. "https://api.example.com/users").
     */
    public function url(string $url) : Scurl
    {
        $this->request->setUrl($url);
        return $this;
    }

    /**
     * Alias de {@see url()}. Útil en contextos donde "target" lee mejor
     * (por ejemplo al describir redirecciones o uploads).
     */
    public function target( string $url): Scurl
    {
        return $this->url($url);
    }

    /**
     * Elimina una cookie del cookie-jar activo.
     *
     * Requiere haber activado cookies con {@see cookie()} o
     * {@see cookieFile()} previamente.
     *
     * @param string      $name   Nombre exacto de la cookie.
     * @param string|null $domain Si se pasa, solo borra las cookies de ese
     *                            dominio. Si es null, borra todas las que
     *                            coincidan por nombre (en cualquier dominio).
     */
    public function deleteCookie(string $name, ?string $domain = null) : Scurl
    {
        $this->request->deleteCookie($name, $domain);
        return $this;
    }

    /**
     * Elimina TODAS las entradas con ese nombre de cookie en el jar,
     * incluyendo líneas marcadas como `#HttpOnly_`.
     *
     * Útil cuando hay duplicados con distintos dominios o flags y se quiere
     * un borrado por nombre sin condiciones.
     */
    public function deleteCookieCompletely(string $name) : Scurl
    {
        $this->request->deleteCookieCompletely($name);
        return $this;
    }


    /**
     * Reemplaza una cookie (borra las que coincidan por nombre y añade una
     * nueva con los valores dados).
     *
     * @param string $name    Nombre de la cookie.
     * @param string $value   Valor a guardar (sin URL-encoding; se escribe crudo).
     * @param string $domain  Dominio al que aplica.
     * @param string $path    Path dentro del dominio. Default "/".
     * @param bool   $secure  Marca "Secure" (solo se envía por HTTPS).
     * @param int    $expires Timestamp Unix de expiración. 0 = cookie de sesión.
     */
    public function replaceCookie(string $name, string $value, string $domain,string $path = "/", bool $secure = false, int $expires = 0) : Scurl {
        $this->request->replaceCookie($name,$value, $domain, $path, $secure,  $expires);
        return $this;
    }

    /**
     * Añade una cookie nueva al jar (no valida si ya existe una con el mismo
     * nombre; usa {@see replaceCookie()} para garantizar unicidad).
     *
     * Mismos parámetros que {@see replaceCookie()}.
     */
    public function addCookie(string $name, string $value, string $domain, string $path = "/", bool $secure = false, int $expires = 0): Scurl
    {
        $this->request->addCookie($name, $value, $domain, $path, $secure, $expires);
        return $this;
    }

    /**
     * Configura un proxy HTTP/SOCKS para esta petición.
     *
     * Acepta dos formatos:
     *
     *   1. String con URL completa:
     *      $curl->proxy('http://user:pass@host:8080');
     *      $curl->proxy('socks5://host:1080');
     *
     *   2. Array posicional [host, port, user?, pass?]:
     *      $curl->proxy(['host', 8080]);
     *      $curl->proxy(['host', 8080, 'user', 'pass']);
     *
     * @param string|array $proxy Definición del proxy.
     *
     * @throws InvalidArgumentException Si el string no tiene host+port, si el
     *                                  array no trae host/port, o si el tipo
     *                                  del argumento es inválido.
     */
    public function proxy(string|array $proxy): static
    {
        if (is_string($proxy)) {
            $parsed = parse_url($proxy);

            if (!isset($parsed['host'], $parsed['port'])) {
                throw new InvalidArgumentException("Proxy string must contain host and port.");
            }

            $this->request->setOptions([
                CURLOPT_PROXY => $parsed['host'],
                CURLOPT_PROXYPORT => $parsed['port'],
            ]);

            if (isset($parsed['user'], $parsed['pass'])) {
                $this->request->setOptions([
                    CURLOPT_PROXYUSERPWD => "{$parsed['user']}:{$parsed['pass']}",
                ]);
            }

        } elseif (is_array($proxy)) {
            $host = $proxy[0] ?? null;
            $port = $proxy[1] ?? null;
            $user = $proxy[2] ?? null;
            $pass = $proxy[3] ?? null;

            if (!$host) {
                throw new InvalidArgumentException("Proxy host is required in array format.");
            }
            if (!$port) {
                throw new InvalidArgumentException("Proxy port is required in array format.");
            }

            $this->request->setOptions([
                CURLOPT_PROXY => $host,
                CURLOPT_PROXYPORT => $port,
            ]);

            if ($user !== null && $pass !== null) {
                $this->request->setOptions([
                    CURLOPT_PROXYUSERPWD => "{$user}:{$pass}",
                ]);
            }

        } else {
            throw new InvalidArgumentException("Invalid proxy format.");
        }

        return $this;
    }
    /**
     * Activa la gestión de cookies persistentes usando un archivo jar.
     *
     * Si se pasa `$file`, ese archivo se usa como cookie-jar (se crea si no
     * existe). Si se omite o es null, se genera un archivo temporal aleatorio
     * vía `tempnam(sys_get_temp_dir(), 'scurl_cookie_')`.
     *
     * El parámetro es nullable por compatibilidad: llamar `cookieFile()` sin
     * argumentos equivale a {@see cookie()}.
     *
     * @param string|null $file Ruta absoluta del cookie-jar, o null para temp.
     */
    public function cookieFile(?string $file = null): self
    {
        $this->request->enableCookies($file);
        return $this;
    }

    /**
     * Activa cookies persistentes usando un archivo temporal aleatorio.
     *
     * Atajo equivalente a `cookieFile(null)`. Útil cuando solo se necesita
     * preservar la sesión dentro del proceso actual, sin importar la ruta.
     */
    public function cookie() : Scurl
    {
        $this->request->enableCookies();
        return $this;
    }

    /**
     * Devuelve la URL actualmente configurada. Cadena vacía si aún no se
     * llamó {@see url()}.
     */
    public function getUrl() : string
    {
        return $this->request->getUrl();
    }

    /**
     * Devuelve el verbo HTTP actual en mayúsculas (default "GET").
     */
    public function getMethod() : string
    {
        return $this->request->getMethod();
    }

    /**
     * Mezcla configuración de comportamiento con los defaults.
     *
     * Claves soportadas actualmente:
     *   - 'auto_json'  (bool) Añadir Content-Type: application/json cuando
     *                  body() recibe un string JSON válido. Default: true.
     *   - 'exceptions' (bool) Lanzar \Exception si el status no está aceptado
     *                  (ver {@see acceptStatus()}). Default: false.
     *
     * @param array $config Map parcial (se mezcla con la config previa).
     */
    public function config(array $config) : Scurl
    {
        $this->request->setConfig($config);
        return $this;
    }

    /**
     * Asigna el verbo HTTP de la petición. Se normaliza a mayúsculas.
     *
     * Prefiere los atajos {@see get()}, {@see post()}, etc. cuando sea
     * posible; este método queda para verbos no estándar.
     */
    public function method(string $method): Scurl
    {
        $this->request->setMethod($method);
        return $this;
    }

    /** Atajo para `method('GET')`. */
    public function get(): Scurl { return $this->method("GET"); }

    /** Atajo para `method('POST')`. */
    public function post(): Scurl { return $this->method("POST"); }

    /** Atajo para `method('PUT')`. */
    public function put(): Scurl { return $this->method("PUT"); }

    /** Atajo para `method('DELETE')`. */
    public function delete(): Scurl { return $this->method("DELETE"); }

    /** Atajo para `method('PATCH')`. */
    public function patch(): Scurl { return $this->method('PATCH'); }

    /** Atajo para `method('HEAD')`. */
    public function head(): Scurl { return $this->method('HEAD'); }

    /**
     * Atajo para `method('OPTIONS')`.
     *
     * Se llama `options_method()` para no colisionar con {@see options()},
     * que setea opciones cURL. Esta colisión de nombres está documentada en
     * BUG-011 y se considera breaking change a posponer a v3.
     */
    public function options_method(): Scurl { return $this->method('OPTIONS'); }

    /**
     * Setea opciones cURL arbitrarias (CURLOPT_*).
     *
     * Se mezclan con las opciones previas: las claves nuevas sobrescriben las
     * existentes, las omitidas se preservan.
     *
     *     $curl->options([
     *         CURLOPT_CONNECTTIMEOUT => 5,
     *         CURLOPT_REFERER        => 'https://example.com',
     *     ]);
     *
     * @param array $options Mapa con constantes CURLOPT_* como claves.
     */
    public function options(array $options): Scurl
    {
        $this->request->setOptions($options);
        return $this;
    }

    /**
     * Devuelve todas las opciones cURL configuradas hasta ahora, con las
     * constantes CURLOPT_* resueltas a sus nombres legibles (útil para debug).
     *
     * @return array Mapa `nombre_constante => valor`.
     */
    public function getOptions() : array
    {
        return $this->request->getOptions();
    }

    /**
     * Establece el timeout total de la petición en segundos.
     *
     * Mapea a `CURLOPT_TIMEOUT`. 0 desactivaría el timeout (no recomendado).
     */
    public function timeout(int $seconds): Scurl
    {
        $this->request->setTimeout($seconds);
        return $this;
    }

    /**
     * Deshabilita la verificación del certificado SSL del servidor.
     *
     * ⚠️ Inseguro: expone la conexión a MITM. Úsalo solo en entornos controlados
     * (desarrollo local, sitios con certificados autofirmados conocidos).
     *
     * El flag persiste en esta instancia de Scurl: reset() no lo revierte, por
     * lo que afecta a todas las requests posteriores hasta que lo desactives
     * explícitamente con ->insecure(false).
     *
     * Ejemplo:
     *     $curl->url('https://self-signed.dev/api')->insecure()->get()->send();
     *
     * @param bool $enable true para deshabilitar la verificación (default), false para re-activarla.
     */
    public function insecure(bool $enable = true): Scurl
    {
        $this->request->setInsecure($enable);
        return $this;
    }
    /**
     * Fuerza el header `Content-Type: application/json` en la petición.
     *
     * Útil cuando `body()` recibe un array y se quiere enviar como JSON
     * explícitamente (por defecto los arrays se envían como multipart /
     * URL-encoded). También se añade automáticamente cuando `body()` recibe
     * un string JSON válido y `config['auto_json']` está activo.
     */
    public function json() : Scurl
    {
        $this->request->setJsonHeader();
        return $this;
    }

    /**
     * Sobrescribe el `User-Agent` de la petición.
     *
     * Se aplica en el momento del {@see send()}, pisando cualquier
     * User-Agent previamente seteado vía {@see headers()} o defaults.
     */
    public function useragent(string $useragent): Scurl
    {
        $this->request->setUserAgent($useragent);
        return $this;
    }

    /**
     * Devuelve el `User-Agent` vigente (forzado primero, default después).
     * Cadena vacía si nunca se estableció.
     */
    public function getUserAgent() : string
    {
        return $this->request->getUserAgent();
    }

    /**
     * Asigna el body / parámetros de la petición.
     *
     * Se comporta según el tipo:
     *   - array       → se envía como form-url-encoded o multipart.
     *   - CURLFile    → se sube como archivo crudo (PUT/POST).
     *   - string JSON → se envía tal cual y, si `config['auto_json']` está
     *                   activo, se añade `Content-Type: application/json`.
     *   - string query-string (p.ej. "a=1&b=2") → se convierte a array
     *     (heurística conservadora: requiere `=`, sin whitespace).
     *   - cualquier otro string → se preserva como body crudo (texto plano,
     *     XML, binario, etc.).
     *
     * Alias semántico: {@see body()}.
     */
    public function parameters(array|string|CURLFile $parameters): Scurl
    {
        $this->request->setParameters($parameters);
        return $this;
    }

    /**
     * Mezcla headers HTTP con los actuales.
     *
     * Acepta dos formatos intercambiables:
     *
     *   $curl->headers(['Authorization: Bearer xyz', 'X-Trace: abc']);
     *   $curl->headers(['Authorization' => 'Bearer xyz', 'X-Trace' => 'abc']);
     *
     * Los nombres se normalizan case-insensitive, así que volver a setear
     * "authorization" pisa el "Authorization" previo.
     */
    public function headers(array $headers) : Scurl
    {
        $this->request->setHeaders($headers);
        return $this;
    }

    /**
     * Indica si la última petición terminó en un status 2xx.
     *
     * Solo válido después de `send()`. Antes de enviar retorna false.
     */
    public function isOK(): bool
    {
        return $this->request->isOk();
    }

    /**
     * Acepta un status HTTP como "no-error" cuando `config['exceptions']`
     * está activo (por defecto solo 2xx no lanza).
     *
     * Admite tanto grupos clásicos como códigos específicos (todo código se
     * normaliza a su centena):
     *
     *     $curl->acceptStatus(400);   // 4xx completo
     *     $curl->acceptStatus(404);   // engloba 4xx (mismo efecto que 400)
     *     $curl->acceptStatus(503);   // engloba 5xx
     *
     * @throws InvalidArgumentException Si $status está fuera del rango HTTP
     *                                  válido (100-599).
     */
    public function acceptStatus(int $group): Scurl
    {
        $this->request->acceptStatus($group);
        return $this;
    }

    /**
     * Alias semántico de {@see parameters()}. Útil cuando lo que se manda
     * conceptualmente es un "body" y no "form parameters".
     */
    public function body(array|string|CURLFile $parameters): Scurl
    {
        $this->parameters($parameters);
        return $this;
    }

    /**
     * Declara un archivo local para subir como body crudo (upload directo).
     *
     * El archivo se envía como stream (`CURLOPT_UPLOAD` + `CURLOPT_INFILE`)
     * en el verbo HTTP actual. Si el MIME está declarado en el CURLFile se
     * usa como Content-Type.
     *
     * @throws InvalidArgumentException Si la ruta no existe o no es un archivo.
     */
    public function upload(string $path): Scurl
    {
        $this->request->upload($path);
        return $this;
    }

    /**
     * Devuelve el CURLFile configurado vía {@see upload()}, o null si no hay.
     */
    public function getUploadFile(): ?CURLFile
    {
        return $this->request->getUploadFile();
    }

    /**
     * Habilita captura de headers de respuesta para esta petición.
     *
     * Tras `send()`, los headers quedan accesibles vía `$response->headers()`,
     * `$response->getHeader('X-...')`, etc. El flag es per-request: `reset()`
     * lo limpia automáticamente después de cada `send()`.
     */
    public function getHeaders(): Scurl
    {
        $this->request->setOptions([CURLOPT_HEADER => true]);
        return $this;
    }


    /**
     * Ejecuta la petición HTTP y retorna el Response.
     *
     * Las excepciones de Request::send() se propagan tal cual, preservando:
     *   - La clase original (InvalidArgumentException, \Exception, etc.)
     *   - El stack trace original (no se sobreescribe con el de este frame)
     *   - La cadena de $previous si la hubiera
     *
     * Casos típicos que pueden lanzar:
     *   - InvalidArgumentException: archivo de upload inexistente, proxy
     *     malformado, status group inválido en acceptStatus().
     *   - \Exception: HTTP error cuando config(['exceptions' => true]) y el
     *     status no está aceptado (ver acceptStatus()).
     *
     * @throws InvalidArgumentException
     * @throws Exception
     */
    public function send(): Response
    {
        return $this->request->send();
    }
}
