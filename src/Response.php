<?php
namespace SrvClick\Scurlv2;

class Response
{
    protected ?string $body = null;
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

    public function getHeader(string $name, $default = null)
    {
        $name = strtolower($name);
        if (empty($this->responseHeaders)) {
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

    public function isOk()
    {


        return $this->statuscode() >= 200 && $this->statuscode() < 300;
    }


}
