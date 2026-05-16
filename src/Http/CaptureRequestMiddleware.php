<?php

namespace Uptimex\Client\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Uptimex\Client\Context\ExecutionContext;
use Uptimex\Client\Uptimex;

/**
 * Captures a request event when the host application's HTTP lifecycle ends.
 *
 * The middleware itself does very little — it just records the start time.
 * Recording the actual event is deferred to the `terminate()` hook so we
 * can include the response status + duration without forcing an early
 * resolution. The trace is started lazily here if no other code already did.
 */
final class CaptureRequestMiddleware
{
    /**
     * Header allow-list for capture. Anything outside this list is silently
     * dropped — Phase 7 will make this configurable per environment.
     */
    private const HEADER_ALLOW_LIST = [
        'accept', 'accept-encoding', 'accept-language', 'cache-control',
        'content-type', 'content-length', 'host', 'origin', 'referer',
        'user-agent', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto',
        'x-real-ip', 'x-request-id',
    ];

    private const REDACTED_HEADERS = [
        'authorization', 'cookie', 'proxy-authorization', 'x-xsrf-token',
    ];

    private const REDACTED_PAYLOAD_FIELDS = [
        'password', 'password_confirmation', '_token', 'api_key', 'secret',
    ];

    public function __construct(private readonly Uptimex $uptimex) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        // Laravel may resolve a different middleware instance for terminate() than for
        // handle() (the kernel re-resolves through the container). Stash the start time
        // on the request attributes so it survives the instance swap.
        $request->attributes->set('uptimex.request_started_at', microtime(true));

        if ($this->uptimex->isEnabled() && $this->uptimex->context() === null) {
            $this->uptimex->startTrace(ExecutionContext::TYPE_REQUEST, [
                'method' => $request->method(),
                'path' => $request->path(),
            ]);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if (! $this->uptimex->isRecording() || $this->shouldSkip($request)) {
            return;
        }

        $startedAt = (float) $request->attributes->get('uptimex.request_started_at', microtime(true));
        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $this->uptimex->record('request', [
            'duration_ms' => $durationMs,
            'method' => $request->method(),
            'route' => optional($request->route())->getName() ?? optional($request->route())->uri(),
            'path' => '/'.ltrim($request->path(), '/'),
            'status' => $response->getStatusCode(),
            'ip' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 512),
            'headers' => $this->captureHeaders($request),
            'payload' => $this->capturePayload($request),
            'user_id' => optional($request->user())->getAuthIdentifier(),
        ]);
    }

    /**
     * Hosts that match the UptimeX ingest URL (or any explicit `skip_hosts`
     * entry) are not captured — without this the server self-DDoSes when
     * dogfooding because every captured request triggers an ingest call
     * which is itself a captured request.
     */
    private function shouldSkip(Request $request): bool
    {
        $host = strtolower($request->getHost());
        $skip = array_map('strtolower', (array) config('uptimex.skip_hosts', []));

        $ingestHost = parse_url((string) config('uptimex.ingest_url'), PHP_URL_HOST);
        if (is_string($ingestHost) && $ingestHost !== '') {
            $skip[] = strtolower($ingestHost);
        }

        return in_array($host, $skip, true);
    }

    private function captureHeaders(Request $request): array
    {
        $captured = [];

        foreach (self::HEADER_ALLOW_LIST as $name) {
            if ($request->headers->has($name)) {
                $captured[$name] = $request->headers->get($name);
            }
        }

        // Tag — never the value — for the redacted ones, so the dashboard can
        // still show "an Authorization header was present, redacted".
        foreach (self::REDACTED_HEADERS as $name) {
            if ($request->headers->has($name)) {
                $captured[$name] = '[REDACTED]';
            }
        }

        return $captured;
    }

    private function capturePayload(Request $request): ?array
    {
        if (! config('uptimex.capture_request_payload', false)) {
            return null;
        }

        $payload = $request->all();

        foreach (self::REDACTED_PAYLOAD_FIELDS as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = '[REDACTED]';
            }
        }

        return $payload;
    }
}
