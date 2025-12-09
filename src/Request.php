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

    protected Response $response;
    protected array|string $parameters = [];
    protected array $config = [
        'auto_json' => true,
        'exceptions' => false,
    ];
    protected array $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => [
            'User-Agent: SrvClick Scurl/2.0',
        ],
    ];

    protected array $acceptedStatusGroups = [200];

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

    public function deleteCookie(string $name, string $domain = null): self
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

            $columns = preg_split('/\s+/', $line);
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
            $cols = preg_split('/\s+/', trim(ltrim($line, '#')));
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
    public function setHeaders(array $headers): void
    {
        $current = [];
        foreach ($this->options[CURLOPT_HTTPHEADER] ?? [] as $header) {
            if (str_contains($header, ':')) {
                [$key, $value] = explode(':', $header, 2);
                $current[trim($key)] = trim($value);
            }
        }
        foreach ($headers as $key => $value) {
            if (is_int($key) && str_contains($value, ':')) {
                [$k, $v] = explode(':', $value, 2);
                $current[trim($k)] = trim($v);
            } else {
                $current[trim($key)] = trim($value);
            }
        }
        $this->options[CURLOPT_HTTPHEADER] = array_map(
            fn($k, $v) => "$k: $v",
            array_keys($current),
            $current
        );
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
        $this->setHeaders(['User-Agent' => $userAgent]);
    }
    public function getUserAgent() : string
    {
        return $this->getHeader('user-agent');
    }
    public function setParameters(array|string $parameters): void
    {
        if (is_array($parameters)) {
            $this->parameters = $parameters;
            return;
        }
        $trimmed = trim($parameters);
        if ((str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) && json_validate($trimmed)) {
            $this->parameters = $trimmed;
            if (isset($this->config['auto_json']) && $this->config['auto_json']) {
                $this->setJsonHeader();
            }
            return;
        }
        parse_str($parameters, $parsed);
        $this->parameters = $parsed;
    }


    public function isOk(): bool
    {
        if (!isset($this->response)) return false;
        return $this->response->statuscode() >= 200 && $this->response->statuscode() < 300;
    }

    public function acceptStatus(int $group): void
    {
        if (!in_array($group, [100, 200, 300, 400, 500])) {
            throw new InvalidArgumentException("Invalid HTTP status group: $group");
        }

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

    /**
     * @throws Exception
     */
    public function send() : Response
    {
        $this->response = new Response();
        $this->response->setConfig($this->config);
        $ch = curl_init($this->getUrl());

        if ($this->method === 'POST') {
            $this->setOptions([ CURLOPT_POST => true, CURLOPT_POSTFIELDS => $this->parameters ]);
        } elseif ($this->method !== 'GET') {
            $this->setOptions([ CURLOPT_CUSTOMREQUEST => $this->method,  CURLOPT_POSTFIELDS => $this->parameters ]);
        }else{
            unset(
                $this->options[CURLOPT_POST],
                $this->options[CURLOPT_POSTFIELDS],
                $this->options[CURLOPT_CUSTOMREQUEST]
            );
        }


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





        if ($this->cookieFile !== null) {
            $this->setOptions([CURLOPT_COOKIEJAR => $this->cookieFile]);
            $this->setOptions([CURLOPT_COOKIEFILE => $this->cookieFile]);
        }

        curl_setopt_array($ch, $this->options);
        $body   = curl_exec($ch);
        $error  = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $this->response->setBody($body);
        $this->response->setStatusCode($status);

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
