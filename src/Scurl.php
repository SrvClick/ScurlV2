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
            $scheme = $proxy[0] ?? 'http://';
            $port   = $proxy[1] ?? null;
            $user   = $proxy[2] ?? null;
            $pass   = $proxy[3] ?? null;

            if (!$port) {
                throw new InvalidArgumentException("Proxy port is required in array format.");
            }

            $url = parse_url($scheme);
            if (!isset($url['host'])) {
                throw new InvalidArgumentException("Proxy host must be included in the first element.");
            }

            $this->request->setOptions([
                CURLOPT_PROXY => $url['host'],
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
    public function patch(): Scurl { return $this->method('PUT'); }
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
    public function parameters(array|string $parameters): Scurl
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

    public function body(array|string $parameters): Scurl
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


    /**
     * @throws Exception
     */
    public function send(): Response
    {
        try {
            return $this->request->send();
        } catch (Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode());
        }
    }
}
