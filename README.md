<div align="center">

# UptimeX Laravel Client

**The official Laravel SDK for [UptimeX](https://uptimex.tech) — full-stack APM
and uptime monitoring, self-hosted or cloud.**

[![Tests](https://img.shields.io/github/actions/workflow/status/Purposeeco/uptimex-laravel-client-compser/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Purposeeco/uptimex-laravel-client-compser/actions/workflows/tests.yml)
[![Latest Version](https://img.shields.io/packagist/v/uptimex/laravel-client.svg?style=flat-square)](https://packagist.org/packages/uptimex/laravel-client)
[![PHP Version](https://img.shields.io/packagist/php-v/uptimex/laravel-client.svg?style=flat-square)](https://packagist.org/packages/uptimex/laravel-client)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)

</div>

---

## What it does

Drop this package into a Laravel app and UptimeX automatically captures
**eleven** kinds of telemetry — without scattering instrumentation calls
across your codebase. The SDK hooks into Laravel's framework events,
batches the captured data, and ships it to your UptimeX server over a
gzip-compressed HTTP transport that's tightly bounded so a slow ingest
never slows your application.

| Captured | What lands in UptimeX |
|---|---|
| **HTTP requests** | route, method, status, response time, headers, payload (with redaction) |
| **Database queries** | normalized SQL, connection name, duration, slow-query detection |
| **Exceptions** | class, message, file, line, stack trace, fingerprint, occurrence groups |
| **Background jobs** | class, queue, attempts, status (queued / processing / processed / released / failed), duration |
| **Cache events** | hit / miss / write / delete / fail by key + store, hit-rate analytics |
| **Log lines** | full PSR-3 (debug → emergency), channel, message, context (with PII redaction) |
| **Outgoing mail** | mailer, recipients, subject |
| **Notifications** | class, channel, notifiable |
| **Artisan commands** | name, arguments, exit code, duration |
| **Scheduled tasks** | expression, description, run duration, success/failure |
| **Outgoing HTTP** | URL, method, status, duration of every request your app makes |

Everything ties together via a **trace id** (UUIDv7) so a single request
in the dashboard shows its queries, jobs, log lines, and outgoing HTTP
calls in one timeline.

## Why use it

- **Zero-config defaults.** `composer require`, set one env var — your
  ingest token — and you're capturing telemetry. Auto-discovers the
  service provider; no code changes in your app.
- **Built for production.** Bounded buffers (drop-oldest on overflow),
  fail-closed timeouts (0.5 s default), exception swallowing in every
  listener — a bug in the SDK can never break your application.
- **Privacy-first.** PII redaction for log context and request payloads
  out of the box, with hooks for adding your own redactors per event
  type. No fixed allow-list of fields; you decide what's sensitive.
- **Configurable sampling.** Per-event-type sample-rate gates with the
  rate stored on the trace, so server-side aggregations can multiply
  by 1/rate to keep counts honest under sampling.
- **Laravel Context propagation.** Whatever your app puts in
  `Illuminate\Support\Facades\Context` (request id, tenant id, feature
  flags) ships with each trace.
- **Deployment markers.** `php artisan uptimex:deploy <ref>` posts a
  release marker; the dashboard correlates issues with deploys
  ("regressions since v2.5.0").
- **Multi-version Laravel.** Tested against Laravel 10 / 11 / 12 / 13
  on PHP 8.2 / 8.3 / 8.4.

## Install

```bash
composer require uptimex/laravel-client
```

Set your ingest token in your monitored app's `.env`:

```dotenv
UPTIMEX_TOKEN=utx_your_environment_token
```

Smoke-test the connection:

```bash
php artisan uptimex:test
```

Expected output:

```
Sending synthetic batch to https://ingest.uptimex.tech ...
Batch accepted by UptimeX.
  trace_id: 019df4c8-d721-7067-8c88-10a84081b445
```

That's it. Telemetry now flows to UptimeX automatically as your app
serves requests, processes jobs, and runs scheduled tasks. Browse the
UptimeX dashboard a few seconds later and you'll see live data.

## Configuration

The defaults work out of the box. To override anything, publish the
config file:

```bash
php artisan vendor:publish --tag=uptimex-config
```

| Env var | Default | Purpose |
|---|---|---|
| `UPTIMEX_ENABLED` | `true` | Master switch — set `false` to disable in non-prod |
| `UPTIMEX_TOKEN` | — | Environment-scoped ingest token from the UptimeX dashboard |
| `UPTIMEX_DEPLOY` | — | Release identifier (set by `uptimex:deploy`) |
| `UPTIMEX_SERVER` | hostname | Optional server label shown in the dashboard |
| `UPTIMEX_EVENT_BUFFER` | `500` | Max events buffered per execution context |
| `UPTIMEX_FLUSH_TIMEOUT` | `0.5` | Seconds; HTTP timeout on flush |

### Self-hosting

The ingest URL is **not** an env var — it ships hardcoded in the package
(`https://ingest.uptimex.tech`), so cloud customers never have to think
about it and a stray `http://` can't leak telemetry in plaintext. Your
ingest token, not the URL, is what routes data to your workspace.

Running your own UptimeX server? Publish the config and point `ingest_url`
at it:

```php
// config/uptimex.php
'ingest_url' => 'https://ingest.your-uptimex-server.com',
```

## Public API

The `Uptimex` facade exposes the SDK's full surface:

```php
use Uptimex\Client\Facades\Uptimex;

// Manually start a trace (CLI scripts, custom workers, etc.)
$ctx = Uptimex::startTrace('command', ['source' => 'cron-cleanup']);

// Record a custom event
Uptimex::record('request', ['route' => '/checkout', 'duration_ms' => 42]);

// Skip recording for a block
Uptimex::ignore(function () {
    // SDK silent inside this closure
});

// Or pause/resume manually
Uptimex::pause();
// … noisy section …
Uptimex::resume();

// End the trace and flush
Uptimex::endTrace('ok');
```

Most apps never need to call any of this — the lifecycle hooks handle
HTTP requests, Artisan commands, and scheduled tasks automatically.

## Sampling, filtering, redaction

Register callbacks in a service provider:

```php
use Uptimex\Client\Uptimex;

public function boot(Uptimex $uptimex): void
{
    // Sample-rate gate: only ship 10% of cache events
    $uptimex->sampleRate('cache', 0.10);

    // Reject events matching a predicate
    $uptimex->reject('query', fn (array $event) =>
        str_contains($event['sql'] ?? '', 'pg_stat')
    );

    // Redact / transform an event before it ships
    $uptimex->redact('log', fn (array $event) => [
        ...$event,
        'context' => array_diff_key($event['context'] ?? [], ['password' => true]),
    ]);
}
```

## Deployment markers

Add a single command at the end of your CI deploy step:

```bash
php artisan uptimex:deploy "$(git rev-parse HEAD)" \
    --name="v$(git describe --tags --abbrev=0)" \
    --url="https://github.com/your-org/your-app/commit/$(git rev-parse HEAD)"
```

UptimeX:

- Records the deploy as a vertical line on every dashboard chart.
- Auto-resolves any issues marked **resolve on next deploy**.
- Surfaces "issues introduced since this deploy" so you spot regressions
  before customers do.

## Testing your integration

The package ships three test suites:

| Suite | What it covers | Speed | External deps |
|---|---|---|---|
| `Unit` + `Feature` | SDK internals against a Testbench-faked Laravel; uses `NullTransport` (no socket) | ~1 s | None |
| `Integration` | Real HTTP calls to a live UptimeX ingest endpoint | ~2 s | A reachable UptimeX server + a valid ingest token |

```bash
composer test                # default — Unit + Feature only, hermetic
composer test:integration    # only the real-wire suite
composer test:all            # everything
```

`Integration` tests are skipped automatically unless both env vars are
set:

```bash
UPTIMEX_INTEGRATION_INGEST_URL=https://ingest.your-uptimex-server.com \
UPTIMEX_INTEGRATION_TOKEN=utx_your_real_token \
composer test:integration
```

## Performance

The SDK is designed to add **negligible** overhead to a request:

- Lifecycle listeners record into an in-memory buffer; they never block
  on network I/O.
- The HTTP flush happens on `terminate()` (after the response is sent
  to the client) on a tightly-bounded transport (default 0.5 s timeout).
- A bug in any listener is wrapped in `try { … } catch (\Throwable) {}`
  so it can never throw into your request handler.
- Buffer overflow is "drop oldest" — old events are discarded silently
  rather than failing the trace.

In practice you'll see **<2 ms** added per request, dominated by the
JSON encode + gzip on flush. Sub-millisecond if your trace has few
events.

## Requirements

- PHP **8.2+**
- Laravel **10 / 11 / 12 / 13**
- A reachable UptimeX server (self-hosted or cloud)

## License

[MIT](LICENSE) © Purpose Company

---

<div align="center">

### Built by [Purpose Company](https://github.com/Purposeeco)

A Palestinian software development company based in the West Bank.<br>
We build observability and developer tools used by teams across the region and beyond.

[**UptimeX**](https://uptimex.tech) · [**GitHub Org**](https://github.com/Purposeeco) · [**Issues**](https://github.com/Purposeeco/uptimex-laravel-client-compser/issues)

</div>
