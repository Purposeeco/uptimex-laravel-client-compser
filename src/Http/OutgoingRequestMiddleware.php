<?php

namespace Uptimex\Client\Http;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Uptimex\Client\Uptimex;

/**
 * Captures `events_outgoing_requests` for HTTP calls made via Laravel's
 * `Http` facade by listening to `RequestSending` + `ResponseReceived` events.
 *
 * Duration is computed by stashing the start time in an in-memory map keyed
 * by spl_object_id of the request — this is correct as long as the
 * RequestSending → ResponseReceived pair runs in the same process (true for
 * synchronous Http calls).
 *
 * The querystring is stripped from the captured URL by default — query
 * params often carry tokens. Phase 7 will add a `redactOutgoingRequests()`
 * callback for full URL capture.
 *
 * For users who use raw Guzzle (bypassing Laravel's Http facade), this class
 * also implements the Guzzle middleware contract via `__invoke()` so it can
 * be plugged into a `\GuzzleHttp\HandlerStack`.
 */
final class OutgoingRequestMiddleware
{
    /**
     * Per-request start times keyed by spl_object_id of the PSR-7 request.
     *
     * @var array<int, float>
     */
    private array $startedAtById = [];

    public function __construct(private readonly Uptimex $uptimex) {}

    public function onSending(RequestSending $event): void
    {
        $this->startedAtById[spl_object_id($event->request)] = microtime(true);
    }

    public function onReceived(ResponseReceived $event): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $request = $event->request->toPsrRequest();
        $response = $event->response->toPsrResponse();
        $startedAt = $this->startedAtById[spl_object_id($event->request)] ?? null;
        unset($this->startedAtById[spl_object_id($event->request)]);

        $this->record($request, $response, $startedAt);
    }

    public function onConnectionFailed(ConnectionFailed $event): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $request = $event->request->toPsrRequest();
        $startedAt = $this->startedAtById[spl_object_id($event->request)] ?? null;
        unset($this->startedAtById[spl_object_id($event->request)]);

        $this->record($request, null, $startedAt);
    }

    /**
     * Guzzle middleware entry-point for users wiring this into a raw
     * `\GuzzleHttp\HandlerStack` (bypassing Laravel's Http facade).
     */
    public function __invoke(callable $handler): callable
    {
        return function (RequestInterface $request, array $options) use ($handler): PromiseInterface {
            $startedAt = microtime(true);

            return $handler($request, $options)->then(
                function (ResponseInterface $response) use ($request, $startedAt): ResponseInterface {
                    $this->record($request, $response, $startedAt);

                    return $response;
                },
                function (mixed $reason) use ($request, $startedAt): mixed {
                    $this->record($request, null, $startedAt);
                    if ($reason instanceof Throwable) {
                        throw $reason;
                    }

                    return $reason;
                },
            );
        };
    }

    private function record(RequestInterface $request, ?ResponseInterface $response, ?float $startedAt): void
    {
        if (! $this->uptimex->isEnabled() || $this->uptimex->context() === null) {
            return;
        }

        $uri = $request->getUri();
        $host = (string) $uri->getHost();
        $url = $uri->getScheme().'://'.$host.$uri->getPath();

        $duration = $startedAt !== null ? (int) round((microtime(true) - $startedAt) * 1000) : null;

        $this->uptimex->record('outgoing_request', [
            'host' => mb_substr($host !== '' ? $host : '(unknown)', 0, 255),
            'method' => mb_substr($request->getMethod(), 0, 8),
            'url' => mb_substr($url, 0, 2048),
            'status' => $response?->getStatusCode(),
            'duration_ms' => $duration,
            'request_size_bytes' => $request->getBody()->getSize(),
            'response_size_bytes' => $response?->getBody()?->getSize(),
        ]);
    }
}
