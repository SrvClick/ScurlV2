---
name: scurl-v2
description: Modern PHP 8.0+ HTTP client built on cURL with fluent API, reusable instances, and Laravel integration patterns.
---

## What is Scurl?

**Scurl** is a modern PHP 8.0+ HTTP client built on top of cURL, with a fluent (chainable) API. It wraps three classes:

```
Scurl          ← Public fluent facade. All user-facing methods live here.
  └── Request  ← Manages cURL options, headers, cookies, body, method, timeout.
        └── Response  ← Wraps the HTTP response: body, status, headers, JSON, cookies.
```

**Key design principle:** A single `Scurl` instance is meant to be **reused** across multiple requests. Global config (headers, cookies, proxy, timeout) **persists** between calls. Per-request data (body, upload file, Content-Type) is **reset automatically** after each `send()`.

---

## Installation

```bash
composer require srvclick/scurlv2
```

Requires PHP 8.0+ and the `ext-curl` extension enabled.

---

## Lifecycle of a Request

Understanding this flow prevents most bugs:

```
new Scurl()
    ↓
Set PERSISTENT config (headers, cookie, proxy, timeout, useragent)
    ↓
[Loop of requests:]
    →  url()  → method()  → body()/upload()  → send()
                                                  ↓
                                            returns Response
                                                  ↓
                                          reset() is called internally
                                          (clears: parameters, uploadFile, Content-Type header)
                                          (keeps: headers, cookies, proxy, timeout, options)
```

---

## Laravel Integration Patterns

### Register as a Singleton (Recommended)

In `AppServiceProvider` or a dedicated `ScurlServiceProvider`:

```php
// app/Providers/AppServiceProvider.php
use SrvClick\Scurlv2\Scurl;

public function register(): void
{
    $this->app->singleton(Scurl::class, function () {
        $curl = new Scurl();
        $curl->config([
                'exceptions' => true,   // throw Exception on non-2xx
                'auto_json'  => true,   // auto Content-Type: application/json for JSON bodies
            ])
            ->timeout(15)
            ->useragent('MyApp/1.0')
            ->headers([
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . config('services.myapi.token'),
            ]);
        return $curl;
    });
}
```

### Inject into a Service

```php
use SrvClick\Scurlv2\Scurl;

class PaymentService
{
    public function __construct(protected Scurl $curl) {}

    public function charge(array $data): array
    {
        $response = $this->curl
            ->url(config('services.payment.endpoint') . '/charge')
            ->post()
            ->body($data)
            ->send();

        return $response->json();
    }
}
```

### Use directly in a Controller (simple cases)

```php
use SrvClick\Scurlv2\Scurl;

class WebhookController extends Controller
{
    public function forward(Request $request): JsonResponse
    {
        $curl = new Scurl();
        $response = $curl->url('https://external.api/webhook')
                         ->post()
                         ->headers(['X-Secret' => config('services.webhook.secret')])
                         ->body($request->all())
                         ->send();

        return response()->json(['status' => $response->statuscode()]);
    }
}
```

### Use inside a Laravel Job

```php
use SrvClick\Scurlv2\Scurl;
use Exception;

class SyncOrderJob implements ShouldQueue
{
    public function handle(): void
    {
        $curl = new Scurl();
        $curl->config(['exceptions' => true])->timeout(30);

        try {
            $response = $curl->url('https://erp.example.com/orders')
                             ->post()
                             ->body($this->payload)
                             ->send();

            Log::info('Order synced', ['status' => $response->statuscode()]);
        } catch (Exception $e) {
            Log::error('Sync failed', ['error' => $e->getMessage()]);
            throw $e; // Let Laravel retry the job
        }
    }
}
```

---

## Complete API Reference

### Instantiation

```php
$curl = new Scurl();  // Always start fresh with new Scurl()
```

---

### URL

```php
$curl->url('https://api.example.com/endpoint');
$curl->target('https://api.example.com/endpoint');  // Alias for url()
```

---

### HTTP Methods

```php
$curl->get();             // GET (default)
$curl->post();            // POST
$curl->put();             // PUT
$curl->delete();          // DELETE
$curl->patch();           // PATCH
$curl->head();            // HEAD
$curl->options_method();  // OPTIONS  ← NOTE: not options(), that sets cURL options
$curl->method('CUSTOM');  // Any arbitrary method string
```

---

### Request Body / Parameters

```php
// Array → sent as multipart/form-data on POST, or query string on GET
$curl->body(['key' => 'value', 'foo' => 'bar']);

// JSON string → auto-detected, auto-sets Content-Type: application/json (if auto_json=true)
$curl->body('{"key":"value"}');

// Form-encoded string → parsed via parse_str(), sent as form data
$curl->body('key=value&foo=bar');

// Explicit JSON header (use when you want to force it regardless of body format)
$curl->json();

// body() is an alias for parameters()
$curl->parameters(['key' => 'value']);
```

**Body detection logic (from source code):**
1. If input is an `array` → stored as array, sent as form data or JSON depending on headers.
2. If input is a string starting with `{` or `[` AND passes `json_validate()` → treated as JSON string, `Content-Type: application/json` auto-added if `auto_json=true`.
3. Otherwise string → `parse_str()` is applied (form-encoded).

---

### Headers

```php
// Associative array (key replaces if same name, case-insensitive)
$curl->headers([
    'Authorization'  => 'Bearer TOKEN',
    'Content-Type'   => 'application/json',
    'user-agent'     => 'MyApp/1.0',  // Replaces default "SrvClick Scurl/2.0"
    'X-Custom'       => 'value',
]);

// Or string format
$curl->headers([
    'Authorization: Bearer TOKEN',
    'X-Custom: value',
]);
```

**Header merge rules (from source code):**
- Keys are normalized to lowercase for deduplication comparison.
- Original capitalization is preserved when actually sending.
- Calling `headers()` multiple times **merges** (never replaces all headers).
- The default `user-agent: SrvClick Scurl/2.0` is replaced if you pass `user-agent` or `User-Agent`.
- Headers **persist across requests** (only Content-Type added by JSON body is reset).

```php
// Read a request header (case-insensitive)
$curl->getHeader('Authorization'); // → "Bearer TOKEN" or null

// Convenience shortcuts
$curl->useragent('MyApp/1.0');   // Sets User-Agent header
$curl->getUserAgent();            // Gets current User-Agent string
$curl->json();                    // Adds Content-Type: application/json
```

---

### Timeout & Config

```php
$curl->timeout(30);  // Seconds. Default: 30

$curl->config([
    'exceptions' => true,   // Throw Exception on non-2xx (or non-accepted) status. Default: false
    'auto_json'  => true,   // Auto-add Content-Type: application/json when body is a JSON string. Default: true
]);
```

---

### Error Handling

```php
// Option 1: Manual check (exceptions disabled by default)
$response = $curl->url('...')->get()->send();
if (! $response->isOk()) {
    // Handle HTTP error
    Log::error("HTTP Error {$response->statuscode()}: {$response->body()}");
}

// Option 2: Exceptions (enable in config)
$curl->config(['exceptions' => true]);
try {
    $response = $curl->url('...')->get()->send();
} catch (\Exception $e) {
    // Exception message: "HTTP Error: 404 - Not Found"
    Log::error($e->getMessage());
}

// Accept additional status groups as valid (won't throw exception for these)
$curl->acceptStatus(400);  // Accept 4xx as valid
$curl->acceptStatus(500);  // Accept 5xx as valid
// Valid groups: 100, 200, 300, 400, 500
```

---

### Raw cURL Options

Direct access to any cURL constant:

```php
$curl->options([
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_ENCODING       => '',        // Accept gzip/deflate
    CURLOPT_SSL_VERIFYPEER => true,      // Enable SSL verification (disabled by default)
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_HEADER         => true,      // Enable response header capture
    CURLOPT_COOKIEJAR      => '/tmp/c.txt',
    CURLOPT_COOKIEFILE     => '/tmp/c.txt',
]);

// Read current options (returns human-readable CURLOPT_ names as keys)
$curl->getOptions();
```

**Default cURL options set internally:**

| Option | Default |
|--------|---------|
| `CURLOPT_RETURNTRANSFER` | `true` |
| `CURLOPT_FOLLOWLOCATION` | `true` |
| `CURLOPT_TIMEOUT` | `30` |
| `CURLOPT_SSL_VERIFYPEER` | `false` |
| `CURLOPT_SSL_VERIFYHOST` | `false` |
| `CURLOPT_HTTPHEADER` | `['user-agent: SrvClick Scurl/2.0']` |

---

### Response Headers

Response headers are **only captured** when `CURLOPT_HEADER => true` is set:

```php
$curl->options([CURLOPT_HEADER => true]);
$response = $curl->url('https://api.example.com')->get()->send();

$response->headers();                         // All headers as assoc array (lowercase keys)
$response->getHeader('content-type');         // Single header by name (case-insensitive)
$response->getHeader('x-rate-limit', '0');    // With default fallback
```

---

### Cookies

```php
// Enable cookie storage (temp file, auto-created)
$curl->cookie();

// Use a specific file (optional - auto-creates temp file if not provided)
$curl->cookieFile('/tmp/my_session.txt');
$curl->cookieFile();  // Also works - auto-creates temp file

// Manual cookie management (requires cookieFile to be set first)
$curl->addCookie('session', 'abc123', 'example.com');
$curl->addCookie('token', 'xyz', 'example.com', '/', true, time() + 3600);
//               name,   value,   domain,         path, secure, expires (unix timestamp)

$curl->replaceCookie('session', 'newvalue', 'example.com');
// replaceCookie = deleteCookieCompletely + addCookie in one call

$curl->deleteCookie('session', 'example.com');   // Remove from specific domain
$curl->deleteCookie('session');                  // Remove from all domains
$curl->deleteCookieCompletely('session');         // Remove ALL entries for name (ignores comments/header lines)

// Get cookie from server response
$response->getCookie('session_id');              // From Set-Cookie response header
$response->getCookie('token', 'default');        // With default
```

**Cookie file format:** Netscape/Mozilla format (tab-separated). Functions parse/write directly from the file. `addCookie` appends. `deleteCookie` filters by name+domain. `deleteCookieCompletely` filters by name across all entries including commented lines.

---

### Proxy

```php
// String format (recommended — parses scheme, host, port, credentials)
$curl->proxy('http://proxy.example.com:8080');
$curl->proxy('http://user:pass@proxy.example.com:8080');
$curl->proxy('socks5://proxy.example.com:1080');

// Array format: [host, port, user (optional), pass (optional)]
$curl->proxy(['proxy.example.com', 8080]);
$curl->proxy(['proxy.example.com', 8080, 'user', 'pass']);
```

---

### File Upload

```php
// 1. Register the file (throws InvalidArgumentException if not found)
$curl->upload('/absolute/path/to/file.pdf');

// 2. Include in body alongside other fields
$curl->body([
    'description' => 'Invoice',
    'file'        => $curl->getUploadFile(),  // Returns CURLFile object
])
->post()
->send();

// getUploadFile() returns null if upload() was not called
$curl->getUploadFile(); // → CURLFile|null
```

---

### Sending the Request

```php
$response = $curl->url('...')
                 ->post()
                 ->body([...])
                 ->send();  // Returns Response object. Calls reset() internally after execution.
```

---

## Response Object — Complete Reference

```php
$response->body();              // Raw response body as string
$response->json();              // Decoded JSON as array, or null if not JSON
$response->isJson();            // true if body is valid JSON
$response->statuscode();        // HTTP status code as int (e.g. 200, 404)
$response->isOk();              // true if status is 200–299

// Headers (only populated when CURLOPT_HEADER => true)
$response->headers();                        // All response headers (lowercase keys)
$response->getHeader('content-type');        // Case-insensitive lookup
$response->getHeader('x-token', 'default'); // With fallback default

// Cookies set by server (from Set-Cookie response header, requires CURLOPT_HEADER)
$response->getCookie('session_id');          // Cookie value or null
$response->getCookie('session_id', '');      // With fallback default

// Cookie jar file path (if cookieFile was used)
$response->getCookieFileName();              // string path, or '' if none
```

---

## reset() — What Clears, What Persists

Called automatically after every `send()`. Understanding this is critical.

| | Clears After send() | Persists Between Requests |
|---|---|---|
| `body()` / `parameters()` | ✅ | |
| `upload()` file | ✅ | |
| `Content-Type: application/json` header | ✅ | |
| `headers()` (custom headers) | | ✅ |
| `useragent()` | | ✅ |
| `cookie()` / `cookieFile()` | | ✅ |
| `proxy()` | | ✅ |
| `timeout()` | | ✅ |
| `config()` | | ✅ |
| `options()` (cURL options) | | ✅ |
| `acceptStatus()` groups | | ✅ |

---

## Common Laravel Patterns

### API Client Service (recommended pattern)

```php
// app/Services/ExternalApiService.php
namespace App\Services;

use SrvClick\Scurlv2\Scurl;
use SrvClick\Scurlv2\Response;
use Exception;

class ExternalApiService
{
    protected Scurl $curl;

    public function __construct()
    {
        $this->curl = new Scurl();
        $this->curl
            ->config(['exceptions' => true, 'auto_json' => true])
            ->timeout((int) config('services.externalapi.timeout', 15))
            ->useragent('MyApp/' . config('app.version', '1.0'))
            ->headers([
                'Authorization' => 'Bearer ' . config('services.externalapi.key'),
                'Accept'        => 'application/json',
            ]);
    }

    public function getUser(int $id): ?array
    {
        $response = $this->curl
            ->url(config('services.externalapi.url') . "/users/{$id}")
            ->get()
            ->send();
        return $response->json();
    }

    public function createOrder(array $data): array
    {
        $response = $this->curl
            ->url(config('services.externalapi.url') . '/orders')
            ->post()
            ->body($data)      // array → form data, or use body(json_encode($data)) for JSON
            ->send();
        return $response->json() ?? [];
    }

    public function updateOrder(int $id, array $data): bool
    {
        try {
            $this->curl
                ->url(config('services.externalapi.url') . "/orders/{$id}")
                ->put()
                ->body(json_encode($data))  // JSON string: auto-adds Content-Type
                ->send();
            return true;
        } catch (Exception $e) {
            Log::error('Order update failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
```

### Login Flow with Cookie Session

```php
$curl = new Scurl();
$curl->cookieFile(storage_path('app/sessions/user_session.txt'))
     ->timeout(20);

// Step 1: Login (server sets session cookie)
$loginResponse = $curl
    ->url('https://site.com/login')
    ->post()
    ->body(['username' => 'user', 'password' => 'pass'])
    ->send();

if (! $loginResponse->isOk()) {
    throw new \Exception('Login failed: ' . $loginResponse->statuscode());
}

// Step 2: Authenticated request (cookies sent automatically)
$dataResponse = $curl
    ->url('https://site.com/api/data')
    ->get()
    ->send();

// Step 3: Manipulate cookies manually
$curl->replaceCookie('pref', 'dark_mode', 'site.com');

// Step 4: Logout
$curl->url('https://site.com/logout')->post()->send();
```

### Scraping with Response Headers

```php
$curl = new Scurl();
$curl->options([CURLOPT_HEADER => true])  // Must enable to capture response headers
     ->useragent('Mozilla/5.0 (compatible)');

$response = $curl
    ->url('https://api.example.com/resource')
    ->get()
    ->send();

$contentType  = $response->getHeader('content-type');
$rateLimit    = $response->getHeader('x-ratelimit-remaining', 'unknown');
$redirectedTo = $response->getHeader('location');
```

### File Upload to API

```php
$curl = new Scurl();
$curl->headers(['Authorization' => 'Bearer ' . config('services.storage.key')]);

$response = $curl
    ->url('https://storage.api.com/upload')
    ->upload(storage_path('app/exports/report.pdf'))
    ->body([
        'folder'      => 'reports',
        'description' => 'Monthly report',
        'file'        => $curl->getUploadFile(),
    ])
    ->post()
    ->send();

if ($response->isOk()) {
    $uploadedUrl = $response->json()['url'] ?? null;
}
```

### Using Proxy (with env config)

```php
$curl = new Scurl();

if (config('services.proxy.enabled')) {
    $curl->proxy(
        sprintf(
            'http://%s:%s@%s:%d',
            config('services.proxy.user'),
            config('services.proxy.pass'),
            config('services.proxy.host'),
            config('services.proxy.port'),
        )
    );
}

$response = $curl->url('https://geo-restricted.api.com/data')->get()->send();
```

### Multiple Sequential Requests (reusing instance)

```php
$curl = new Scurl();
$curl->config(['exceptions' => true])
     ->cookie()                     // Session cookies persist across all requests
     ->headers([
         'Accept'        => 'application/json',
         'Authorization' => 'Bearer ' . $token,
     ])
     ->timeout(15);

// GET — no body
$profile = $curl->url('https://api.example.com/me')->get()->send()->json();

// POST — JSON body (auto Content-Type applied, then reset after send)
$order = $curl
    ->url('https://api.example.com/orders')
    ->post()
    ->body(json_encode(['product_id' => 42, 'qty' => 2]))
    ->send()
    ->json();

// PUT — body is reset from previous send, safe to reuse
$updated = $curl
    ->url('https://api.example.com/orders/' . $order['id'])
    ->put()
    ->body(['status' => 'confirmed'])
    ->acceptStatus(400)    // Don't throw if validation error
    ->send();

if ($updated->isOk()) {
    // success
}
```

---

## Critical Gotchas

### 1. Cookie methods require a cookie file set first
Calling `addCookie()`, `deleteCookie()`, etc. **before** `cookie()` or `cookieFile()` silently fails:
```php
$curl->cookieFile('/tmp/session.txt');     // Must be first
$curl->addCookie('token', 'abc', 'x.com'); // Now safe
```

### 3. `upload()` path must be absolute
`upload()` calls `is_file($path)` — relative paths may fail depending on PHP working directory. Use Laravel's `storage_path()` or `base_path()`.

### 4. `body()` with array on GET has no effect on URL
Scurl does NOT append array parameters as query string for GET requests. For GET with query params, build the URL manually:
```php
$url = 'https://api.example.com/search?' . http_build_query(['q' => 'test', 'page' => 2]);
$curl->url($url)->get()->send();
```

### 5. `options_method()` vs `options()`
- `options_method()` → sets HTTP method to OPTIONS
- `options([...])` → sets raw cURL options
  These are two completely different methods.

### 6. SSL verification is disabled by default
`CURLOPT_SSL_VERIFYPEER` and `CURLOPT_SSL_VERIFYHOST` are `false` by default. Enable for production:
```php
$curl->options([
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
```

### 7. `config()` merges, not replaces
```php
$curl->config(['exceptions' => true]);
$curl->config(['auto_json' => false]);
// Result: ['exceptions' => true, 'auto_json' => false]  ← both applied
```

### 8. `acceptStatus()` only matters when `exceptions => true`
If exceptions are disabled (default), `acceptStatus()` does nothing visible — `isOk()` still only returns `true` for 2xx.

---

## Debugging Tips

```php
// See all active cURL options (human-readable)
dd($curl->getOptions());

// Check current URL and method
echo $curl->getUrl();
echo $curl->getMethod();

// Check User-Agent
echo $curl->getUserAgent();

// Check upload file
var_dump($curl->getUploadFile());  // CURLFile or null

// Check if last request was successful
echo $curl->isOK() ? 'OK' : 'FAILED';

// Full response dump
$response = $curl->url('...')->get()->send();
dd([
    'status'  => $response->statuscode(),
    'ok'      => $response->isOk(),
    'isJson'  => $response->isJson(),
    'body'    => $response->body(),
    'json'    => $response->json(),
    'headers' => $response->headers(),
]);
```

---

## Summary Table

| Task | Code |
|------|------|
| Basic GET | `$curl->url($url)->get()->send()` |
| POST JSON | `$curl->url($url)->post()->body(json_encode($data))->send()` |
| POST form | `$curl->url($url)->post()->body($array)->send()` |
| Custom headers | `$curl->headers(['X-Key' => 'val'])` |
| Auth header | `$curl->headers(['Authorization' => 'Bearer '.$token])` |
| With cookies | `$curl->cookie()->url($url)->get()->send()` |
| File upload | `$curl->upload($path)->body(['f' => $curl->getUploadFile()])->post()->send()` |
| Proxy | `$curl->proxy('http://user:pass@host:port')` |
| Throw on error | `$curl->config(['exceptions' => true])` |
| Accept 4xx | `$curl->acceptStatus(400)` |
| Response body | `$response->body()` |
| Response JSON | `$response->json()` |
| Response status | `$response->statuscode()` |
| Check success | `$response->isOk()` |
| Response header | `$response->getHeader('content-type')` (needs CURLOPT_HEADER) |
| Response cookie | `$response->getCookie('name')` (needs CURLOPT_HEADER) |