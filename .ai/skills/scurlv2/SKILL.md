---
name: scurl-v2
description: Modern PHP 8.3+ HTTP client built on cURL with fluent API, reusable instances, and Laravel integration patterns.
---

## What is Scurl?

**Scurl** is a modern PHP 8.3+ HTTP client built on top of cURL, with a fluent (chainable) API. It wraps three classes:

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

Requires PHP 8.3+ and the `ext-curl` extension enabled.

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
$curl->useragent('MyApp/1.0');   // ⚠️ Highest priority - always overrides any other User-Agent (even from headers())
$curl->getUserAgent();            // Gets current User-Agent string
$curl->json();                    // Adds Content-Type: application/json
```

**User-Agent priority order (highest to lowest):**
1. `useragent('...')` - Always wins, removes any existing User-Agent header
2. Custom headers via `headers(['user-agent' => '...'])` - Replaces default
3. Default: `SrvClick Scurl/2.0`

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

**Exception types preserved**: `Scurl::send()` does NOT wrap inner exceptions. Original class, stack trace, and `$previous` chain bubble up intact.

```php
try {
    $curl->upload('/missing.pdf')->post()->send();
} catch (InvalidArgumentException $e) {
    // Caught here — file-not-found bubbles as its original class
}
```

| Exception class | Thrown when |
|---|---|
| `InvalidArgumentException` | upload file doesn't exist, malformed proxy string, invalid status group in `acceptStatus()` |
| `\Exception` | HTTP status not accepted AND `config(['exceptions' => true])` |

---

### Raw cURL Options

Direct access to any cURL constant:

```php
$curl->options([
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_ENCODING       => '',        // Accept gzip/deflate
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
| `CURLOPT_SSL_VERIFYPEER` | `true` |
| `CURLOPT_SSL_VERIFYHOST` | `2` |
| `CURLOPT_HTTPHEADER` | `['user-agent: SrvClick Scurl/2.0']` |

### SSL Verification — `insecure()`

SSL certificate verification is **enabled by default** (`CURLOPT_SSL_VERIFYPEER=true`, `CURLOPT_SSL_VERIFYHOST=2`). For self-signed certificates or local development, use the explicit opt-out:

```php
$curl->url('https://self-signed.dev/api')
     ->insecure()        // Disables SSL verification for this instance
     ->get()
     ->send();
```

**Rules:**
- `insecure()` **persists across `reset()`** — once disabled, all subsequent requests on the same Scurl instance skip verification.
- Re-enable verification in the same instance: `$curl->insecure(false)`.
- Never use in production against real servers — leaves connections open to MITM.

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
$response->array();             // Decoded JSON as array, or null if not JSON (PREFERRED)
$response->json();              // @deprecated — alias of array(). Will be removed.
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

### Dot-notation JSON Access

Response exposes dot-notation helpers to read/validate JSON fields without manually decoding:

```php
// Given body: {"success": true, "data": {"user": {"id": 42, "name": "Joel"}}}

// 1) Read a value (with optional default)
$response->get('data.user.id');               // 42
$response->get('data.user.name');             // 'Joel'
$response->get('no.existe', 'fallback');      // 'fallback'
$response->get('data.roles.0');               // numeric indices work too

// 2) Check if a path exists (distinguishes "missing" from "null value")
$response->has('data.user.id');               // true
$response->has('data.user.missing');          // false

// 3) Strict equality check (===)
$response->expectJson('success', true);       // true
$response->expectJson('data.user.id', 42);    // true
$response->expectJson('data.user.id', '42');  // false (int !== string)

// 4) Invokable shortcut
$response('data.user.id');                    // 1 arg  → get()
$response('data.user.id', 42);                // 2 args → expectJson()
```

**Rules (same semantics as Orchestrator's `Step::expectJson`):**
- Strict `===` comparison (no casts).
- Missing keys resolve to `null` in `get()` / `expectJson()`. Use `has()` to distinguish.
- Numeric indices are supported: `'data.roles.0'` → `$arr['data']['roles'][0]`.
- Non-JSON body: `get()` returns default, `has()` returns `false`, `expectJson()` returns `false` (unless you compare against `null`).
- No wildcards or filters — path must be literal.

**Idiomatic usage in conditionals:**

```php
if ($response->isOk() && $response('success', true)) {
    $userId = $response->get('data.user.id');
    // ...
}
```

**The invokable uses `func_num_args()`** to distinguish "no second arg" (→ `get()`) from "second arg is null" (→ `expectJson(..., null)`), so you can still validate explicitly for null:

```php
$response('data.error', null);   // true if data.error is exactly null (or missing)
```

---

## reset() — What Clears, What Persists

Called automatically after every `send()`. Understanding this is critical.

| | Clears After send() | Persists Between Requests |
|---|---|---|
| `body()` / `parameters()` | ✅ | |
| `upload()` file | ✅ | |
| `Content-Type: application/json` header | ✅ | |
| `CURLOPT_HEADER` / `CURLOPT_HEADERFUNCTION` (`getHeaders()`) | ✅ | |
| `headers()` (custom headers) | | ✅ |
| `useragent()` | | ✅ |
| `cookie()` / `cookieFile()` | | ✅ |
| `proxy()` | | ✅ |
| `timeout()` | | ✅ |
| `config()` | | ✅ |
| `options()` (cURL options) | | ✅ |
| `acceptStatus()` groups | | ✅ |
| `insecure()` (SSL verification) | | ✅ |

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

### 6. SSL verification is enabled by default
`CURLOPT_SSL_VERIFYPEER=true` and `CURLOPT_SSL_VERIFYHOST=2` are the defaults. To disable (e.g. for self-signed certs in development), use the explicit fluent method:
```php
$curl->url('https://self-signed.dev')->insecure()->get()->send();
```
The `insecure()` flag persists across requests on the same instance (`reset()` does not revert it). Pass `false` to re-enable: `$curl->insecure(false)`.

### 7. `config()` merges, not replaces
```php
$curl->config(['exceptions' => true]);
$curl->config(['auto_json' => false]);
// Result: ['exceptions' => true, 'auto_json' => false]  ← both applied
```

### 8. `acceptStatus()` only matters when `exceptions => true`
If exceptions are disabled (default), `acceptStatus()` does nothing visible — `isOk()` still only returns `true` for 2xx.

---

## Orchestrator — Multi-Step Flows

For scraping, automation, or any workflow that chains **multiple dependent HTTP requests** (where step N's response controls whether step N+1 runs), Scurl ships with an orchestrator in `SrvClick\Scurlv2\Orchestrator`.

### When to reach for the Orchestrator (vs. writing procedural code)

Prefer the Orchestrator when **any** of these apply:

- Multiple requests must run in a specific order, and a failure should halt the rest.
- You want declarative validation per step (status code, body substring, JSON field) instead of ad-hoc `if ($response->isOk() && ...)` pyramids.
- You need **retry with delay** on specific steps without mutating global config.
- You need an **error-recovery path** (e.g. "if this fails with 401, re-login and resume").
- You need one step to use an **isolated Scurl instance** (fresh cookies/headers) but keep the main session intact for the rest of the flow.

Keep using plain Scurl for single requests or flows where every step has bespoke error handling.

### Core classes

```
SrvClick\Scurlv2\Orchestrator                  ← main class (fluent entry point)
SrvClick\Scurlv2\Orchestrator\Step             ← fluent builder per step (proxies all Scurl methods via __call)
SrvClick\Scurlv2\Orchestrator\Result           ← aggregate result after run()
SrvClick\Scurlv2\Orchestrator\StepResult       ← per-step readonly result
```

### Minimal example

```php
use SrvClick\Scurlv2\Orchestrator;

$orch = new Orchestrator();

// Configure the shared Scurl (persists across every non-fresh step)
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

You can also inject a pre-built Scurl:

```php
$curl = new Scurl();
$curl->config(['exceptions' => false])->timeout(20)->useragent('Bot/2.0');

$orch = new Orchestrator($curl);
// Equivalent:
$orch = (new Orchestrator())->scurl($curl);
```

### Instance lifecycle (critical to understand)

- The Orchestrator holds **one** `Scurl` instance (`$orch->getScurl()`) that all non-`fresh()` steps share. Cookies, headers, proxy, useragent, and other global config persist across the entire flow.
- A step marked `->fresh()` gets a **brand-new `Scurl`** for that one step only. The main Scurl is not touched; the next non-fresh step resumes using the shared instance.
- `Request::reset()` still runs after every `send()` inside a step, so per-request data (body, upload file, auto-added JSON content-type) is cleared between steps as usual.

### Declaring a step

`Step` **proxies all Scurl methods via `__call`** and defers their execution until runtime. Every Scurl fluent method is available on the step:

`url`, `target`, `get`/`post`/`put`/`delete`/`patch`/`head`/`options_method`, `method`, `body`/`parameters`, `json`, `headers`, `timeout`, `useragent`, `upload`, `getHeaders`, `options`, `acceptStatus`, `proxy`, `cookie`/`cookieFile`, `addCookie`/`replaceCookie`/`deleteCookie`/`deleteCookieCompletely`, `config`.

```php
$orch->step('search')
     ->url('https://api.example.com/search')
     ->post()
     ->headers(['X-Api-Key' => 'abc'])
     ->body(['query' => 'laravel'])
     ->timeout(10);
```

### Expectations (what a successful response looks like)

Multiple expectations are combined with AND. If none are declared, the step requires a 2xx status by default.

```php
$orch->step('check')
     ->expectStatus(200)                         // int or int[]
     ->expectStatus([200, 204])

     ->expectBodyContains('exito')               // case-sensitive substring
     ->expectBodyContains(['"ok":true', 'token']) // all must be present

     ->expectJson('success', true)                // dot-notation path
     ->expectJson('data.user.id', 42)

     // Free-form validator: return true/null to pass, false to fail,
     // or a string to fail with a custom reason.
     ->expect(function ($response) {
         return (int) $response->getHeader('x-ratelimit-remaining') > 0
             ? true
             : 'Rate limit exhausted';
     });
```

The first failing expectation wins and its reason is stored in `StepResult::$failureReason`.

### Retries and failure handling

```php
$orch->step('payment')
     ->url('https://api.example.com/charge')
     ->post()
     ->body($data)
     ->expectStatus(200)
     ->retries(3, 2000)   // up to 3 retries, 2000 ms between each (4 attempts total)
     ->onFail('cancel');  // default
```

`onFail` values:

| Value                     | Behavior when the step ultimately fails (after retries) |
|---------------------------|---------------------------------------------------------|
| `'cancel'` (default)      | Stop the flow. `Result::failedAt()` returns this step name. |
| `'continue'`              | Move on to the next step anyway. |
| `'<stepName>'`            | Jump to a specific declared step (re-login / rescue pattern). |
| `callable`                | Receives `(StepResult $sr, Orchestrator $orch, Result $partial)` and must return one of the strings above. |

Dynamic recovery example:

```php
$orch->step('fetchData')
     ->url('https://api.example.com/data')
     ->get()
     ->expectStatus(200)
     ->onFail(function ($stepResult, $orch, $result) {
         if ($stepResult->response?->statuscode() === 401) {
             return 'login';   // token expired → re-login, then the flow resumes from 'login'
         }
         return 'cancel';
     });
```

### Custom step order with `next()`

By default steps run in declaration order. `next($stepName)` overrides the successor on success:

```php
$orch->step('a')->url('...')->get()->next('c');  // 'a' passes → jump directly to 'c'
$orch->step('b')->url('...')->get();             // skipped when 'a' succeeds
$orch->step('c')->url('...')->get();
```

### Rescue/recovery steps — `offFlow()`

Steps that exist **only to be targeted by `onFail()` or by an explicit `next()`** (re-login, token refresh, error-handling branches) must be marked `->offFlow()`. Otherwise, when the natural declaration order reaches them, they run anyway — and if they carry a `next()` pointing back into the main chain, the flow becomes an infinite loop:

```
login → fetch → reLogin → fetch → reLogin → fetch → ...
```

The orchestrator has a defensive guard (`stepCount × 50` transitions) that will eventually throw a `RuntimeException`, but that's a backstop — `offFlow()` is the correct way to express intent.

```php
$orch->step('login')->url('https://site.com/api/login')->post()->body([...])
     ->expectStatus(200)->onFail('reLogin');

$orch->step('fetch')->url('https://site.com/api/data')->get()
     ->expectStatus(200);

$orch->step('reLogin')                           // unreachable via declaration order
     ->offFlow()
     ->url('https://site.com/api/refresh')->post()->body(['refresh_token' => $token])
     ->expectStatus(200)
     ->next('fetch');                             // after recovery, resume fetch
```

Rules:
- `offFlow` steps never run via natural progression (start of flow or step+1 after success).
- `offFlow` steps **do** run when invoked via `onFail('<stepName>')` or `next('<stepName>')`.
- After an `offFlow` step succeeds, its `next()` (if any) is honored; otherwise the next in-flow step is picked, still skipping further `offFlow` steps.
- If **all** steps are `offFlow`, `run()` throws `RuntimeException` — there's no natural entry point.
- You can still force an off-flow step as the starting point with `$orch->run('reLogin')`.

### Isolated Scurl instance per step — `fresh()`

```php
$orch->step('user_data')          // uses shared Scurl (logged-in session)
     ->url('https://site.com/api/me')->get();

$orch->step('geoip')              // brand-new Scurl, no shared cookies/headers
     ->fresh()
     ->url('https://api.ipify.org?format=json')->get();

$orch->step('user_orders')        // back to shared Scurl, session intact
     ->url('https://site.com/api/orders')->get();
```

If the fresh step produces a value that must flow back to the main session (a token, a cookie), extract it in `afterSend()` and inject it in the next step via `request()` or `beforeSend()`.

### Hooks — `beforeSend` and `afterSend`

```php
$orch->step('upload')
     ->url('https://api.example.com/upload')
     ->post()
     // Signature: fn(Scurl $scurl, Result $partial): void
     ->beforeSend(function ($scurl, $partial) {
         $scurl->headers(['X-Trace-Id' => uniqid('trc_')]);
     })
     // Signature: fn(Response $response, Scurl $scurl, Result $partial): void
     ->afterSend(function ($response, $scurl, $partial) {
         // Runs after send() and BEFORE expectations are evaluated.
     });
```

### Dynamic configuration — `request(callable)`

When a step's configuration depends on a previous step's response (token, id, cookie), encode the logic with `request()` instead of fluent calls — fluent calls execute at declaration time with the arguments you passed, but `request()` callbacks run at step execution time with live access to `$scurl` and `$result`:

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

**Gotcha — multipart upload inside a step:** `$curl->getUploadFile()` returns the current CURLFile only after `upload()` has actually been applied. In a step, that happens at run time, so you can't reference it in a fluent `body()` call. Use `request()`:

```php
$orch->step('upload')
     ->request(fn($scurl) => $scurl
         ->upload('/path/to/file.pdf')
         ->body([
             'description' => 'Invoice',
             'file'        => $scurl->getUploadFile(),
         ])
     )
     ->url('https://api.example.com/upload')
     ->post()
     ->expectStatus(201);
```

### Reading the `Result`

```php
$result = $orch->run();

$result->isSuccess();        // true if the flow ended without being cancelled
$result->isCancelled();      // true if a step failed with onFail='cancel'
$result->failedAt();         // name of the step that caused the cancellation, or null

$result->response('login');  // Response of the step named 'login' (or null)
$result->lastResponse();     // Response of the last executed step
$result->lastStepResult();   // StepResult of the last executed step

$result->get('login');       // StepResult for a given step name
$result->steps();            // array<string, StepResult>
$result->executionOrder();   // ['login', 'fetch', 'rescue', ...] actual execution order
```

Per-step `StepResult` (readonly, positional or named-arg construction):

```php
$sr = $result->get('login');
$sr->name;                 // string — step name
$sr->passed;               // bool — true if all expectations passed
$sr->attempts;             // int — total attempts (1 if no retry happened)
$sr->response;             // ?Response — the Response object, may be null on hard errors
$sr->failureReason;        // ?string — e.g. "Status 404 not in [200]"
$sr->exception;            // ?Throwable — captured during send()
$sr->usedFreshInstance;    // bool — whether this step ran with ->fresh()
```

### `isSuccess()` semantics (important)

`Result::isSuccess()` returns `true` if the flow finished without being cancelled. A step that failed but was rescued via `onFail('<otherStep>')` or `onFail('continue')` does **not** flip `isSuccess()` to false. If the caller cares about "did every single step pass," check per-step results:

```php
$allPassed = array_reduce(
    $result->steps(),
    fn($ok, $s) => $ok && $s->passed,
    true
);
```

### Global hooks

For centralized logging/metrics without polluting every step:

```php
$orch->onStepSuccess(function ($stepResult, $orch, $result) {
         Log::info("Step OK: {$stepResult->name} ({$stepResult->attempts} attempts)");
     })
     ->onStepFailure(function ($stepResult, $orch, $result) {
         Log::warning("Step FAIL: {$stepResult->name} — {$stepResult->failureReason}");
     });
```

### Common patterns

#### Login + authenticated call + rescue on token expiry

```php
$orch = new Orchestrator();
$orch->getScurl()
     ->config(['exceptions' => false])
     ->cookie()
     ->timeout(20);

$orch->step('login')
     ->url('https://site.com/api/login')->post()
     ->body(['email' => 'u@x.com', 'password' => 'secret'])
     ->expectStatus(200)->expectJson('success', true)
     ->retries(2, 1500)->onFail('cancel');

$orch->step('orders')
     ->url('https://site.com/api/orders')->get()
     ->expectStatus([200, 204])
     ->onFail(fn($sr) => $sr->response?->statuscode() === 401 ? 'login' : 'cancel');

$result = $orch->run();
```

#### Sequential scraping with fresh side-call

```php
$orch->step('list')    // main Scurl
     ->url('https://site.com/list')->get()->expectStatus(200);

$orch->step('captcha') // isolated Scurl so cookies don't leak
     ->fresh()
     ->url('https://captcha.provider/solve')->post()->body([...])
     ->expectStatus(200)->expectJson('status', 'ok');

$orch->step('detail')  // main Scurl again, session intact
     ->request(fn($scurl, $result) =>
         $scurl->headers(['X-Captcha' => $result->response('captcha')->array()['token']])
     )
     ->url('https://site.com/detail')->get()->expectStatus(200);
```

### Gotchas specific to the Orchestrator

0. **Rescue steps that don't use `offFlow()` cause infinite loops.** If a step carries `->next('<earlierStep>')` and is reached via the natural declaration order (not via `onFail`), the flow will bounce forever. Mark rescue/recovery steps with `->offFlow()`.
1. **Fluent calls are deferred, arguments are not.** `->body($data)` stores a closure that calls `$scurl->body($data)` at run time, but `$data` is captured by value at declaration time. If `$data` must be computed from a previous response, use `request(callable)`.
2. **`expectJson()` requires a valid JSON body.** If the response isn't JSON, the step fails with `"Expected JSON in the response but the body is not valid JSON"`. Use `expectBodyContains()` for non-JSON endpoints.
3. **Retry counter is per step, not per flow.** `retries(3)` means 4 total attempts for that step; the count does not reset when jumping to other steps via `onFail`.
4. **Default expectation is 2xx.** A step with no `expectStatus*()`/`expectJson*()`/`expectBodyContains*()` will still fail if the response is not 2xx.
5. **Infinite-loop guard.** The run loop aborts with a `RuntimeException` after `50 × stepCount` transitions to catch misconfigured `onFail` cycles.
6. **`StepResult` is read-only.** Properties are PHP `readonly` promoted — you cannot mutate them after `run()` returns.
7. **`$sr->response` can be null** if the step threw before `send()` returned (e.g. invalid URL, unresolvable host with `exceptions=true` on Scurl's config).

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
    'array'   => $response->array(),
    'headers' => $response->headers(),
]);

// Orchestrator: inspect the full flow after run()
$result = $orch->run();
dd([
    'success'   => $result->isSuccess(),
    'cancelled' => $result->isCancelled(),
    'failedAt'  => $result->failedAt(),
    'order'     => $result->executionOrder(),
    'steps'     => array_map(fn($s) => [
        'name'    => $s->name,
        'passed'  => $s->passed,
        'status'  => $s->response?->statuscode(),
        'attempts'=> $s->attempts,
        'fresh'   => $s->usedFreshInstance,
        'reason'  => $s->failureReason,
    ], $result->steps()),
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
| Response as array | `$response->array()` (prefer over `json()`) |
| Response status | `$response->statuscode()` |
| Check success | `$response->isOk()` |
| Response header | `$response->getHeader('content-type')` (needs CURLOPT_HEADER) |
| Response cookie | `$response->getCookie('name')` (needs CURLOPT_HEADER) |
| Multi-step flow | `$orch = new Orchestrator(); $orch->step('x')->url(...)->get()->expectStatus(200); $orch->run();` |
| Expect status | `->expectStatus(200)` or `->expectStatus([200,204])` |
| Expect JSON field | `->expectJson('data.id', 42)` (dot-notation) |
| Expect body text | `->expectBodyContains('ok')` |
| Retry on failure | `->retries(3, 1000)` (n retries, delayMs between) |
| Stop on failure | `->onFail('cancel')` (default) |
| Recover step | `->onFail('reLoginStep')` or `->onFail(fn($sr) => ...)` |
| Rescue-only step | `->offFlow()` (skipped in natural order, reachable via onFail/next) |
| Fresh scurl per step | `->fresh()` |
| Dynamic config | `->request(fn($scurl, $result) => ...)` |
| Read step response | `$result->response('stepName')->array()` |
