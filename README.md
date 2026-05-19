<div align="center">

# UptimeX Laravel Client

**The official Laravel SDK for [UptimeX](https://uptimex.tech) — full-stack
application performance monitoring, self-hosted or cloud.**

[![Tests](https://img.shields.io/github/actions/workflow/status/Purposeeco/uptimex-laravel-client-compser/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/Purposeeco/uptimex-laravel-client-compser/actions)
[![Latest Version](https://img.shields.io/packagist/v/uptimex/laravel-client.svg?style=flat-square)](https://packagist.org/packages/uptimex/laravel-client)
[![PHP Version](https://img.shields.io/packagist/php-v/uptimex/laravel-client.svg?style=flat-square)](https://packagist.org/packages/uptimex/laravel-client)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg?style=flat-square)](LICENSE)

</div>

---

## What it does

Add the package to a Laravel app, set one token, and UptimeX captures
**eleven kinds of telemetry** automatically — with no instrumentation
calls scattered through your code. The SDK hooks into Laravel's framework
events, buffers what it captures in memory, and hands it to the local
`uptimex:agent` daemon once the response has reached the user — so
monitoring never slows a request.

| Captured | What lands in UptimeX |
|---|---|
| **HTTP requests** | route, method, status, response time, headers, payload (redacted) |
| **Database queries** | normalized SQL, connection, duration, slow-query detection |
| **Exceptions** | class, message, file, line, stack trace, fingerprint, occurrence groups |
| **Background jobs** | class, queue, attempts, status (queued → processing → processed / released / failed), duration |
| **Cache events** | hit / miss / write / delete / fail by key + store, hit-rate analytics |
| **Log lines** | full PSR-3 (debug → emergency), channel, message, context (redacted) |
| **Outgoing mail** | mailer, recipients, subject |
| **Notifications** | class, channel, notifiable |
| **Artisan commands** | name, arguments, exit code, duration |
| **Scheduled tasks** | expression, description, run duration, success / failure |
| **Outgoing HTTP** | URL, method, status and duration of every request your app makes |

Every event carries a **trace id** (UUIDv7), so a single request in the
dashboard shows its queries, jobs, log lines, and outgoing calls on one
timeline.

## Why use it

- **Drop-in.** `composer require`, set your ingest token — done. The
  service provider auto-registers; no code changes in your app.
- **Safe by construction.** Every listener is exception-wrapped, the
  request only writes to a local socket (never the network), buffers
  drop oldest on overflow, and with no agent running the SDK goes
  silently inert — a bug or an outage can never break *or* flood your
  application.
- **Privacy-first.** Request payloads, headers, and log context are
  redacted against sensible defaults you can extend per event type.
- **Volume control.** Per-event-type sampling and whole-category ignore
  switches keep telemetry — and your bill — proportionate.
- **Agent-based delivery.** A local `uptimex:agent` daemon ships your
  telemetry out of band — the request only writes to a loopback socket,
  never the network.
- **Multi-version.** Tested against Laravel 10 / 11 / 12 / 13 on PHP
  8.2 / 8.3 / 8.4.

## Requirements

- PHP **8.2+**
- Laravel **10, 11, 12, or 13**
- An UptimeX workspace (cloud or self-hosted) and an environment ingest token

## Installation

```bash
composer require uptimex/laravel-client
```

Set your environment's ingest token in `.env`:

```dotenv
UPTIMEX_TOKEN=utx_your_environment_token
```

Verify the connection:

```bash
php artisan uptimex:test
```

```
Sending synthetic batch to https://ingest.uptimex.tech ...
Batch accepted by UptimeX.
  trace_id: 019df4c8-d721-7067-8c88-10a84081b445
```

Then run the agent — the daemon that delivers your telemetry — and leave
it running:

```bash
php artisan uptimex:agent
```

That is the whole setup. With the agent running, telemetry flows
automatically as your app serves requests, runs jobs, and executes
scheduled tasks — visible in the UptimeX dashboard within seconds. In
production the agent runs as a supervised daemon; see
[Deploying to production](#deploying-to-production).

## Configuration

The defaults work out of the box. To change anything, publish the config
file:

```bash
php artisan vendor:publish --tag=uptimex-config
```

| Env var | Default | Purpose |
|---|---|---|
| `UPTIMEX_ENABLED` | `true` | Master switch — `false` makes the SDK a complete no-op |
| `UPTIMEX_TOKEN` | — | Environment-scoped ingest token from the UptimeX dashboard |
| `UPTIMEX_DEPLOY` | — | Release identifier, usually set by `uptimex:deploy` |
| `UPTIMEX_SERVER` | hostname | Server label shown in the dashboard |
| `UPTIMEX_AGENT_ADDRESS` | `127.0.0.1:9237` | Address the `uptimex:agent` daemon listens on (`host:port` or `unix:///path.sock`) |
| `UPTIMEX_LOG_LEVEL` | `debug` | Minimum PSR-3 level the `uptimex` log channel captures |

Privacy, sampling, and filtering have their own env vars — see
[Sampling, filtering & redaction](#sampling-filtering--redaction).

Performance internals — network timeouts, buffer sizes, the agent's
in-memory queue — are deliberately **not** environment variables. UptimeX
manages those values so a stray setting can never degrade your app.

### Self-hosting

The ingest URL is **not** an env var — it ships hardcoded in the package
(`https://ingest.uptimex.tech`). Cloud customers never think about it, and
a stray `http://` can't leak telemetry in plaintext; your token, not the
URL, routes data to your workspace.

Running your own UptimeX server? Publish the config and point `ingest_url`
at it:

```php
// config/uptimex.php
'ingest_url' => 'https://ingest.your-uptimex-server.com',
```

## Delivery — the agent

Telemetry is delivered by one channel: the **`uptimex:agent`** daemon.
During a request the SDK records events into an in-memory buffer; when
the request ends it writes the finished batch to the agent over a local
loopback socket — a microsecond-scale write, no network on the request
path. The daemon ships batches to UptimeX out of band, buffering in
memory and retrying through outages, and drains gracefully on `SIGTERM`
so a deploy restart loses nothing.

Run the agent locally while you develop:

```bash
php artisan uptimex:agent
```

In production it runs as a supervised daemon — see
[Deploying to production](#deploying-to-production).

**With no agent running, the SDK is inert.** It detects the absence —
a cached health check, re-probed every ~30 s — starts no trace, buffers
nothing, and writes no log line: it behaves exactly as if
`UPTIMEX_ENABLED=false`. The moment the agent is back, capture resumes
on its own. So a forgotten or crashed agent costs a gap in telemetry,
never an error or a slowdown.

Check the agent's reachability any time with `php artisan uptimex:status`.

## Deploying to production

The `uptimex:agent` daemon must run as a supervised, long-lived process —
exactly as you would run Horizon or a queue worker. Keep
`php artisan uptimex:agent` alive; running the command by hand is not
enough, it must survive reboots and crashes.

`php artisan uptimex:install` generates the supervision config for you:

- **Laravel Forge** — add it under Server → Daemons → New Daemon, using
  the command, directory, and user the installer prints. Forge keeps it
  alive.
- **Plain VPS** — copy the generated Supervisor program (or `systemd`
  unit) into place and enable it.
- **Docker** — run `php artisan uptimex:agent` as its own service.

Serverless runtimes (Vapor / Lambda) cannot host a persistent daemon, so
the SDK is inert there — no errors, just no telemetry.

## Capturing logs

The SDK registers a `uptimex` log channel **automatically** — no
`config/logging.php` edit needed. To capture your application's logs as
telemetry, add `uptimex` to your log stack in `.env`:

```dotenv
LOG_CHANNEL=stack
LOG_STACK=single,uptimex
```

Every `Log::info()`, `Log::error()`, etc. written during a traced request,
command, or job now ships to UptimeX as a `log` event on that trace —
channel, level, message, and context included (with PII redaction). Logs
fired outside a trace are skipped by design.

Tune the minimum level captured with `UPTIMEX_LOG_LEVEL` (default
`debug`):

```dotenv
UPTIMEX_LOG_LEVEL=warning
```

Already have a `uptimex` channel defined in `config/logging.php`? The SDK
detects it and leaves yours untouched.

## Sampling, filtering & redaction

### Sampling

Control telemetry volume per event-root type. The decision is made once
at trace start, and child events inherit it:

| Env var | Default |
|---|---|
| `UPTIMEX_REQUEST_SAMPLE_RATE` | `1.0` |
| `UPTIMEX_COMMAND_SAMPLE_RATE` | `1.0` |
| `UPTIMEX_SCHEDULED_TASK_SAMPLE_RATE` | `1.0` |
| `UPTIMEX_EXCEPTION_SAMPLE_RATE` | `1.0` |

High-traffic apps usually lower `UPTIMEX_REQUEST_SAMPLE_RATE` (e.g. `0.1`);
keep exception sampling at `1.0` unless you are sure. Server-side
aggregations multiply by `1/rate`, so dashboard counts stay true to real
volume. To force-capture a specific request at runtime:

```php
use Uptimex\Client\Facades\Uptimex;

Uptimex::sample(1.0); // capture this whole trace regardless of the rate
```

### Ignoring whole categories

Set any of these to `true` to drop an event type entirely — no buffer
entry, no network call:

`UPTIMEX_IGNORE_QUERIES` · `UPTIMEX_IGNORE_CACHE_EVENTS` ·
`UPTIMEX_IGNORE_MAIL` · `UPTIMEX_IGNORE_NOTIFICATIONS` ·
`UPTIMEX_IGNORE_OUTGOING_REQUESTS`

### Filtering individual events

Register predicates in a service provider's `boot()` — return `true` to
drop the event:

```php
use Uptimex\Client\Facades\Uptimex;

public function boot(): void
{
    Uptimex::rejectQueries(fn (array $q) =>
        str_contains($q['sql_normalized'] ?? '', 'telescope_')
    );

    Uptimex::rejectCacheKeys(fn (array $c) =>
        str_starts_with($c['key'] ?? '', 'framework/')
    );
}
```

Also available: `rejectQueuedJobs`, `rejectMail`, `rejectNotifications`,
`rejectOutgoingRequests`, and the generic `reject(string $type, Closure)`.

### Redaction

Header names, request-payload fields, and log-context keys are redacted
against built-in defaults. Override them with comma-separated env vars:

| Env var | Redacts |
|---|---|
| `UPTIMEX_REDACT_HEADERS` | request / response header names |
| `UPTIMEX_REDACT_PAYLOAD_FIELDS` | top-level request-payload keys |
| `UPTIMEX_REDACT_LOG_KEYS` | keys inside captured log context |

Two capture toggles govern potentially-sensitive data:

| Env var | Default | Notes |
|---|---|---|
| `UPTIMEX_CAPTURE_REQUEST_PAYLOAD` | `false` | opt-in — request bodies can hold PII |
| `UPTIMEX_CAPTURE_EXCEPTION_SOURCE_CODE` | `true` | ±5 source lines around the throw site |

For anything the defaults don't cover, register a transformer in `boot()`:

```php
Uptimex::redactLogs(function (array $context) {
    unset($context['ssn'], $context['card_number']);

    return $context;
});
```

Also available: `redactHeaders`, `redactPayload`, `redactQueries`,
`redactMail`, `redactCacheKeys`, `redactOutgoingRequests`, and the generic
`redact(string $type, Closure)`.

## Deployment markers

Add one command to the end of your CI deploy step:

```bash
php artisan uptimex:deploy "$(git rev-parse HEAD)" \
    --name="v$(git describe --tags --abbrev=0)" \
    --url="https://github.com/your-org/your-app/commit/$(git rev-parse HEAD)"
```

UptimeX then:

- draws the deploy as a vertical marker on every dashboard chart,
- auto-resolves issues marked **resolve on next deploy**, and
- surfaces "issues introduced since this deploy" so you catch regressions
  before your customers do.

## Artisan commands

| Command | What it does |
|---|---|
| `php artisan uptimex:test` | Send a synthetic batch and print the result — a real round-trip that verifies token and connectivity. |
| `php artisan uptimex:status` | Print the resolved SDK config and report whether the agent is reachable. |
| `php artisan uptimex:deploy <ref>` | Post a deployment marker — see [Deployment markers](#deployment-markers). |
| `php artisan uptimex:agent` | Run the telemetry agent daemon — the SDK's delivery process; run it locally and as a supervised daemon in production. |
| `php artisan uptimex:install` | Generate Supervisor / systemd config to run `uptimex:agent` as a supervised daemon in production. |

## The `Uptimex` facade

Most apps never call the SDK directly — the lifecycle hooks handle HTTP
requests, Artisan commands, and scheduled tasks automatically. For custom
workers or CLI scripts, the facade exposes the full surface:

```php
use Uptimex\Client\Facades\Uptimex;

// Start a trace manually (custom long-running scripts, workers, …)
Uptimex::startTrace('command', ['source' => 'cron-cleanup']);

// Record a custom event under the active trace
Uptimex::record('request', ['route' => '/checkout', 'duration_ms' => 42]);

// Run a block with capture paused
Uptimex::ignore(function () {
    // the SDK is silent inside this closure
});

// …or pause / resume manually
Uptimex::pause();
// noisy section
Uptimex::resume();

// End the trace and flush its batch
Uptimex::endTrace('ok');
```

## Performance

The SDK is built to add **negligible** overhead to a request:

- Lifecycle listeners record into an in-memory buffer; they never block on
  network I/O.
- The request only writes the finished batch to a local loopback socket
  (a microsecond-scale write); the `uptimex:agent` daemon owns all
  network I/O and retries — the request process never touches the
  network.
- Every listener is wrapped in `try { … } catch (\Throwable) {}`, so a bug
  in the SDK can never throw into your request handler.
- Buffer overflow is "drop oldest", and failure logging is throttled —
  the SDK can neither exhaust memory nor flood your log.

## Testing

The package ships three test suites:

| Suite | Covers | External deps |
|---|---|---|
| `Unit` + `Feature` | SDK internals against a Testbench-faked Laravel; no sockets | none |
| `Integration` | Real HTTP calls to a live UptimeX ingest endpoint | a reachable UptimeX server + a valid token |

```bash
composer test                # default — Unit + Feature, hermetic
composer test:integration    # only the real-wire suite
composer test:all            # everything
```

`Integration` tests skip automatically unless both env vars are set:

```bash
UPTIMEX_INTEGRATION_INGEST_URL=https://ingest.your-uptimex-server.com \
UPTIMEX_INTEGRATION_TOKEN=utx_your_real_token \
composer test:integration
```

## License

[MIT](LICENSE) © Purpose Company

---

<div align="center">

### Built by [Purpose Company](https://github.com/Purposeeco)

A Palestinian software development company based in the West Bank.<br>
We build observability and developer tools used by teams across the region and beyond.

[**UptimeX**](https://uptimex.tech) · [**GitHub**](https://github.com/Purposeeco) · [**Issues**](https://github.com/Purposeeco/uptimex-laravel-client-compser/issues)

</div>
