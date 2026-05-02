<?php

namespace Uptimex\Client\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Default transport: gzip-encoded JSON POST to `{ingest_url}/api/ingest/events`
 * with bearer-token auth. Tightly bounded by request + connection timeouts so
 * a sluggish or unreachable UptimeX server never slows the host application.
 *
 * Failures are swallowed and logged at warning level — the SDK is observability
 * infrastructure, not bookkeeping. A dropped batch is acceptable; a thrown
 * exception bubbling into the host's request handler is not.
 */
final class HttpTransport implements Transport
{
    public function __construct(
        private readonly Client $http,
        private readonly string $ingestUrl,
        private readonly string $token,
        private readonly float $timeout = 0.5,
        private readonly float $connectTimeout = 0.5,
    ) {}

    public function send(array $batch): bool
    {
        try {
            $body = json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $compressed = gzencode($body, level: 6);
        } catch (Throwable $e) {
            Log::warning('uptimex.transport.encode_failed', ['exception' => $e->getMessage()]);

            return false;
        }

        try {
            $response = $this->http->post(rtrim($this->ingestUrl, '/').'/api/ingest/events', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                    'Content-Encoding' => 'gzip',
                    'Accept' => 'application/json',
                ],
                'body' => $compressed,
                'timeout' => $this->timeout,
                'connect_timeout' => $this->connectTimeout,
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            Log::warning('uptimex.transport.non_2xx', [
                'status' => $status,
                'body_preview' => substr((string) $response->getBody(), 0, 500),
            ]);

            return false;
        } catch (GuzzleException|Throwable $e) {
            Log::warning('uptimex.transport.network_failed', ['exception' => $e->getMessage()]);

            return false;
        }
    }

    public function sendDeploy(array $payload): ?array
    {
        try {
            $response = $this->http->post(rtrim($this->ingestUrl, '/').'/api/ingest/deploy', [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => $payload,
                // Deploys aren't on the request hot path — give them more room
                // than the per-request batch transport.
                'timeout' => 5.0,
                'connect_timeout' => 2.0,
                'http_errors' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $decoded = json_decode((string) $response->getBody(), true);

                return is_array($decoded) ? $decoded : ['ok' => true];
            }

            Log::warning('uptimex.deploy.non_2xx', [
                'status' => $status,
                'body_preview' => substr((string) $response->getBody(), 0, 500),
            ]);

            return null;
        } catch (GuzzleException|Throwable $e) {
            Log::warning('uptimex.deploy.network_failed', ['exception' => $e->getMessage()]);

            return null;
        }
    }
}
