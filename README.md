# 🚀 SrvClick Scurl v2

**Scurl** es una librería moderna de PHP basada en cURL, orientada a objetos, fluida, fácil de extender y altamente reutilizable. Ideal para APIs, scraping y automatizaciones, con soporte completo para cookies, headers personalizados, archivos y más.

Su diseño permite reutilizar una sola instancia de Scurl para manejar múltiples solicitudes HTTP consecutivas, conservando configuraciones globales como headers, cookies, timeouts o el User-Agent.

---

## 📦 Instalación

```bash
composer require srvclick/scurlv2
```

Inclúyelo en tu proyecto vía autoload (PSR-4).

---

## 📋 Requisitos

- PHP **8.3** o superior
- Extensión `curl` habilitada
- Extensión `json` habilitada

---

## 🧪 Ejemplo de uso

```php
use SrvClick\Scurlv2\Scurl;

$curl = new Scurl();

$curl->cookie()              // Almacena y envía las cookies
     ->headers([
         'Accept'    => 'application/json',
         'X-Api-Key' => '1234567890abcdef',
     ])
     ->timeout(10);

$response = $curl->url('https://example.com')
                 ->get()
                 ->send();

if ($response->isOk()) {
    print_r($response->json()); // Si la respuesta es JSON, lo convierte a array
    echo $response->body();     // Texto plano de la respuesta
} else {
    echo "Error: " . $response->statuscode();
}
```

### 🌐 Múltiples peticiones

```php
$curl = new Scurl();
$curl->config(['exceptions' => true])
     ->cookie()
     ->headers([
         'Accept'    => 'application/json',
         'X-Api-Key' => '1234567890abcdef',
     ])
     ->timeout(10);

// Primera solicitud (GET)
$response = $curl->url('https://example.com/url1')->get()->send();

if (!$response->isOk()) {
    die("Error en la primera solicitud: " . $response->statuscode());
}

// Segunda solicitud (PUT)
$response = $curl->url('https://example.com/url2')
                 ->put()
                 ->body('{"name":"SrvClick Scurl","version":"2.0"}')
                 ->acceptStatus(400) // Si retorna 400 no lanzará excepción
                 ->send();

if (!$response->isOk()) {
    die("Error en la segunda solicitud: " . $response->statuscode());
}

// Tercera solicitud (POST con archivo multipart)
$response = $curl->url('https://example.com/url3')
                 ->upload('/ruta/archivo.txt')
                 ->body([
                     'nombre'  => 'Test',
                     'archivo' => $curl->getUploadFile(),
                 ])
                 ->post()
                 ->send();

if (!$response->isOk()) {
    die("Error en la tercera solicitud: " . $response->statuscode());
}

echo "Solicitudes procesadas correctamente.";
```

---

## 🧩 Características principales

### 🌐 Métodos HTTP

```php
$curl->get();            // GET
$curl->post();           // POST
$curl->put();            // PUT
$curl->delete();         // DELETE
$curl->patch();          // PATCH
$curl->head();           // HEAD
$curl->options_method(); // OPTIONS  ← No confundir con options() que configura cURL

// O de forma genérica:
$curl->method('GET');
$curl->method('POST');
```

---

### 🔧 Configuración

```php
$curl->config([
    'exceptions' => true,  // Lanza excepción si el status no es 2xx
    'auto_json'  => true,  // Añade Content-Type: application/json automáticamente si el body es JSON (true por defecto)
]);

$curl->timeout(10);          // Timeout de la solicitud en segundos
$curl->acceptStatus(400);    // Acepta 4xx como respuesta válida (no lanzará excepción)
```

---

### 📤 Headers, parámetros y JSON

```php
// Array asociativo (la clave reemplaza si ya existe, case-insensitive)
$curl->headers([
    'Content-Type'  => 'application/json',
    'Authorization' => 'Bearer TOKEN',
    'user-agent'    => 'Mi App/1.0',  // Reemplaza el User-Agent por defecto
]);

// Formato string (header completo)
$curl->headers([
    'Content-Type: application/json',
    'Authorization: Bearer TOKEN',
]);

$curl->body(['key' => 'value']); // Array, string o JSON
$curl->json();                   // Activa encabezado JSON manualmente

// User-Agent: método dedicado tiene máxima prioridad
$curl->useragent('Mozilla/5.0 (Windows NT 10.0)');
// ⚠️ Siempre reemplaza cualquier User-Agent existente

// Prioridad User-Agent (mayor a menor):
// 1. useragent('...') - Siempre gana
// 2. headers(['user-agent' => '...']) - Reemplaza el default
// 3. Default: SrvClick Scurl/2.0
```

### 🔍 Métodos GET (lectura de estado)

```php
$curl->getUrl();         // URL actual
$curl->getMethod();      // Método HTTP actual (GET, POST, etc.)
$curl->getOptions();     // Todas las opciones cURL activas
$curl->getUserAgent();   // User-Agent actual
$curl->getUploadFile();  // Objeto CURLFile si se configuró upload(), o null
```

---

### 🍪 Cookies persistentes

```php
$curl->cookie(); // Habilita y reutiliza cookies automáticamente (archivo temporal)

// Gestión manual de cookies
$curl->addCookie('session', 'abc123', 'example.com');
$curl->replaceCookie('session', 'xyz789', 'example.com');
$curl->deleteCookie('session', 'example.com');
$curl->deleteCookieCompletely('session'); // Elimina de todos los dominios
```

#### Archivo de cookies persistentes

```php
$curl->cookieFile('/tmp/cookies.txt'); // Archivo específico para cookies persistentes
```

---

### 📎 Subida de archivos

#### Multipart (con otros campos de formulario)

```php
$curl->upload('/ruta/archivo.txt')
     ->body([
         'nombre'  => 'Archivo de prueba',
         'archivo' => $curl->getUploadFile(),
     ])
     ->post()
     ->send();
```

#### Raw (el archivo es el cuerpo completo de la petición)

Útil para APIs REST que esperan el binario directamente (imágenes, documentos, etc.):

```php
$curl->url('https://api.example.com/upload')
     ->upload('/ruta/imagen.jpg')
     ->headers(['Content-Type' => 'application/octet-stream'])
     ->put()
     ->body($curl->getUploadFile()) // CURLFile como body raw, sin multipart
     ->send();
```

---

### 📥 Captura de headers de respuesta

Usa el método fluent `getHeaders()` para habilitar la captura de headers de respuesta:

```php
$response = $curl->url('https://api.example.com/resource')
                 ->get()
                 ->getHeaders() // Activa CURLOPT_HEADER internamente
                 ->send();

$contentType = $response->getHeader('content-type');
$rateLimit   = $response->getHeader('x-ratelimit-remaining', 'unknown');
$token       = $response->getCookie('token'); // Lee cookies del header Set-Cookie
```

También puedes activarlo mediante `options()`:

```php
$curl->options([CURLOPT_HEADER => true]);
```

---

## ⚙️ Opciones de cURL (avanzado)

```php
$curl->options([
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_SSL_VERIFYPEER => true,   // Desactivado por defecto
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_ENCODING       => '',     // Accept-Encoding: gzip, deflate
    CURLOPT_CONNECTTIMEOUT => 10,
]);
```

Ver todas las opciones: https://www.php.net/manual/es/function.curl-setopt.php

---

## 🌐 Configurar Proxy

```php
// Formato string (recomendado)
$curl->proxy('http://proxy.example.com:8080');
$curl->proxy('http://user:pass@proxy.example.com:8080'); // Con autenticación
$curl->proxy('socks5://proxy.example.com:1080');

// Formato array [host, port, user (opcional), pass (opcional)]
$curl->proxy(['proxy.example.com', 8080]);
$curl->proxy(['proxy.example.com', 8080, 'user', 'pass']);
```

---

## 📥 Manejo de respuesta

```php
$response = $curl->send();

$response->statuscode();             // Código HTTP (int)
$response->body();                   // Texto plano de la respuesta
$response->json();                   // Array si es JSON, null si no
$response->isOk();                   // true si status es 2xx
$response->isJson();                 // true si el body es JSON válido
$response->headers();                // Array de todos los headers de respuesta
$response->getHeader('content-type'); // Header específico (case-insensitive)
$response->getCookie('nombre');      // Cookie de la respuesta (requiere getHeaders())
```

---

## 🧠 Clases principales

| Clase      | Descripción |
|------------|-------------|
| `Scurl`    | Clase principal. Encadena métodos y ejecuta solicitudes. |
| `Request`  | Administra headers, método, cuerpo, timeout, cookies y configuración general. |
| `Response` | Provee acceso a cuerpo, status code, headers, validación y parseo de JSON. |

---

## ⚠️ Errores y excepciones

```php
// Lanza Exception para cualquier respuesta fuera de 2xx
$curl->config(['exceptions' => true]);

try {
    $response = $curl->url('https://api.example.com/data')->get()->send();
} catch (Exception $e) {
    echo $e->getMessage();
}

// Acepta 4xx como válido (no lanza excepción aunque exceptions=true)
$curl->acceptStatus(400);
```

---

## 📄 Licencia

Este proyecto está licenciado bajo la licencia **MIT**.
