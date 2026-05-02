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

## License

MIT.
