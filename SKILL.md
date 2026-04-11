# Scurl v2 - Skill Documentation

## Project Overview

**Scurl** es una librería PHP moderna de HTTP basada en cURL, diseñada con API fluida (chainable), reusable y extensible.

## Architecture

```
Scurl (API fluida)
    └── Request (lógica de petición)
            └── Response (manejo de respuesta)
```

## Usage Pattern

```php
$curl = new Scurl();
$curl->config([...])          // configuración global
     ->headers([...])        // headers (persistentes)
     ->cookie()              // cookies habilitadas
     ->timeout(10);

// Request 1
$response = $curl->url('https://api.example.com/1')
                 ->get()
                 ->send();

// Request 2 (reusa headers, cookies, timeout)
$response = $curl->url('https://api.example.com/2')
                 ->post()
                 ->body(['key' => 'value'])
                 ->send();
```

## Core Methods

### Configuration

| Method | Description |
|--------|------------|
| `url(string $url)` | Set URL |
| `target(string $url)` | Alias de url |
| `config(['exceptions' => bool, 'auto_json' => bool])` | Config global |
| `timeout(int $seconds)` | Timeout (default: 30) |
| `options([CURLOPT_* => value])` | Opciones cURL nativas |

### HTTP Methods

```php
$curl->get();              // GET
$curl->post();             // POST  
$curl->put();             // PUT
$curl->delete();           // DELETE
$curl->patch();           // PATCH
$curl->head();            // HEAD
$curl->options_method(); // OPTIONS
$curl->method('CUSTOM');  // Método arbitrario
```

### Headers (case-insensitive merge)

```php
// Formato array asociativo
$curl->headers([
    'Content-Type' => 'application/json',
    'Authorization' => 'Bearer token',
    'user-agent' => 'MyApp/1.0',  // reemplaza el default
]);

// Formato string
$curl->headers([
    'Content-Type: application/json',
    'Authorization: Bearer token',
]);
```

**Important**: Headers se hace **merge** automáticamente. Si pushea 'user-agent' reemplaza el default 'SrvClick Scurl/2.0'.

### Body

```php
$curl->body(['key' => 'value']);     // array
$curl->body('{"key":"value"}');    // JSON string
$curl->body('key=value&foo=bar');  // form-urlencoded
$curl->json();                    // añade Content-Type: application/json
```

### Cookies

```php
$curl->cookie();                                    // habilita cookies (memoria)
$curl->cookieFile('/tmp/cookies.txt');               // archivo persistente
$curl->addCookie('name', 'value', 'domain.com');
$curl->replaceCookie('name', 'newValue', 'domain.com');
$curl->deleteCookie('name', 'domain.com');
$curl->deleteCookieCompletely('name');
```

### Proxy

```php
// String (recomendado)
$curl->proxy('http://user:pass@proxy.com:8080');
$curl->proxy('socks5://proxy.com:1080');

// Array
$curl->proxy(['proxy.com', 8080, 'user', 'pass']);
```

### Upload

```php
$curl->upload('/path/to/file.txt')
    ->body(['field' => $curl->getUploadFile()])
    ->post()
    ->send();
```

### Error Handling

```php
$curl->config(['exceptions' => true]);
$curl->acceptStatus(400);  // acepta 400 como válido
$curl->acceptStatus(500);  // acepta 5xx como válido
```

## Response Methods

```php
$response = $curl->send();

$response->body();           // string plano
$response->json();          // array (si es JSON)
$response->statuscode();    // int (200, 404, etc)
$response->isOk();         // bool (2xx)
$response->headers();        // array de headers
$response->header('content-type'); // string
$response->getCookie('session'); // string
$response->isJson();       // bool
```

## Execution Flow

1. `new Scurl()` → crea instancia con config por defecto
2. Configurar (headers, proxy, cookies, etc) → **persiste entre requests**
3. `->url()` → define endpoint
4. `->get()/post()/etc` → define método
5. `->body()/json()` → opcional
6. `->send()` → ejecuta, retorna Response, luego **reset()**

## Key Behaviors

### Headers Merge
```php
$curl->headers(['A' => '1']);  // ['A: 1']
$curl->headers(['B' => '2']);  // ['A: 1', 'B: 2'] (A no se pierde)
```

### reset() Scope
- ✅ Limpia: parameters, uploadFile, Content-Type header
- ❌ Mantiene: headers, cookies, proxy, timeout, options

### Default Headers
```
user-agent: SrvClick Scurl/2.0
```
Se reemplaza si se usa 'user-agent' o 'User-Agent' en headers().

### Case-Insensitive
- Keys de headers son normalizadas a lowercase para merge (evita duplicados)
- Capitalización original se preserva al enviar
- getHeader() busca case-insensitive

## Dependencies

- PHP 8.0+
- ext-curl

## Files

```
src/
├── Scurl.php    # Clase principal fluida
├── Request.php  # Lógica de requête
└── Response.php # Manejo de respuesta
```