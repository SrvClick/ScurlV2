# 🚀 SrvClick Scurl v2

**Scurl** es una librería moderna de PHP basada en cURL, orientada a objetos, fluida, fácil de extender y altamente reutilizable. Ideal para APIs, scraping y automatizaciones, con soporte completo para cookies, headers personalizados, archivos y más.

Su diseño permite reutilizar una sola instancia de Scurl para manejar múltiples solicitudes HTTP consecutivas, conservando configuraciones globales como headers, cookies, timeouts o el User-Agent.

Esto te permite escribir código más limpio y eficiente:




---

## ⚙️ Opciones de cURL (avanzado)

Scurl viene con opciones por defecto que puedes personalizar:

```php
$curl->options([
    CURLOPT_RETURNTRANSFER => true,   // Retornar string en vez de output
    CURLOPT_FOLLOWLOCATION => true,   // Seguir redirects
    CURLOPT_TIMEOUT => 30,           // Timeout en segundos
    CURLOPT_SSL_VERIFYPEER => false,  // Verificar SSL
    CURLOPT_SSL_VERIFYHOST => false,  // Verificar host SSL
    CURLOPT_COOKIEJAR => '/tmp/cookies.txt', // Guardar cookies
    CURLOPT_COOKIEFILE => '/tmp/cookies.txt', // Leer cookies
    CURLOPT_ENCODING => '',           // Accept-Encoding: gzip, deflate
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,          // Max redirects
    CURLOPT_CONNECTTIMEOUT => 10,   // Timeout de conexión
    // ... cualquier otra opción de cURL
]);
```

O establecer una opción individual:

```php
$curl->options([CURLOPT_TIMEOUT => 60]);
```

Ver todas las opciones: https://www.php.net/manual/es/function.curl-setopt.php

---

## 🌐 Configurar Proxy

```php
// Formato string (recomendado)
$curl->proxy('http://proxy.example.com:8080');
$curl->proxy('http://user:pass@proxy.example.com:8080'); // Con autenticación
$curl->proxy('socks5://proxy.example.com:1080');    // SOCKS5

// Formato array
$curl->proxy(['http://', 'proxy.example.com', 'user', 'pass']);

// O directamente con opciones cURL
$curl->options([
    CURLOPT_PROXY => 'proxy.example.com:8080',
    CURLOPT_PROXYUSERPWD => 'user:pass',
    CURLOPT_PROXYTYPE => CURLPROXY_HTTP,
]);
```

---

## 📦 Instalación

```bash
composer require srvclick/scurlv2
```

Inclúyelo en tu proyecto vía autoload (PSR-4) o crea un paquete personalizado.

---

## 🧪 Ejemplo de uso

```php
use SrvClick\Scurlv2\Scurl;

$curl = new Scurl();

$curl->cookie() //Almacena y envia las cookies.
     ->headers([
         'User-Agent: SrvClick Scurl/2.0',
         'Accept: application/json',
         'X-Api-Key: 1234567890abcdef',
     ]) //Headers personalizados
     ->timeout(10); //Timeout de 10 segundos; 

$response = $curl->url('https://example.com')
                 ->get() // También disponible con el metodo method("GET")
                 ->send();
                 
if($response->isOk()) {
    // Procesar respuesta exitosa
    print_r( $response->json() ); // Si la respuesta es JSON, lo convierte a array
    
    echo $response->body(); // Texto plano de la respuesta
} else {
    // Manejar error
    echo "Error: " . $response->statuscode();
}

```

### 🌐 Multiples peticiones

```php
$curl = new Scurl();
$curl->config(['exceptions' => true])
     ->cookie()
     ->headers([
         'User-Agent: SrvClick Scurl/2.0',
         'Accept: application/json',
         'X-Api-Key: 1234567890abcdef',
     ])
     ->timeout(10);

// Primera solicitud (GET)
$response = $curl->url('https://example.com/url1')->get()->send();

if(! $response->isOk()) {
   die("Error en la primera solicitud: " . $response->statuscode());
} 
// Segunda solicitud (PUT)
$response = $curl->url('https://example.com/url2')
                ->put()
                ->body('{"name":"SrvClick Scurl","version":"2.0"}')
                ->acceptStatus(400) // Si retorna 400 no lanzará excepción
                ->send();
if(! $response->isOk()) {
   die("Error en la segunda solicitud: " . $response->statuscode());
} 
// Tercera solicitud (POST con archivo)
$response = $curl->url('https://example.com/url3')
                ->upload('/ruta/archivo.txt')
                ->body([
                    'nombre' => 'Test',
                    'archivo' => $curl->getUploadFile()
                ])
                ->post()
                ->send();
if(! $response->isOk()) {
   die("Error en la tercera solicitud: " . $response->statuscode());
} 

echo "Archivos subidos y respuestas procesadas correctamente.";
```

---

## 🧩 Características principales

### 🌐 Métodos HTTP

```php
$curl->get();      // Método GET
$curl->post();     // Método POST
$curl->put();      // Método PUT
$curl->delete();   // Método DELETE
$curl->patch();    // PATCH
$curl->head();     // HEAD
$curl->options_method(); // OPTIONS

o bien

$curl->method('GET');
$curl->method('POST');
...
```

---

### 🔧 Configuración

```php
$curl->config(
    [
        'exceptions' => true, // Lanza excepción si el status no es 2xx
        'auto_json' => true, // Añade automáticamente el header 'Content-Type: application/json' si se envía un JSON, es true por defecto.
    ]
); 
$curl->timeout(10);                    // Timeout de la solicitud
$curl->acceptStatus(400);             // Acepta 400 como respuesta válida y no se lanzará excepción
```

---

### 📤 Headers, parámetros y JSON

```php
// Array asociativo (la clave reemplaza si ya existe)
$curl->headers([
    'Content-Type' => 'application/json',
    'Authorization' => 'Bearer TOKEN',
    'user-agent' => 'Mi App/1.0',  // Case-insensitive: reemplaza User-Agent por defecto
]);

// Formato string (header completo)
$curl->headers([
    'Content-Type: application/json',
    'Authorization: Bearer TOKEN',
]);

$curl->body(['key' => 'value']); // array , string o JSON
$curl->json();                   // Activa encabezado JSON automáticamente

// Obtener un header específico (case-insensitive)
$curl->getHeader('Content-Type');

// User-Agent: método dedicado tiene máxima prioridad
$curl->useragent('Mozilla/5.0 (Windows NT 10.0)'); // ⚠️ Siempre reemplaza cualquier User-Agent existente

// Prioridad User-Agent (mayor a menor):
// 1. useragent('...') - Siempre gana
// 2. headers(['user-agent' => '...']) - Reemplaza el default
// 3. Default: SrvClick Scurl/2.0
```

### 🔍 Métodos GET

```php
$curl->getUrl();         // URL actual
$curl->getMethod();      // Método HTTP actual (GET, POST, etc)
$curl->getOptions();    // Todas las opciones cURL
$curl->getUserAgent();  // User-Agent actual
$curl->getUploadFile(); // Objeto CURLFile si se configuró upload
```

---

### 🍪 Cookies persistentes

```php
$curl->cookie(); // Habilita y reutiliza cookies automáticamente

// Gestion manual de cookies
$curl->addCookie('session', 'abc123', 'example.com');
$curl->replaceCookie('session', 'xyz789', 'example.com');
$curl->deleteCookie('session', 'example.com');
$curl->deleteCookieCompletely('session'); // Elimina de todos los dominios
```

#### Archivo de cookies persistentes

```php
$curl->cookieFile('/tmp/cookies.txt'); // Archivo para cookies persistentes
$curl->cookie(); // Habilitar para usar el archivo
```

---

### 📎 Subida de archivos

```php
$curl->upload('/ruta/archivo.txt')
     ->body([
         'nombre' => 'Archivo de prueba',
         'archivo' => $curl->getUploadFile()
     ])
     ->post()
     ->send();
```

---

## 📥 Manejo de respuesta

```php
$response = $curl->send();

$response->statuscode();    // Código HTTP
$response->body();          // Texto plano
$response->json();          // Array si es JSON
$response->isOk();          // true si status es 2xx
$response->headers();       // Array de headers de respuesta
$response->header('content-type'); // Un header específico (case-insensitive)
```

---

## 🧠 Clases principales

| Clase     | Descripción |
|-----------|-------------|
| `Scurl`   | Clase principal. Encadena métodos y ejecuta solicitudes. |
| `Request` | Administra headers, método, cuerpo, timeout, cookies y configuración general. |
| `Response`| Provee acceso a cuerpo, status code, headers, validación y parseo de JSON. |

---

## ⚠️ Errores y excepciones

Si activas:

```php
$curl->config(['exceptions' => true]);
```

Entonces cualquier código fuera de 2xx (o el grupo aceptado con `acceptStatus`) lanzará una `Exception` automática.

---

## 📋 Requisitos

- PHP 8.0 o superior
- Extensión `curl` habilitada

---

## 📄 Licencia

Este proyecto está licenciado bajo la licencia **MIT**.
