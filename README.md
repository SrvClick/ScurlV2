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

### 🔒 Verificación SSL

Por defecto Scurl **verifica el certificado SSL del servidor** en conexiones HTTPS (`CURLOPT_SSL_VERIFYPEER=true`, `CURLOPT_SSL_VERIFYHOST=2`). Esto protege contra ataques MITM y es el comportamiento recomendado.

Si necesitas conectarte a un servidor con certificado autofirmado, expirado, o en un entorno de desarrollo local, puedes desactivar la verificación con `insecure()`:

```php
$curl->url('https://self-signed.dev/api')
     ->insecure()        // ⚠️ Desactiva verificación SSL
     ->get()
     ->send();
```

**Características del flag `insecure()`:**

- **Persiste durante toda la vida de la instancia** de Scurl — `reset()` no lo revierte.
- Afecta a todas las peticiones posteriores hasta que lo desactives explícitamente.
- Para re-activar la verificación en la misma instancia: `$curl->insecure(false)`.

```php
$curl = new Scurl();
$curl->insecure();                              // SSL desactivado
$curl->url('https://dev.local')->get()->send(); // sin verificar
$curl->url('https://dev.local')->post()->send();// sin verificar (persiste)

$curl->insecure(false);                         // SSL re-activado
$curl->url('https://api.prod.com')->get()->send(); // verifica normalmente
```

> ⚠️ **Nunca uses `insecure()` en producción contra servidores reales.** Deja la conexión vulnerable a ataques de intermediario. Solo tiene sentido en entornos controlados donde confías en la red de manera explícita.

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
$response->array();                  // Array si es JSON, null si no (recomendado)
$response->json();                   // @deprecated — alias de array(), será removido
$response->isOk();                   // true si status es 2xx
$response->isJson();                 // true si el body es JSON válido
$response->headers();                // Array de todos los headers de respuesta
$response->getHeader('content-type'); // Header específico (case-insensitive)
$response->getCookie('nombre');      // Cookie de la respuesta (requiere getHeaders())
```

### 🎯 Acceso a JSON con dot notation

Para los casos en que solo necesitas leer o validar un campo puntual del JSON del response, Scurl expone tres métodos (y un atajo invocable) que evitan decodificar a mano y navegar arrays anidados:

```php
// body: {"success": true, "data": {"user": {"id": 42, "name": "Joel"}}}

// 1) get() — obtener un valor (con default opcional)
$response->get('data.user.id');              // 42
$response->get('data.user.name');            // 'Joel'
$response->get('no.existe', 'fallback');     // 'fallback'
$response->get('data.user');                 // ['id' => 42, 'name' => 'Joel']

// 2) has() — verificar si una ruta existe (distingue "ausente" de "null")
$response->has('data.user.id');              // true
$response->has('data.user.fecha_muerte');    // false

// 3) expectJson() — comparación estricta (===)
$response->expectJson('success', true);            // true
$response->expectJson('data.user.id', 42);         // true
$response->expectJson('data.user.id', '42');       // false (int !== string)

// 4) Atajo invocable: $response() como función
$response('data.user.id');         // con 1 arg → get()
$response('data.user.id', 42);     // con 2 args → expectJson()
```

#### Uso típico

```php
$response = $curl->url('https://api.example.com/me')->get()->send();

if ($response->isOk() && $response('success', true)) {
    $userId = $response->get('data.user.id');
    $email  = $response->get('data.user.email', 'no-email@unknown');
    // ...
}
```

#### Reglas (idénticas al `expectJson()` del Orchestrator)

- **La comparación de `expectJson()` es estricta (`===`).** `1 !== "1"`, `true !== 1`.
- **Soporta índices numéricos:** `'data.roles.0'` accede a `$arr['data']['roles'][0]`.
- **Clave ausente → `null`** en `get()` / `expectJson()`. Si necesitas distinguir "falta" de "es null", usa `has()`.
- **Body no-JSON** → `get()` retorna el default, `has()` retorna `false`, `expectJson()` retorna `false` salvo que esperes `null` (por la regla anterior).
- **Sin wildcards ni filtros.** Es acceso por path literal; para lógica compleja, lee con `get()` y compara a mano.

#### El atajo invocable `$response(...)`

Internamente usa `func_num_args()` para distinguir:

```php
$response('data.foo');         // siempre get(), retorna el valor
$response('data.foo', null);   // expectJson('data.foo', null), retorna bool
```

Eso te permite, por ejemplo, validar explícitamente que un campo es `null`:

```php
if ($response('data.error', null)) {
    // data.error existe y es exactamente null (o no existe, se trata como null)
}
```

---

## 🧠 Clases principales

| Clase                                    | Descripción |
|------------------------------------------|-------------|
| `Scurl`                                  | Clase principal. Encadena métodos y ejecuta solicitudes. |
| `Request`                                | Administra headers, método, cuerpo, timeout, cookies y configuración general. |
| `Response`                               | Provee acceso a cuerpo, status code, headers, validación y parseo de JSON. |
| `Orchestrator`                           | Orquesta múltiples peticiones en orden, con expectations, reintentos y recuperación ante fallos. |
| `Orchestrator\Step`                      | Builder fluido de un paso dentro del flujo. Proxy-a todos los métodos de `Scurl`. |
| `Orchestrator\Result` / `StepResult`     | Resultado agregado del flujo y resultado individual por paso. |

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

### Tipos de excepción que puede lanzar `send()`

Scurl **no envuelve** las excepciones de la capa interna: se propagan con su clase, stack trace y cadena de `$previous` intactos. Esto permite catches específicos según el tipo de error:

```php
try {
    $response = $curl->upload('/ruta/archivo.pdf')
                     ->url('https://api.example.com/upload')
                     ->post()
                     ->send();
} catch (InvalidArgumentException $e) {
    // Archivo de upload no existe, proxy malformado,
    // status group inválido en acceptStatus(), etc.
    echo "Error de configuración: " . $e->getMessage();
} catch (Exception $e) {
    // HTTP error (solo si config(['exceptions' => true]) y el status no está aceptado)
    echo "Error de red/HTTP: " . $e->getMessage();
}
```

| Clase | Cuándo se lanza |
|---|---|
| `InvalidArgumentException` | Archivo de upload inexistente (`upload()` o body con CURLFile), proxy string sin host/port, grupo de status inválido en `acceptStatus()`. |
| `\Exception` | HTTP status no aceptado cuando `config(['exceptions' => true])`. El mensaje incluye el status code y el body de la respuesta. |

> 💡 El trace completo se conserva: `$e->getTrace()` apunta al lugar real donde se lanzó (dentro de `Request::send()` o helpers), no al `Scurl::send()` intermedio.

---

## 🪡 Orchestrator — Flujos multi-step

Para escenarios de scraping o automatizaciones donde se encadenan **varias peticiones HTTP dependientes entre sí** (el resultado de una condiciona la siguiente), Scurl incluye un orquestador que:

- Mantiene **una sola instancia de `Scurl`** a lo largo de todo el flujo (conserva cookies, headers, proxy, config, user-agent, etc. entre steps).
- Permite que un step específico use una **instancia fresca** aislada, sin contaminar la principal.
- Declara en cada paso **qué se espera** como respuesta (status HTTP, substring en el body, valor en un campo JSON, o un validador libre).
- Define **qué hacer si un paso falla**: reintentar con delay, cancelar el flujo, continuar al siguiente, o saltar a un step de recuperación.
- Expone un `Result` con el histórico completo de cada step y acceso directo a sus `Response`.

### Uso básico

```php
use SrvClick\Scurlv2\Orchestrator;
use SrvClick\Scurlv2\Scurl;

$orch = new Orchestrator();

// Configuración persistente del Scurl principal (headers globales, proxy, auth)
$orch->getScurl()
     ->config(['exceptions' => false])
     ->cookie()
     ->timeout(15)
     ->useragent('MyScraper/1.0');

$orch->step('login')
     ->url('https://site.com/api/login')
     ->post()
     ->body(['user' => 'x', 'pass' => 'y'])
     ->expectStatus(200)
     ->expectJson('success', true)
     ->retries(3, 1000)
     ->onFail('cancel');

$orch->step('fetch')
     ->url('https://site.com/api/data')
     ->get()
     ->expectStatus([200, 204])
     ->expectBodyContains('"status":"ok"');

$result = $orch->run();

if ($result->isSuccess()) {
    $data = $result->response('fetch')->array();
}
```

También puedes inyectar un `Scurl` ya preconfigurado:

```php
$curl = new Scurl();
$curl->config(['exceptions' => false])->timeout(20)->useragent('Bot/2.0');

$orch = new Orchestrator($curl);
// o equivalente:
$orch = (new Orchestrator())->scurl($curl);
```

---

### 🧱 Declaración de steps

El `Step` replica la estructura de `Scurl`: **cualquier método de `Scurl`** se puede llamar sobre un step y se encola para aplicarse al momento de ejecutar ese paso. Incluye `url`, `get`/`post`/`put`/`delete`/`patch`/`head`/`options_method`, `method`, `body`/`parameters`, `json`, `headers`, `timeout`, `useragent`, `upload`, `getHeaders`, `options`, `acceptStatus`, `proxy`, `cookie`/`cookieFile`, `addCookie`/`replaceCookie`/`deleteCookie`/`deleteCookieCompletely`, `config`.

```php
$orch->step('buscar')
     ->url('https://api.example.com/buscar')
     ->post()
     ->headers(['X-Api-Key' => 'abc'])
     ->body(['query' => 'laravel'])
     ->timeout(10);
```

---

### ✅ Expectations (qué se espera del response)

Se pueden combinar varias. Todas deben cumplirse para que el step pase. Si no declaras ninguna, por defecto se exige un status 2xx.

```php
$orch->step('check')
     // Status esperado (int o array de ints)
     ->expectStatus(200)
     ->expectStatus([200, 204])

     // El body debe contener estos substrings (case-sensitive)
     ->expectBodyContains('exito')
     ->expectBodyContains(['"ok":true', 'token'])

     // Valor esperado en un campo JSON (dot notation)
     ->expectJson('success', true)
     ->expectJson('data.user.id', 42)

     // Validador libre. Retornar true/null=pasa, false=falla, string=falla con mensaje
     ->expect(function ($response) {
         return $response->getHeader('x-ratelimit-remaining') > 0
             ? true
             : 'Rate limit agotado';
     });
```

---

### 🔎 Cómo funciona `expectJson()` — dot notation

`expectJson($path, $esperado)` primero decodifica el body del response como array (equivalente a `$response->array()`) y luego navega ese array siguiendo el path separado por puntos. Cada segmento entre puntos es una **clave de array**.

Dicho de otra forma, esta llamada:

```php
->expectJson('data.user.id', 42)
```

es conceptualmente equivalente a:

```php
$arr = $response->array();
$actual = $arr['data']['user']['id'] ?? null;
if ($actual !== 42) { /* falla */ }
```

#### Ejemplos prácticos

Dado este response JSON:

```json
{
  "success": true,
  "data": {
    "user": { "id": 42, "name": "user" },
    "roles": ["admin", "editor"]
  },
  "meta": { "page": 1 }
}
```

| Llamada | Equivalente en PHP | ¿Pasa? |
|---|---|---|
| `->expectJson('success', true)` | `$arr['success'] === true` | ✅ |
| `->expectJson('data.user.id', 42)` | `$arr['data']['user']['id'] === 42` | ✅ |
| `->expectJson('data.user.name', 'user')` | `$arr['data']['user']['name'] === 'user'` | ✅ |
| `->expectJson('meta.page', 1)` | `$arr['meta']['page'] === 1` | ✅ |
| `->expectJson('data.user.id', '42')` | `$arr['data']['user']['id'] === '42'` | ❌ (int ≠ string) |
| `->expectJson('data.roles.0', 'admin')` | `$arr['data']['roles'][0] === 'admin'` | ✅ |
| `->expectJson('no.existe', null)` | clave ausente → `null` vs `null` | ✅ |
| `->expectJson('no.existe', true)` | clave ausente → `null` vs `true` | ❌ |

#### Reglas clave

1. **La comparación es estricta (`===`).** `1` no es igual a `"1"`, `true` no es igual a `1`, `null` no es igual a `""`.
2. **Navega arrays indexados con el índice numérico como segmento** — ej. `"data.roles.0"` accede a `$arr['data']['roles'][0]`.
3. **Clave ausente → `null`.** Si algún segmento no existe en la estructura, el valor actual resuelto es `null` (no lanza excepción). Esto significa que `expectJson('no.existe', null)` pasa — si quieres verificar "la clave existe y no es null", usa `->expect(...)` con tu propio callable.
4. **El body debe ser JSON válido.** Si `$response->array()` devuelve `null`, el step falla con el mensaje `"Se esperaba JSON en la respuesta pero el body no es JSON válido"`.
5. **No soporta wildcards ni filtros** (`*`, `[?...]`, etc.). Es un acceso por path literal. Para lógica más compleja usa `->expect(callable)`.

#### Ejemplo con múltiples expectaciones JSON

Todas deben cumplirse para que el step pase:

```php
$orch->step('crear_pedido')
     ->url('https://api.example.com/orders')
     ->post()
     ->body(['items' => [...]])
     ->expectStatus(201)
     ->expectJson('success', true)
     ->expectJson('data.order.status', 'pending')
     ->expectJson('data.order.total', 149.99);
```

Si alguna falla, `failureReason` indica exactamente cuál y qué vino en su lugar, por ejemplo:
```
JSON path 'data.order.status' = 'cancelled', se esperaba 'pending'
```

#### Cuándo NO usar `expectJson()`

- Si solo quieres verificar que una clave existe (sin importar el valor), usa `->expect(fn($r) => isset($r->array()['data']['user']['id']))`.
- Si necesitas comparar con tolerancia (ej. floats), rangos (`>= 10`), o expresiones regulares sobre strings, usa `->expect(callable)`.
- Si el campo puede tener varios valores válidos, usa `->expect(fn($r) => in_array($r->array()['status'] ?? null, ['ok', 'pending']))`.

---

### 🔄 Control de flujo (retries y onFail)

```php
$orch->step('payment')
     ->url('https://api.example.com/charge')
     ->post()
     ->body($data)
     ->expectStatus(200)
     ->retries(3, 2000)         // 3 reintentos con 2000ms entre cada uno (4 intentos totales)
     ->onFail('cancel');        // 'cancel' (default), 'continue', '<stepName>', o callable
```

Opciones de `onFail`:

| Valor                       | Comportamiento al fallar el step |
|-----------------------------|-----------------------------------|
| `'cancel'` (default)        | Detiene el flujo y marca el resultado como fallado. |
| `'continue'`                | Sigue con el siguiente step declarado, ignorando el fallo. |
| `'nombreDeStep'`            | Salta a un step específico (útil para re-login / rescue). |
| `callable`                  | Recibe `(StepResult $sr, Orchestrator $orch, Result $partial)` y debe retornar uno de los strings anteriores. |

Ejemplo con recuperación dinámica:

```php
$orch->step('fetchData')
     ->url('https://api.example.com/data')
     ->get()
     ->expectStatus(200)
     ->onFail(function ($stepResult, $orch, $result) {
         if ($stepResult->response?->statuscode() === 401) {
             return 'login';   // token expirado → re-loguea
         }
         return 'cancel';
     });
```

Con `next()` puedes alterar el orden de los steps cuando pasen (aunque normalmente se respeta el orden de declaración):

```php
$orch->step('a')->url('...')->get()->next('c');  // si 'a' pasa, salta 'b' y va a 'c'
$orch->step('b')->url('...')->get();             // se omite cuando 'a' pasa
$orch->step('c')->url('...')->get();
```

---

### 🚫 Steps fuera del flujo natural — `offFlow()`

Para steps de **rescate o recuperación** que NO deben alcanzarse por orden de declaración — solo via `onFail()` o `next()` explícito — márcalos con `offFlow()`. Si no usas este flag y el step tiene un `next()`, puedes caer en un **loop infinito** cuando el flujo natural lo alcance (el step rescate reenvía perpetuamente al paso original).

```php
$orch->step('login')->...->onFail('reLogin');   // si login falla, usa reLogin
$orch->step('fetch')->...;                      // camino feliz

$orch->step('reLogin')                           // ← solo via onFail, nunca natural
     ->offFlow()
     ->url('https://site.com/api/refresh')
     ->post()
     ->body(['refresh_token' => $token])
     ->expectStatus(200)
     ->next('fetch');                            // tras rescatar, retoma fetch
```

Sin `offFlow()`, el orquestador correría `login → fetch → reLogin → fetch → reLogin → ...` ad infinitum. Con `offFlow()`, `reLogin` es invisible al orden de declaración.

El límite defensivo de transiciones del orquestador (`stepCount × 50`) detendría eventualmente ese ciclo con una `RuntimeException`, pero `offFlow()` es la forma correcta de expresarlo.

---

### 🧼 Aislamiento por step — `fresh()`

Si un step necesita una instancia **completamente nueva** de Scurl (por ejemplo: una llamada a un servicio distinto que no debe compartir cookies ni headers con el flujo principal), marcalo con `fresh()`. El Scurl principal **no se toca** y se retoma en el siguiente step.

```php
$orch->step('datos_usuario')       // usa Scurl principal (con sesión)
     ->url('https://site.com/api/me')->get();

$orch->step('servicio_externo')    // ← Scurl fresco e independiente
     ->fresh()
     ->url('https://otro.host/ping')->get();

$orch->step('datos_pedidos')       // vuelve al Scurl principal, sesión intacta
     ->url('https://site.com/api/orders')->get();
```

Si necesitas propagar algo de la instancia fresca hacia la principal (por ejemplo un token), usa el hook `afterSend()`.

---

### 🪝 Hooks: `beforeSend` y `afterSend`

```php
$orch->step('subir_archivo')
     ->url('https://api.example.com/upload')
     ->post()
     ->beforeSend(function ($scurl, $partialResult) {
         // Justo antes de send(): puedes inspeccionar/ajustar el Scurl
         $scurl->headers(['X-Trace-Id' => uniqid('trc_')]);
     })
     ->afterSend(function ($response, $scurl, $partialResult) {
         // Justo después de send(), antes de validar expectations
         if ($token = $response->getHeader('x-new-token')) {
             // Propaga valor al flujo principal
             $partialResult->lastStepResult(); // acceso al histórico
         }
     });
```

---

### 🧮 Configuración dinámica — `request(callable)`

Cuando un step necesita leer datos del Response de un paso previo (un token, un id, una cookie), usa `request()` en lugar de los métodos fluidos encolados:

```php
$orch->step('fetch')
     ->request(function ($scurl, $result) {
         $token = $result->response('login')->getCookie('accessToken');
         $scurl->headers(['Authorization' => 'Bearer ' . $token]);
     })
     ->url('https://api.example.com/data')
     ->get()
     ->expectStatus(200);
```

Este mismo patrón es el recomendado para uploads multipart, donde `$curl->getUploadFile()` solo está disponible después de que se aplicó `upload()`:

```php
$orch->step('subir')
     ->request(fn($scurl) => $scurl
         ->upload('/ruta/archivo.pdf')
         ->body([
             'descripcion' => 'Factura',
             'archivo'     => $scurl->getUploadFile(),
         ])
     )
     ->url('https://api.example.com/upload')
     ->post()
     ->expectStatus(201);
```

---

### 📊 Lectura del resultado

`run()` retorna un `Result`:

```php
$result = $orch->run();

$result->isSuccess();          // true si el flujo terminó sin cancelarse
$result->isCancelled();        // true si algún step falló con onFail='cancel'
$result->failedAt();           // nombre del step que causó la cancelación (o null)

$result->response('login');    // Response del step 'login' (o null)
$result->lastResponse();       // Response del último step ejecutado
$result->lastStepResult();     // StepResult completo del último paso

$result->get('login');         // StepResult del step 'login'
$result->steps();              // array<string, StepResult> de todos los steps
$result->executionOrder();     // ['login', 'fetch', ...] en orden real de ejecución
```

Cada `StepResult` contiene:

```php
$sr = $result->get('login');
$sr->name;                // string — nombre del step
$sr->passed;              // bool — si cumplió todas las expectations
$sr->attempts;            // int — intentos realizados (1 si no hubo retry)
$sr->response;            // ?Response — respuesta obtenida
$sr->failureReason;       // ?string — por qué falló (ej. "Status 404 no está en [200]")
$sr->exception;           // ?Throwable — excepción capturada durante send
$sr->usedFreshInstance;   // bool — true si usó ->fresh()
```

> 💡 **Importante sobre `isSuccess()`:** el flujo se considera exitoso si terminó sin cancelarse. Un step que falló pero fue recuperado via `onFail('otroStep')` **no** hace que `isSuccess()` retorne `false`. Si quieres saber si **todos** los steps pasaron individualmente, recorre `$result->steps()`:
> ```php
> $todosOK = array_reduce($result->steps(), fn($ok, $s) => $ok && $s->passed, true);
> ```

---

### 🧩 Hooks globales (opcionales)

Para logs o métricas sin ensuciar cada step:

```php
$orch->onStepSuccess(function ($stepResult, $orch, $result) {
         Log::info("Step OK: {$stepResult->name} ({$stepResult->attempts} intentos)");
     })
     ->onStepFailure(function ($stepResult, $orch, $result) {
         Log::warning("Step FAIL: {$stepResult->name} — {$stepResult->failureReason}");
     });
```

---

### 🐛 Debugging del flujo

Con una sola llamada `$curl->send()` podías hacer `print_r($response)` y listo. Con el Orchestrator el flujo ejecuta varios pasos y cada `Response` queda guardado dentro del `Result`, así que tienes **más** puntos de inspección — no menos. Estas son las herramientas que tienes disponibles, de menor a mayor invasividad.

#### 1. Post-ejecución: inspeccionar desde el `Result`

Después de `run()`, el `Result` expone el `Response` de cada step por nombre. Es el reemplazo directo de tu viejo `print_r($response)`:

```php
$result = $orch->run();

// Response completo de un step (equivalente a print_r($response) del Scurl plano)
print_r($result->response('actualizar_perfil'));

// Solo el body crudo
echo $result->response('actualizar_perfil')->body();

// Body decodificado si era JSON (usa array(), no json() que está deprecado)
print_r($result->response('actualizar_perfil')->array());

// Status code
echo $result->response('actualizar_perfil')->statuscode();

// Último response ejecutado, sin tener que recordar el nombre
print_r($result->lastResponse());

// Orden REAL de ejecución (incluyendo saltos por onFail/next)
print_r($result->executionOrder());  // ['login', 'perfil', 'actualizar_perfil', ...]
```

Metadata del step (útil cuando algo falló y el response por sí solo no basta):

```php
$sr = $result->get('actualizar_perfil');

dump([
    'passed'    => $sr->passed,              // bool — si cumplió las expectations
    'attempts'  => $sr->attempts,            // int — reintentos realizados (1 = primera corrió y pasó)
    'reason'    => $sr->failureReason,       // ?string — ej. "JSON path 'data.id' = null, se esperaba 42"
    'exception' => $sr->exception?->getMessage(),
    'fresh'     => $sr->usedFreshInstance,   // bool — si usó ->fresh()
]);
```

#### 2. Mid-flight: `afterSend()` para ver un response en caliente

Cuando necesitas ver la respuesta **antes** de que el siguiente step la consuma (típicamente para entender por qué un `expectJson` falla), mete un `afterSend()` en ese step:

```php
$orch->step('actualizar_perfil')
     ->url('https://httpbin.org/put')
     ->put()
     ->body('{"nombre":"user"}')
     ->afterSend(function ($response, $scurl, $result) {
         // Se ejecuta apenas llega el response, antes de validar expectations
         dump($response->statuscode());
         dump($response->array());
     })
     ->expectStatus(200)
     ->expectJson('json.nombre', 'user');
```

`beforeSend()` es su contraparte para verificar cómo quedó configurado el Scurl **antes** del `send()` — headers, url, cookies, etc.:

```php
->beforeSend(function ($scurl, $result) {
    dump($scurl->getUrl(), $scurl->getMethod(), $scurl->getHeaders());
})
```

#### 3. Global: hooks del orquestador como log de ejecución

Cuando quieres ver **todos** los steps pasar sin ensuciar cada uno, usa los hooks del orquestador. Ideal durante el desarrollo:

```php
$orch
    ->onStepSuccess(function ($sr) {
        $status = $sr->response?->statuscode() ?? '—';
        echo "✔ {$sr->name} status={$status} intentos={$sr->attempts}\n";
        dump($sr->response?->array());
    })
    ->onStepFailure(function ($sr) {
        echo "✘ {$sr->name} — {$sr->failureReason}\n";
        if ($sr->response)  dump($sr->response->body());
        if ($sr->exception) dump($sr->exception->getMessage());
    });
```

#### 4. `dd()` para cortar el flujo en un punto exacto

Si quieres el comportamiento clásico de "pegar un `dd()` y parar todo", hazlo dentro de un `afterSend()`:

```php
->afterSend(fn($response) => dd($response->array()))
```

`dd()` lanza y mata el proceso. Si prefieres inspeccionar sin matar pero sí cancelar el step y detener el flujo, usa `dump()` + un `->expect(fn() => false)` temporal.

#### 5. Tabla de referencia rápida

| Quiero ver... | Dónde |
|---|---|
| Response final de un step | `$result->response('nombre')` |
| Último response ejecutado | `$result->lastResponse()` |
| Body decodificado | `$result->response('nombre')->array()` |
| Status code | `$result->response('nombre')->statuscode()` |
| Por qué falló un step | `$result->get('nombre')->failureReason` |
| Excepción capturada | `$result->get('nombre')->exception` |
| Intentos realizados | `$result->get('nombre')->attempts` |
| Orden real de ejecución (con saltos) | `$result->executionOrder()` |
| Response en vivo durante el flujo | `->afterSend(fn($r) => dump($r))` en el step |
| Config del Scurl antes del `send()` | `->beforeSend(fn($s) => dump($s->getHeaders()))` |
| Trace de todos los steps como van | `$orch->onStepSuccess(...)->onStepFailure(...)` |
| Cortar en un punto | `->afterSend(fn($r) => dd($r))` |

> 💡 **Regla mnemotécnica:** `$result->response('step')` es tu nuevo `$response`. Para lo que ocurre **entre** steps, `afterSend` en el step. Para el panorama general, los hooks globales del orquestador.

---

### 🎬 Ejemplo completo — login + datos + recuperación

```php
$orch = new Orchestrator();
$orch->getScurl()
     ->config(['exceptions' => false])
     ->cookie()
     ->useragent('MyScraper/1.0')
     ->timeout(20);

// 1. Login
$orch->step('login')
     ->url('https://site.com/api/login')
     ->post()
     ->body(['email' => 'user@x.com', 'password' => 'secret'])
     ->expectStatus(200)
     ->expectJson('success', true)
     ->retries(2, 1500)
     ->onFail('cancel');

// 2. Consumo autenticado (las cookies del login viajan automáticamente)
$orch->step('orders')
     ->url('https://site.com/api/orders')
     ->get()
     ->expectStatus([200, 204])
     ->onFail(function ($sr) {
         // Si expiró el token, reintentamos login y después este mismo step
         return $sr->response?->statuscode() === 401 ? 'login' : 'cancel';
     });

// 3. Servicio externo aislado
$orch->step('geoip')
     ->fresh()
     ->url('https://api.ipify.org?format=json')
     ->get()
     ->expectStatus(200);

$result = $orch->run();

if ($result->isSuccess()) {
    $orders = $result->response('orders')->array();
    $ip     = $result->response('geoip')->array()['ip'];
} else {
    Log::error("Flujo cancelado en step '{$result->failedAt()}'");
}
```

---

## 📄 Licencia

Este proyecto está licenciado bajo la licencia **MIT**.
