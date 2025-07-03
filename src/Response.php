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

    public function setConfig(array $config): void
    {
        $this->config = array_replace($this->config, $config);
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
