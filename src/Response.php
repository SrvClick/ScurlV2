<?php
namespace SrvClick\Scurlv2;

class Response
{
    protected string $body;
    protected int $statusCode;
    protected array $headers = [];
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
    public function setCookieFileName(string $cookieFileName): void
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

    public function getHeader(string $name, $default = null)
    {
        $name = strtolower($name);
        if (!isset($this->responseHeaders)) {
            return $default;
        }
        return $this->responseHeaders[$name] ?? $default;
    }

    public function getCookie(string $cookieName, $default = null)
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

    public function setBody(string $body): void
    {
        $this->body = $body;
        $this->isJson = json_decode($body) !== null;
    }
    public function setStatusCode($statusCode): void
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
        return $this->headers;
    }

    public function isJson(): bool
    {
        return $this->isJson;
    }

    public function json(): ?array
    {
        return $this->isJson ? json_decode($this->body, true) : null;
    }

    public function isOk()
    {


        return $this->statuscode() >= 200 && $this->statuscode() < 300;
    }


}
