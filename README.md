# 🚀 SrvClick Scurl v2

**Scurl** es una librería moderna de PHP basada en cURL, orientada a objetos, fluida, fácil de extender y altamente reutilizable. Ideal para APIs, scraping y automatizaciones, con soporte completo para cookies, headers personalizados, archivos y más.

Su diseño permite reutilizar una sola instancia de Scurl para manejar múltiples solicitudes HTTP consecutivas, conservando configuraciones globales como headers, cookies, timeouts o el User-Agent.

Esto te permite escribir código más limpio y eficiente:




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
$curl->headers([
    'Content-Type: application/json',
    'Authorization: Bearer TOKEN'
]);

$curl->body(['key' => 'value']); // array , string o JSON
$curl->json();                   // Activa encabezado JSON automáticamente
```

---

### 🍪 Cookies persistentes

```php
$curl->cookie(); // Habilita y reutiliza cookies automáticamente
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
