<?php
namespace SrvClick\Scurlv2;
/*
 * Scurl v2
 * Fecha de inicio de desarrollo: 2025-07-02
 */

use CURLFile;
use Exception;
use InvalidArgumentException;

class Scurl
{
    protected Request $request;
    public function __construct()
    {
        $this->request = new Request();
    }
    public function url(string $url) : Scurl
    {
        $this->request->setUrl($url);
        return $this;
    }
    public function target( string $url): Scurl
    {
        return $this->url($url);
    }

    public function deleteCookie(string $name, ?string $domain = null) : Scurl
    {
        $this->request->deleteCookie($name, $domain);
        return $this;
    }

    public function deleteCookieCompletely(string $name) : Scurl
    {
        $this->request->deleteCookieCompletely($name);
        return $this;
    }


    public function replaceCookie(string $name, string $value, string $domain,string $path = "/", bool $secure = false, int $expires = 0) : Scurl {
        $this->request->replaceCookie($name,$value, $domain, $path, $secure,  $expires);
        return $this;
    }

    public function addCookie(string $name, string $value, string $domain, string $path = "/", bool $secure = false, int $expires = 0): Scurl
    {
        $this->request->addCookie($name, $value, $domain, $path, $secure, $expires);
        return $this;
    }

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
    public function cookieFile(?string $file = null): self
    {
        $this->request->enableCookies($file);
        return $this;
    }
    public function cookie() : Scurl
    {
        $this->request->enableCookies();
        return $this;
    }
    public function getUrl() : string
    {
        return $this->request->getUrl();
    }
    public function getMethod() : string
    {
        return $this->request->getMethod();
    }

    public function config(array $config) : Scurl
    {
        $this->request->setConfig($config);
        return $this;
    }
    public function method(string $method): Scurl
    {
        $this->request->setMethod($method);
        return $this;
    }
    public function get(): Scurl { return $this->method("GET"); }

    public function post(): Scurl { return $this->method("POST"); }
    public function put(): Scurl { return $this->method("PUT"); }
    public function delete(): Scurl { return $this->method("DELETE"); }
    public function patch(): Scurl { return $this->method('PATCH'); }
    public function head(): Scurl { return $this->method('HEAD'); }
    public function options_method(): Scurl { return $this->method('OPTIONS'); }

    public function options(array $options): Scurl
    {
        $this->request->setOptions($options);
        return $this;
    }
    public function getOptions() : array
    {
        return $this->request->getOptions();
    }
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
    public function json() : Scurl
    {
        $this->request->setJsonHeader();
        return $this;
    }
    public function useragent(string $useragent): Scurl
    {
        $this->request->setUserAgent($useragent);
        return $this;
    }
    public function getUserAgent() : string
    {
        return $this->request->getUserAgent();
    }
    public function parameters(array|string|CURLFile $parameters): Scurl
    {
        $this->request->setParameters($parameters);
        return $this;
    }
    public function headers(array $headers) : Scurl
    {
        $this->request->setHeaders($headers);
        return $this;
    }
    public function isOK(): bool
    {
        return $this->request->isOk();
    }
    public function acceptStatus(int $group): Scurl
    {
        $this->request->acceptStatus($group);
        return $this;
    }

    public function body(array|string|CURLFile $parameters): Scurl
    {
        $this->parameters($parameters);
        return $this;
    }

    public function upload(string $path): Scurl
    {
        $this->request->upload($path);
        return $this;
    }
    public function getUploadFile(): ?CURLFile
    {
        return $this->request->getUploadFile();
    }
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
