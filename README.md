# UptimeX Laravel Client

Thin SDK that ships application telemetry from a Laravel app to an UptimeX server.

## Install

```bash
composer require uptimex/laravel-client
```

Set two env vars:

```
UPTIMEX_TOKEN=utx_your_environment_token
UPTIMEX_INGEST_URL=https://ingest.uptimex.io
```

Run the smoke test:

```bash
php artisan uptimex:test
```

## What it ships in v1 (Phase 3)

- Buffered HTTP transport (gzip JSON, bearer-token auth, fail-closed timeouts)
- Execution-context lifecycle hooks for HTTP requests, Artisan commands, and scheduled tasks
- Public `Uptimex` facade with `record()`, `pause()`, `resume()`, `ignore()`, `startTrace()`, `endTrace()`

Per-event-type listeners (requests, queries, exceptions, jobs, cache, logs, mail, notifications, commands, scheduled tasks, outgoing HTTP) ship in Phases 4–6.

## Configuration

Publish the config if you want to override defaults:

```bash
php artisan vendor:publish --tag=uptimex-config
```

| Env var | Default | Purpose |
|---|---|---|
| `UPTIMEX_ENABLED` | `true` | Master switch |
| `UPTIMEX_TOKEN` | — | Environment-scoped ingest token |
| `UPTIMEX_INGEST_URL` | `https://ingest.uptimex.io` | UptimeX server base URL |
| `UPTIMEX_DEPLOY` | — | Release identifier (used in Phase 8) |
| `UPTIMEX_SERVER` | hostname | Optional server label |
| `UPTIMEX_EVENT_BUFFER` | `500` | Max events buffered per execution context |
| `UPTIMEX_FLUSH_TIMEOUT` | `0.5` | Seconds; HTTP timeout on flush |

## Running the test suite

The package ships three test suites:

| Suite | What it covers | Speed | External deps |
|---|---|---|---|
| `Unit` + `Feature` | SDK internals against a Testbench-faked Laravel; uses `NullTransport` (no socket) | ~1s | None |
| `Integration` | Real HTTP calls to a live UptimeX ingest endpoint | ~1.5s | A reachable UptimeX server + a valid ingest token |

```bash
composer test                # default — Unit + Feature only, hermetic
composer test:integration    # only the real-wire suite
composer test:all            # everything
```

`Integration` tests are skipped automatically unless both env vars are set:

```bash
UPTIMEX_INTEGRATION_INGEST_URL=https://ingest.uptimex.test \
UPTIMEX_INTEGRATION_TOKEN=utx_your_real_token \
composer test:integration
```

To mint a token against a local backend:

```bash
php artisan tinker --execute="
  \$t = App\Models\Tenant::first();
  tenancy()->initialize(\$t);
  \$e = App\Models\Environment::first();
  [\$plain] = App\Models\IngestToken::generate(\$t->id, \$e->id, 'sdk-integration');
  echo \$plain;
"
```

## License

MIT.
