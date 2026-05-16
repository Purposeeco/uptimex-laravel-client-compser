<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When false, all SDK behaviour becomes a no-op: no events are buffered,
    | no HTTP calls are made, lifecycle hooks short-circuit immediately. Useful
    | in `phpunit.xml` and CI environments where you don't want telemetry.
    */
    'enabled' => env('UPTIMEX_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Authentication
|--------------------------------------------------------------------------
    |
    | The bearer token issued by UptimeX for this environment. Sent on every
    | ingest request as `Authorization: Bearer <token>`. The server resolves it
    | to the matching tenant + environment.
    */
    'token' => env('UPTIMEX_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Ingest endpoint
    |--------------------------------------------------------------------------
    |
    | Where batches are shipped. Deliberately NOT env-driven: the host is
    | identical for every UptimeX cloud tenant — your bearer token, not this
    | URL, routes data to your workspace — and a customer-supplied URL is a
    | foot-gun (an `http://` scheme ships telemetry in plaintext and trips the
    | server's HTTPS redirect).
    |
    | Self-hosted installs are the one case that needs a different host: edit
    | the value here in the copy published by `vendor:publish --tag=uptimex-config`.
    | The transport upgrades any `http://` host (except localhost / *.test) to
    | https:// regardless.
    */
    'ingest_url' => 'https://ingest.uptimex.tech',

    /*
    |--------------------------------------------------------------------------
    | Release / deployment metadata
    |--------------------------------------------------------------------------
    |
    | A free-form identifier (commit SHA, semver tag, timestamp) attached to
    | every batch. Phase 8 wires this into the deploy correlation workflow.
    */
    'deploy' => env('UPTIMEX_DEPLOY'),

    /*
    |--------------------------------------------------------------------------
    | Server label
    |--------------------------------------------------------------------------
    |
    | Defaults to `gethostname()` so multi-server deployments are
    | distinguishable in the dashboard.
    */
    'server' => env('UPTIMEX_SERVER'),

    /*
    |--------------------------------------------------------------------------
    | Buffer + flush behaviour
    |--------------------------------------------------------------------------
    |
    | `event_buffer` is the per-execution-context cap; oldest events are
    | dropped on overflow with a counter incremented for diagnostics.
    | `flush_timeout` and `connect_timeout` are network timeouts in seconds —
    | the SDK will rather drop a batch than slow the host application.
    */
    'event_buffer' => (int) env('UPTIMEX_EVENT_BUFFER', 500),
    'flush_timeout' => (float) env('UPTIMEX_FLUSH_TIMEOUT', 0.5),
    'connect_timeout' => (float) env('UPTIMEX_CONNECT_TIMEOUT', 0.5),

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    |
    | How a finished telemetry batch leaves your application:
    |
    |   spool  (default) — the batch is written to a local file the instant
    |                      the request ends (no network on the request path),
    |                      then shipped to UptimeX out of band. A batch is
    |                      removed only after the server confirms receipt, so
    |                      telemetry survives ingest downtime, deploys and
    |                      restarts. It drains itself on your app's own
    |                      traffic — no worker or daemon for you to run.
    |   direct          — send inline over HTTP at the end of the request
    |                      (the pre-spool behaviour). Auto-selected on
    |                      serverless runtimes (Vapor / Lambda).
    |   null            — drop batches without touching the master switch.
    */
    'delivery' => env('UPTIMEX_DELIVERY', 'spool'),

    /*
    |--------------------------------------------------------------------------
    | Spool
    |--------------------------------------------------------------------------
    |
    | `spool_path` defaults to storage/uptimex/spool. The directory is
    | self-bounding: once `spool_max_files` or `spool_max_bytes` is reached the
    | oldest pending batches are dropped and the loss is logged loudly. The
    | defaults are large on purpose — a disk-safety backstop, not a routine
    | pressure valve.
    */
    'spool_path' => env('UPTIMEX_SPOOL_PATH'),
    'spool_max_files' => (int) env('UPTIMEX_SPOOL_MAX_FILES', 10000),
    'spool_max_bytes' => (int) env('UPTIMEX_SPOOL_MAX_BYTES', 524288000),

    /*
    |--------------------------------------------------------------------------
    | Drain
    |--------------------------------------------------------------------------
    |
    | The spool is drained opportunistically after a response is sent — one
    | request at a time, capped by `drain_max_batches` / `drain_max_ms` so a
    | request never over-spends. Set `drain_auto` to false to turn the
    | piggyback drain off (leaving `uptimex:spool:drain` as the only drainer).
    | A failed send backs off exponentially between `retry_base_seconds` and
    | `retry_max_seconds`.
    */
    'drain_auto' => (bool) env('UPTIMEX_DRAIN_AUTO', true),
    'drain_max_batches' => (int) env('UPTIMEX_DRAIN_MAX_BATCHES', 20),
    'drain_max_ms' => (int) env('UPTIMEX_DRAIN_MAX_MS', 750),
    'drain_failfast' => (int) env('UPTIMEX_DRAIN_FAILFAST', 3),
    'retry_base_seconds' => (int) env('UPTIMEX_RETRY_BASE', 10),
    'retry_max_seconds' => (int) env('UPTIMEX_RETRY_MAX', 3600),

    /*
    |--------------------------------------------------------------------------
    | Hosts the SDK should never capture for
    |--------------------------------------------------------------------------
    |
    | Prevents the recursive feedback loop when an UptimeX server dogfoods
    | itself: the SDK posts to `ingest.uptimex.tech`, the server receives it,
    | the server's own SDK would otherwise capture that ingest request,
    | flush it back to ingest, ad infinitum.
    |
    | The host of `ingest_url` is auto-added at boot — anything else listed
    | here is also skipped.
    */
    'skip_hosts' => [],

    /*
    |--------------------------------------------------------------------------
    | Privacy controls
    |--------------------------------------------------------------------------
    |
    | `capture_request_payload` is opt-in (default false) — request bodies can
    | contain PII. When true, the redaction list strips known sensitive keys.
    |
    | `capture_exception_source_code` is opt-out (default true) — ±5 lines of
    | source around the throw site help debugging without exposing much that
    | wasn't already in the stack trace.
    */
    'capture_request_payload' => env('UPTIMEX_CAPTURE_REQUEST_PAYLOAD', false),
    'capture_exception_source_code' => env('UPTIMEX_CAPTURE_EXCEPTION_SOURCE_CODE', true),

    /*
    |--------------------------------------------------------------------------
    | Log capture
    |--------------------------------------------------------------------------
    |
    | Min PSR-3 level captured by `UptimexLogChannel`. Records below this
    | level are dropped before they hit the buffer.
    */
    'log_level' => env('UPTIMEX_LOG_LEVEL', 'debug'),

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | Volume control. The decision is made *once* at trace start (per the
    | trace's context type) and child events inherit. Server-side aggregations
    | multiply counters by 1/sample_rate so dashboard headline counts stay
    | true to the actual production volume even when sampled.
    |
    | High-traffic apps usually pin `request_sample_rate` to 0.1 or lower.
    | Exception/error sampling stays at 1.0 unless you really know what
    | you're doing — error-budget reasoning collapses without all errors.
    */
    'request_sample_rate' => (float) env('UPTIMEX_REQUEST_SAMPLE_RATE', 1.0),
    'command_sample_rate' => (float) env('UPTIMEX_COMMAND_SAMPLE_RATE', 1.0),
    'scheduled_task_sample_rate' => (float) env('UPTIMEX_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    'exception_sample_rate' => (float) env('UPTIMEX_EXCEPTION_SAMPLE_RATE', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Whole-category disable env vars
    |--------------------------------------------------------------------------
    |
    | When true, the matching event type is fully ignored — no buffer entry,
    | no transport call, no DB write. Listeners fire but short-circuit
    | immediately. Use these as the volume hammer when sampling is too
    | granular.
    */
    'ignore_queries' => (bool) env('UPTIMEX_IGNORE_QUERIES', false),
    'ignore_cache_events' => (bool) env('UPTIMEX_IGNORE_CACHE_EVENTS', false),
    'ignore_mail' => (bool) env('UPTIMEX_IGNORE_MAIL', false),
    'ignore_notifications' => (bool) env('UPTIMEX_IGNORE_NOTIFICATIONS', false),
    'ignore_outgoing_requests' => (bool) env('UPTIMEX_IGNORE_OUTGOING_REQUESTS', false),

    /*
    |--------------------------------------------------------------------------
    | Header + payload redaction defaults
    |--------------------------------------------------------------------------
    |
    | Comma-separated env vars override the defaults. Header names are
    | matched case-insensitively. Payload field names match the immediate
    | top-level array key (Phase 7 keeps the simple flat match; Phase 9 may
    | extend to dotted paths).
    */
    'redact_headers' => array_filter(array_map('trim', explode(',', (string) env(
        'UPTIMEX_REDACT_HEADERS',
        'authorization,cookie,proxy-authorization,x-xsrf-token',
    )))),
    'redact_payload_fields' => array_filter(array_map('trim', explode(',', (string) env(
        'UPTIMEX_REDACT_PAYLOAD_FIELDS',
        '_token,password,password_confirmation,api_key,secret,credit_card',
    )))),
    'redact_log_keys' => array_filter(array_map('trim', explode(',', (string) env(
        'UPTIMEX_REDACT_LOG_KEYS',
        'password,password_confirmation,token,authorization,api_key,apikey,secret,credit_card,cc_number',
    )))),

    /*
    |--------------------------------------------------------------------------
    | Context propagation cap
    |--------------------------------------------------------------------------
    |
    | Maximum bytes of Laravel `Context` snapshot embedded into the trace's
    | `context_json` column. Anything beyond this is truncated (a metric
    | counter is incremented for diagnostics).
    */
    'context_max_bytes' => (int) env('UPTIMEX_CONTEXT_MAX_BYTES', 65 * 1024),

    /*
    |--------------------------------------------------------------------------
    | SDK metadata sent on every batch
    |--------------------------------------------------------------------------
    */
    'sdk_version' => '0.1.0',
];
