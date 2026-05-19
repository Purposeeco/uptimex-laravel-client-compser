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
    | `flush_timeout` and `connect_timeout` are network timeouts in seconds.
    |
    | These are fixed, managed values — NOT env-driven. They are performance
    | internals UptimeX tunes; a mis-set timeout or buffer would only slow or
    | starve the host app, so they are not exposed as a customer foot-gun.
    */
    'event_buffer' => 500,
    'flush_timeout' => 0.5,
    'connect_timeout' => 0.5,

    /*
    |--------------------------------------------------------------------------
    | Delivery — the uptimex:agent daemon
    |--------------------------------------------------------------------------
    |
    | Telemetry is delivered one way: the SDK writes each finished batch to a
    | local `uptimex:agent` daemon over a loopback socket — a microsecond-scale
    | write, no network on the request path — and the daemon ships it to
    | UptimeX out of band, buffering and retrying through outages. Run the
    | daemon with `php artisan uptimex:agent`, kept alive by a process monitor
    | (a Forge daemon, Supervisor, or systemd) in production.
    |
    | When no agent is running the SDK is completely inert — it captures
    | nothing and makes no network calls, exactly as if `enabled` were false —
    | and resumes on its own once the agent is back. To turn telemetry off
    | deliberately, use the `enabled` master switch above.
    |
    | `agent_address` is env-configurable because it is a genuine deployment
    | choice: the loopback address the daemon listens on and the SDK writes to
    | (`host:port` or `unix:///path/to.sock`). Change it when a port clashes,
    | or when several apps run an agent on one host.
    |
    | Everything else here is a fixed, managed value — NOT env-driven.
    | `agent_connect_timeout_ms` bounds the SDK's connect attempt so a missing
    | agent never stalls a request; `agent_health_recheck_seconds` is how often
    | the SDK re-probes a down agent before resuming capture; the agent buffers
    | up to `agent_max_queue` batches in memory and ships `agent_ship_batch_size`
    | at a time; on a failed send it backs off exponentially between
    | `retry_base_seconds` and `retry_max_seconds`. These are performance
    | internals UptimeX tunes — a mis-set value would only hurt the host app
    | or, for the retry bounds, storm UptimeX's ingest.
    */
    'agent_address' => env('UPTIMEX_AGENT_ADDRESS', '127.0.0.1:9237'),
    'agent_connect_timeout_ms' => 50,
    'agent_health_recheck_seconds' => 30,
    'agent_max_queue' => 10000,
    'agent_ship_batch_size' => 20,
    'retry_base_seconds' => 5,
    'retry_max_seconds' => 300,

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
    | counter is incremented for diagnostics). A fixed, managed value — NOT
    | env-driven.
    */
    'context_max_bytes' => 65 * 1024,

    /*
    |--------------------------------------------------------------------------
    | SDK metadata sent on every batch
    |--------------------------------------------------------------------------
    */
    'sdk_version' => '0.1.0',
];
