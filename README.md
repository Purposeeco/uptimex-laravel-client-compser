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
batches the captured data in memory, and ships it to UptimeX over a
bounded HTTPS transport once the response has been sent to the client —
so a slow ingest never slows your application. High-traffic apps can opt
into the local `uptimex:agent` daemon to move that send off the request
process entirely.

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
  fail-closed timeouts (0.5 s default), throttled failure logging (the
  SDK never floods your log), exception swallowing in every listener —
  a bug in the SDK can never break your application.
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
| `UPTIMEX_FLUSH_TIMEOUT` | `0.5` | Seconds; HTTP timeout when shipping a batch |
| `UPTIMEX_DELIVERY` | `direct` | Delivery mode — `direct` (default) or `agent` |
| `UPTIMEX_AGENT_ADDRESS` | `127.0.0.1:9237` | `agent` mode: address the daemon listens on (`host:port` or `unix://…`) |
| `UPTIMEX_AGENT_CONNECT_TIMEOUT_MS` | `50` | `agent` mode: ms the SDK waits to reach the agent before falling back to direct |
| `UPTIMEX_AGENT_MAX_QUEUE` | `10000` | `agent` mode: max batches the agent buffers in memory (drop-oldest on overflow) |
| `UPTIMEX_AGENT_SHIP_BATCH` | `20` | `agent` mode: how many batches the agent ships per cycle |
| `UPTIMEX_LOG_LEVEL` | `debug` | Minimum PSR-3 level captured by the `uptimex` log channel |

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

## Delivery

By default (`UPTIMEX_DELIVERY=direct`) the SDK sends each finished batch
inline over HTTPS at the end of the request — *after* the response has
been flushed to the client, on a tightly-bounded transport. Nothing to
install or run; it works on every host, serverless included.

### The agent (opt-in)

High-traffic apps can move the network send off the request process
entirely. Set `UPTIMEX_DELIVERY=agent` and run the `uptimex:agent`
daemon: the request then only writes the batch to a local loopback
socket — a microsecond-scale write — and the daemon ships it out of
band, buffering in memory and retrying through outages.

The daemon is a long-lived process and must be supervised. Generate the
Supervisor / systemd config (and the Laravel Forge "Daemon" command)
with:

```bash
php artisan uptimex:install
```

It drains gracefully on `SIGTERM`, so restarting it on deploy loses
nothing. If `agent` is set but no agent is listening, the SDK falls back
to a direct send — so it is always safe. Serverless runtimes (Vapor /
Lambda), where no long-lived process can run, stay on direct delivery
automatically.

Check delivery status — including whether the agent is reachable — any
time:

```bash
php artisan uptimex:status
```

## Capturing logs

The SDK registers a `uptimex` log channel **automatically** — no
`config/logging.php` edit needed. To capture your application's logs as
telemetry, add `uptimex` to your log stack in your monitored app's
`.env`:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=single,uptimex
```

Every `Log::info()`, `Log::error()`, etc. written during a traced
request, command, or job now ships to UptimeX as a `log` event on that
trace — channel, level, message and context included (with PII
redaction). Logs fired outside a trace are skipped by design.

Tune the minimum level captured with `UPTIMEX_LOG_LEVEL` (default
`debug`):

```dotenv
UPTIMEX_LOG_LEVEL=warning
```

Already have a `uptimex` channel defined in `config/logging.php`? The
SDK detects it and leaves yours untouched.

## Artisan commands

| Command | What it does |
|---|---|
| `php artisan uptimex:test` | Send a synthetic batch to UptimeX and print the result — a real round-trip that verifies your token, URL and connectivity. |
| `php artisan uptimex:status` | Print the resolved SDK config; in `agent` mode, also report whether the agent is reachable. |
| `php artisan uptimex:deploy <ref>` | Post a deployment marker — see [Deployment markers](#deployment-markers). |
| `php artisan uptimex:agent` | Run the telemetry agent daemon. Needed only for the opt-in `agent` delivery mode. |
| `php artisan uptimex:install` | Generate Supervisor / systemd config to run `uptimex:agent` as a supervised daemon. |

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
- In the default `direct` mode the batch is sent over a tightly-bounded
  HTTPS transport (0.5 s timeout) on `terminate()` — after the response
  has already been sent to the client, so the user never waits on it.
- With the `agent` opt-in, the request only writes the batch to a local
  loopback socket — a microsecond-scale write — and the daemon owns all
  network I/O and retries; the request process never touches the network.
- A bug in any listener is wrapped in `try { … } catch (\Throwable) {}`
  so it can never throw into your request handler.
- Buffer overflow is "drop oldest" — old events are discarded silently
  rather than failing the trace.

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
