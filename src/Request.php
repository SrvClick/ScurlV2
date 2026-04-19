<?php

namespace SrvClick\Scurlv2;

use CURLFile;
use Exception;
use InvalidArgumentException;

class Request
{
    protected string $url;
    protected string $method = 'GET';
    private ?string $cookieFile = null;
    protected ?CURLFile $uploadFile = null;

    protected ?array $responseHeaders = [];
    protected ?string $forcedUserAgent = null;

    protected Response $response;
    protected array|string|CURLFile $parameters = [];
    protected array $config = [
        'auto_json' => true,
        'exceptions' => false,
    ];
    protected array $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 30,
        // Verificación SSL activada por defecto. Para deshabilitarla usa ->insecure()
        // (persiste a lo largo de toda la vida de la instancia, reset() no la toca).
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => [
            'user-agent: SrvClick Scurl/2.0',
        ],
    ];

    protected array $acceptedStatusGroups = [200];
    protected ?bool $lastCookieResult = null;

    public function setUrl(string $url): void
    {
        $this->url = $url;
    }
    public function getUrl() : string
    {
        return $this->url;
    }


    public function replaceCookie( string $name, string $value, string $domain, string $path = "/", bool $secure = false, int $expires = 0): self {

        $this->deleteCookieCompletely($name);
        if (!$this->cookieFile || !file_exists($this->cookieFile)) {
            $this->lastCookieResult = false;
            return $this;
        }
        $line = implode("\t", [
            $domain,
            'TRUE',
            $path,
            $secure ? 'TRUE' : 'FALSE',
            $expires,
            $name,
            $value
        ]);

        $this->lastCookieResult = file_put_contents($this->cookieFile, PHP_EOL . $line, FILE_APPEND) !== false;
        return $this;
    }

    public function deleteCookie(string $name, ?string $domain = null): self
    {
        if (!$this->cookieFile || !file_exists($this->cookieFile)) {
            $this->lastCookieResult = false;
            return $this;
        }
        $lines = file($this->cookieFile, FILE_IGNORE_NEW_LINES);
        $newLines = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, '#') || trim($line) === '') {
                $newLines[] = $line;
                continue;
            }

            // Formato Netscape cookie-jar: columnas separadas por TAB literal.
            // Usamos explode("\t") en vez de preg_split('/\s+/') para que sea
            // simétrico con la escritura (implode("\t", ...) en addCookie /
            // replaceCookie) y para no destrozar valores de cookie que
            // contengan espacios (ej. tokens base64 con padding o paths raros).
            $columns = explode("\t", $line);
            if (count($columns) < 7) {
                $newLines[] = $line;
                continue;
            }

            [$dom, $sub, $path, $secure, $expires, $cookieName, $cookieValue] = $columns;

            if ($cookieName === $name && ($domain === null || $domain === $dom)) {
                continue;
            }

            $newLines[] = $line;
        }

        $this->lastCookieResult = file_put_contents($this->cookieFile, implode(PHP_EOL, $newLines)) !== false;

        return $this;
    }

    public function deleteCookieCompletely(string $name): self
    {
        if (!$this->cookieFile || !file_exists($this->cookieFile)) {
            $this->lastCookieResult = false;
            return $this;
        }

        $lines = file($this->cookieFile, FILE_IGNORE_NEW_LINES);
        $newLines = [];

        foreach ($lines as $line) {
            // Quitamos el prefijo '#HttpOnly_' (si existe) y whitespace
            // externo (útil con archivos CRLF) antes de partir por TAB.
            // Simétrico con escritura: columnas separadas por TAB literal.
            $cols = explode("\t", trim(ltrim($line, '#')));
            if (count($cols) < 7) {
                $newLines[] = $line;
                continue;
            }
            $cookieName = $cols[5];
            if ($cookieName === $name) {
                continue;
            }
            $newLines[] = $line;
        }
        $this->lastCookieResult = file_put_contents($this->cookieFile, implode(PHP_EOL, $newLines)) !== false;
        return $this;
    }


    public function addCookie(string $name, string $value, string $domain, string $path = "/", bool $secure = false, int $expires = 0): self
    {
        if (!$this->cookieFile || !file_exists($this->cookieFile)) {
            $this->lastCookieResult = false;
            return $this;
        }
        $line = implode("\t", [$domain, 'TRUE', $path, $secure ? 'TRUE' : 'FALSE', $expires, $name, $value]);
        $this->lastCookieResult = file_put_contents($this->cookieFile, PHP_EOL . $line,FILE_APPEND) !== false;
        return $this;
    }
    public function enableCookies(?string $file = null): self
    {
        $this->cookieFile = $file ?? tempnam(sys_get_temp_dir(), 'scurl_cookie_');
        return $this;
    }

    public function reset(): void
    {
        $this->parameters = [];
        $this->uploadFile = null;

        unset(
            $this->options[CURLOPT_POSTFIELDS],
            $this->options[CURLOPT_CUSTOMREQUEST],
            $this->options[CURLOPT_POST],
            $this->options[CURLOPT_UPLOAD],      // Limpiar configuraciones de subida directa
            $this->options[CURLOPT_INFILE],
            $this->options[CURLOPT_INFILESIZE],
            $this->options[CURLOPT_HEADER],          // Captura de headers es per-request
            $this->options[CURLOPT_HEADERFUNCTION]   // también limpiamos el closure capturador
        );

        if (isset($this->options[CURLOPT_HTTPHEADER])) {
            $this->options[CURLOPT_HTTPHEADER] = array_filter(
                $this->options[CURLOPT_HTTPHEADER],
                fn($header) => stripos($header, 'Content-Type: application/json') === false
            );
        }
    }

    public function setConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);
    }
    public function setMethod(string $method): void
    {
        $this->method = strtoupper($method);
    }
    public function getMethod() : string
    {
        return $this->method;
    }
    public function setOptions(array $options): void
    {
        $this->options = array_replace($this->options, $options);
    }
    public function getOptions(): array
    {
        $readable = [];
        $allCurlConstants = get_defined_constants(true)['curl'];
        $curloptConstants = array_filter($allCurlConstants, fn($k) => str_starts_with($k, 'CURLOPT_'), ARRAY_FILTER_USE_KEY);
        $flip = array_flip($curloptConstants);
        foreach ($this->options as $key => $value) {
            $name = $flip[$key] ?? $key;
            $readable[$name] = $value;
        }
        return $readable;
    }
    public function setTimeout(int $seconds) : void
    {
        $this->options[CURLOPT_TIMEOUT] = $seconds;
    }

    /**
     * Deshabilita la verificación del certificado SSL del servidor.
     *
     * ⚠️ Uso bajo tu propia responsabilidad: deja la conexión vulnerable a MITM.
     * Solo tiene sentido para entornos controlados (desarrollo local, sitios con
     * certificados autofirmados o expirados conocidos).
     *
     * Este ajuste persiste durante toda la vida de la instancia de Scurl:
     * reset() NO lo revierte, y aplica a todas las requests posteriores hasta
     * que se llame setInsecure(false) explícitamente.
     */
    public function setInsecure(bool $enable = true): void
    {
        $this->options[CURLOPT_SSL_VERIFYPEER] = !$enable;
        $this->options[CURLOPT_SSL_VERIFYHOST] = $enable ? 0 : 2;
    }
    public function setHeaders(array $headers): void
    {
        $current = [];
        foreach ($this->options[CURLOPT_HTTPHEADER] ?? [] as $header) {
            if (str_contains($header, ':')) {
                [$key, $value] = explode(':', $header, 2);
                $current[strtolower(trim($key))] = [trim($key), trim($value)];
            }
        }

        $hasUserAgent = false;
        foreach ($headers as $key => $value) {
            if (is_int($key) && str_contains($value, ':')) {
                [$k, $v] = explode(':', $value, 2);
                $current[strtolower(trim($k))] = [trim($k), trim($v)];
                if (strtolower(trim($k)) === 'user-agent') $hasUserAgent = true;
            } else {
                $current[strtolower(trim($key))] = [trim($key), trim($value)];
                if (strtolower(trim($key)) === 'user-agent') $hasUserAgent = true;
            }
        }

        if ($hasUserAgent && isset($current['user-agent'])) {
            unset($this->options[CURLOPT_HTTPHEADER]);
            $this->options[CURLOPT_HTTPHEADER] = array_map(
                fn($entry) => "{$entry[0]}: {$entry[1]}",
                $current
            );
        } else {
            $this->options[CURLOPT_HTTPHEADER] = array_map(
                fn($entry) => "{$entry[0]}: {$entry[1]}",
                array_values($current)
            );
        }
    }

    public function getHeader(string $key): ?string
    {
        $headers = $this->options[CURLOPT_HTTPHEADER] ?? [];
        foreach ($headers as $header) {
            [$hKey, $hValue] = explode(':', $header, 2);
            if (strcasecmp(trim($hKey), $key) === 0) {
                return trim($hValue);
            }
        }
        return null;
    }
    public function setJsonHeader(): void
    {
        $this->setHeaders(['Content-Type' => 'application/json']);
    }
    public function setUserAgent(string $userAgent) : void
    {
        $this->forcedUserAgent = $userAgent;
    }
    public function getUserAgent() : string
    {
        return $this->forcedUserAgent ?? $this->getHeader('user-agent') ?? '';
    }
    /**
     * Asigna el body de la petición.
     *
     * Tres modos de almacenamiento según el tipo y contenido:
     *
     *   1. Array o CURLFile  → se guarda tal cual (cURL arma multipart / URL-encoded).
     *   2. String JSON válido (empieza con '{' o '[') → se guarda como string.
     *      Si config['auto_json'] está activo, se añade Content-Type: application/json.
     *   3. String tipo query-string URL-encoded (p.ej. 'a=1&b=2') → parse_str a array.
     *      Detección conservadora: debe contener '=', NO contener whitespace, y
     *      empezar con un caracter válido de nombre de campo.
     *   4. Cualquier otro string (texto plano, XML, binario, etc.) → se guarda
     *      CRUDO. cURL lo enviará como body literal. El Content-Type queda a
     *      cargo del usuario vía headers() o json().
     *
     * Nota histórica: versiones anteriores hacían parse_str() sobre cualquier
     * string no-JSON, lo que convertía silenciosamente bodies como 'hello world'
     * en arrays absurdos como ['hello_world' => '']. Ahora esos strings se
     * preservan literalmente.
     */
    public function setParameters(array|string|CURLFile $parameters): void
    {
        // Caso 1: array o CURLFile → se almacena tal cual.
        if (is_array($parameters) || $parameters instanceof CURLFile) {
            $this->parameters = $parameters;
            return;
        }

        $trimmed = trim($parameters);

        // Caso 2: JSON válido.
        if ($trimmed !== ''
            && (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '['))
            && json_validate($trimmed)
        ) {
            $this->parameters = $trimmed;
            if (!empty($this->config['auto_json'])) {
                $this->setJsonHeader();
            }
            return;
        }

        // Caso 3: query-string URL-encoded.
        //   - contiene al menos un '='
        //   - NO contiene whitespace (las query-strings reales vienen URL-encoded)
        //   - el primer caracter es válido para un nombre de campo
        if ($trimmed !== ''
            && str_contains($trimmed, '=')
            && !preg_match('/\s/', $trimmed)
            && preg_match('/^[A-Za-z0-9_%\[]/', $trimmed)
        ) {
            parse_str($trimmed, $parsed);
            $this->parameters = $parsed;
            return;
        }

        // Caso 4: string libre → se preserva CRUDO.
        $this->parameters = $parameters;
    }


    public function isOk(): bool
    {
        if (!isset($this->response)) return false;
        return $this->response->statuscode() >= 200 && $this->response->statuscode() < 300;
    }

    /**
     * Acepta un status HTTP como "no-error" cuando config['exceptions'] está activo.
     *
     * Admite tanto grupos clásicos (100, 200, 300, 400, 500) como códigos
     * específicos (p.ej. 404, 301, 503). Internamente todo código se normaliza
     * a su grupo de centena, de modo que `acceptStatus(404)` engloba todo el
     * rango 4xx — no hay aceptación de códigos individuales.
     *
     * Esta semántica es retrocompatible con llamadas clásicas
     * `acceptStatus(400)` / `acceptStatus(500)` y además permite que alguien
     * pase un código exacto sin tropezar con la excepción antigua.
     *
     * @throws InvalidArgumentException Si $status queda fuera del rango HTTP
     *                                  válido (100-599).
     */
    public function acceptStatus(int $status): void
    {
        if ($status < 100 || $status >= 600) {
            throw new InvalidArgumentException("Invalid HTTP status code or group: $status");
        }

        // Normalizamos al grupo de centena: 404 → 400, 301 → 300, etc.
        $group = intdiv($status, 100) * 100;

        if (!in_array($group, $this->acceptedStatusGroups)) {
            $this->acceptedStatusGroups[] = $group;
        }
    }

    protected function isStatusAccepted(int $status): bool
    {
        foreach ($this->acceptedStatusGroups as $group) {
            if ($status >= $group && $status < ($group + 100)) {
                return true;
            }
        }
        return false;
    }


    public function upload(string $path): void
    {
        if (!is_file($path)) {
            throw new InvalidArgumentException("Archivo no encontrado: $path");
        }
        $this->uploadFile = new CURLFile($path);
    }
    public function getUploadFile(): ?CURLFile
    {
        return $this->uploadFile;
    }
    public function getResponseHeaders(): array
    {
        return $this->responseHeaders ?? [];
    }

    /**
     * @throws Exception
     */
    public function send() : Response
    {
        $this->response = new Response();
        $this->response->setConfig($this->config);
        $ch = curl_init($this->getUrl());

        $fileStream = null;

        // 1. Manejo del Body (Subida cruda de archivo vs parámetros normales)
        if ($this->parameters instanceof CURLFile) {
            $filePath = $this->parameters->getFilename();

            if (!is_file($filePath)) {
                throw new InvalidArgumentException("Archivo no encontrado: $filePath");
            }

            $fileStream = fopen($filePath, 'r');

            $this->setOptions([
                CURLOPT_UPLOAD => true,
                CURLOPT_INFILE => $fileStream,
                CURLOPT_INFILESIZE => filesize($filePath),
            ]);

            // cURL por defecto cambia a PUT con CURLOPT_UPLOAD, forzamos el método original si es distinto
            if ($this->method !== 'PUT') {
                $this->setOptions([CURLOPT_CUSTOMREQUEST => $this->method]);
            }

            // Inyectar el Content-Type si el CURLFile lo tiene definido
            $mime = $this->parameters->getMimeType();
            if ($mime) {
                $this->setHeaders(['Content-Type' => $mime]);
            }

        } else {
            // Lógica original para arrays y strings
            if ($this->method === 'POST') {
                $this->setOptions([ CURLOPT_POST => true, CURLOPT_POSTFIELDS => $this->parameters ]);
            } elseif ($this->method !== 'GET') {
                $this->setOptions([ CURLOPT_CUSTOMREQUEST => $this->method,  CURLOPT_POSTFIELDS => $this->parameters ]);
            } else {
                unset(
                    $this->options[CURLOPT_POST],
                    $this->options[CURLOPT_POSTFIELDS],
                    $this->options[CURLOPT_CUSTOMREQUEST]
                );
            }
        }


        // 2. Manejo de lectura de Headers
        if (isset($this->options[CURLOPT_HEADER]) && $this->options[CURLOPT_HEADER] === true) {

            // Reiniciar headers para esta request
            $this->responseHeaders = [];

            $this->setOptions([
                CURLOPT_HEADERFUNCTION => function ($curl, $header) {

                    $len = strlen($header);
                    $header = trim($header);

                    if ($header === '') {
                        return $len;
                    }

                    if (strpos($header, ':') !== false) {
                        list($key, $value) = explode(':', $header, 2);
                        $key = strtolower(trim($key));  // Estandarizar keys
                        $value = trim($value);

                        // Manejo especial para múltiples Set-Cookie
                        if ($key === 'set-cookie') {
                            if (!isset($this->responseHeaders['set-cookie'])) {
                                $this->responseHeaders['set-cookie'] = [];
                            }
                            $this->responseHeaders['set-cookie'][] = $value;
                        } else {
                            $this->responseHeaders[$key] = $value;
                        }

                    } else {
                        // Headers tipo: HTTP/2 200 OK
                        $this->responseHeaders[] = $header;
                    }

                    return $len;
                }

            ]);
        }

        // 3. Manejo de Cookies
        if ($this->cookieFile !== null) {
            $this->setOptions([CURLOPT_COOKIEJAR => $this->cookieFile]);
            $this->setOptions([CURLOPT_COOKIEFILE => $this->cookieFile]);
        }

        // 4. Manejo de User-Agent forzado
        if ($this->forcedUserAgent !== null) {
            $currentHeaders = $this->options[CURLOPT_HTTPHEADER] ?? [];
            $filtered = array_filter($currentHeaders, fn($h) => !str_starts_with(strtolower($h), 'user-agent:'));
            $this->options[CURLOPT_HTTPHEADER] = array_values($filtered);
            $this->options[CURLOPT_HTTPHEADER][] = 'User-Agent: ' . $this->forcedUserAgent;
        }

        // 5. Ejecución de cURL
        curl_setopt_array($ch, $this->options);
        $rawBody = curl_exec($ch);
        $error   = curl_error($ch);
        $status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE); // Obtenemos el tamaño del header
        curl_close($ch);

        if (is_resource($fileStream)) {
            fclose($fileStream);
        }

        // --- Limpiamos el body si activamos los headers en el output ---
        if (isset($this->options[CURLOPT_HEADER]) && $this->options[CURLOPT_HEADER] === true && $rawBody !== false) {
            $body = substr($rawBody, $headerSize);
        } else {
            $body = $rawBody;
        }

        $this->response->setBody($body);
        $this->response->setStatusCode($status);
        $this->response->setCookieFileName($this->cookieFile);

        if (isset($this->options[CURLOPT_HEADER]) && $this->options[CURLOPT_HEADER] === true) {
            $this->response->setResponseHeaders($this->responseHeaders);
        }

        if (
            $this->config['exceptions'] &&
            !$this->isStatusAccepted($status)
        ) {
            throw new Exception("HTTP Error: $status - " . $this->response->body() . ($error ? " - Error: $error" : ""));
        }

        $this->reset();
        return $this->response;
    }

}
