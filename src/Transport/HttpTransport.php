<?php

namespace Uptimex\Client\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Psr\Http\Message\ResponseInterface;
use Throwable;
use Uptimex\Client\Support\LogThrottle;

/**
 * Default transport: gzip-encoded JSON POST to `{ingest_url}/api/ingest/events`
 * with bearer-token auth. Tightly bounded by request + connection timeouts so
 * a sluggish or unreachable UptimeX server never slows the host application.
 *
 * Failures are swallowed and logged at warning level — the SDK is observability
 * infrastructure, not bookkeeping. A dropped batch is acceptable; a thrown
 * exception bubbling into the host's request handler is not.
 *
 * The ingest URL is not customer-configurable — it ships hardcoded in the
 * package config (`ingest_url`), because the host is identical for every cloud
 * tenant and the bearer token is what identifies the workspace. A self-hosted
 * install is the one case that overrides it, in a published config copy.
 * Either way the scheme is forced to HTTPS in the constructor: an `http://`
 * URL is silently upgraded so telemetry never crosses the wire in plaintext.
 * Genuinely-local dev hosts (localhost, 127.0.0.1, *.test, *.localhost) are
 * exempt — they legitimately run without a TLS cert under `artisan serve` or
 * Herd.
 *
 * Redirects are still deliberately NOT followed, as defense-in-depth. If a URL
 * slips past normalization that the server answers with a 3xx, Guzzle would
 * silently downgrade the POST to a GET while following it → the server answers
 * 405 "GET not supported", a symptom three hops removed from the cause. With
 * redirects off, the SDK sees the raw 3xx and logs an actionable hint instead.
 */
final class HttpTransport implements Transport
{
    private readonly string $ingestUrl;

    public function __construct(
        private readonly Client $http,
        string $ingestUrl,
        private readonly string $token,
        private readonly float $timeout = 0.5,
        private readonly float $connectTimeout = 0.5,
    ) {
        $this->ingestUrl = $this->normalizeIngestUrl($ingestUrl);
    }

    public function send(array $batch): bool
    {
        try {
            $body = json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $compressed = gzencode($body, level: 6);
        } catch (Throwable $e) {
            LogThrottle::warn('uptimex.transport.encode_failed', 'uptimex.transport.encode_failed', ['exception' => $e->getMessage()]);

            return false;
        }

        try {
            $response = $this->http->post($this->endpoint('events'), [
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
                'allow_redirects' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                return true;
            }

            $this->logBadStatus('uptimex.transport', $status, $response);

            return false;
        } catch (GuzzleException|Throwable $e) {
            LogThrottle::warn('uptimex.transport.network_failed', 'uptimex.transport.network_failed', ['exception' => $e->getMessage()]);

            return false;
        }
    }

    public function sendDeploy(array $payload): ?array
    {
        try {
            $response = $this->http->post($this->endpoint('deploy'), [
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
                'allow_redirects' => false,
            ]);

            $status = $response->getStatusCode();
            if ($status >= 200 && $status < 300) {
                $decoded = json_decode((string) $response->getBody(), true);

                return is_array($decoded) ? $decoded : ['ok' => true];
            }

            $this->logBadStatus('uptimex.deploy', $status, $response);

            return null;
        } catch (GuzzleException|Throwable $e) {
            Log::warning('uptimex.deploy.network_failed', ['exception' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Force the ingest URL onto HTTPS.
     *
     * The cloud ingest URL ships hardcoded as HTTPS, so this is a safety net
     * for a self-hosted install that overrides `ingest_url` in a published
     * config: an `http://` value ships telemetry in plaintext and trips the
     * server's HTTPS redirect, so it is upgraded here. Genuinely-local dev
     * hosts (localhost, 127.0.0.1, ::1, *.localhost, *.test) are exempt — they
     * legitimately run without a TLS cert under `artisan serve` or Herd.
     */
    private function normalizeIngestUrl(string $url): string
    {
        $url = trim($url);

        if (! str_starts_with(strtolower($url), 'http://')) {
            return $url;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        $isLocalHost = in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test');

        if ($isLocalHost) {
            return $url;
        }

        return 'https://'.substr($url, 7);
    }

    /**
     * Build the absolute ingest endpoint URL for a given path segment.
     */
    private function endpoint(string $path): string
    {
        return rtrim($this->ingestUrl, '/').'/api/ingest/'.$path;
    }

    /**
     * Log a non-2xx response. A 3xx gets a dedicated `*.redirect` channel
     * with a concrete hint — the SDK never follows redirects, because doing
     * so would silently downgrade the POST to a GET and surface a baffling
     * 405 three hops removed from the real cause.
     */
    private function logBadStatus(string $prefix, int $status, ResponseInterface $response): void
    {
        if ($status >= 300 && $status < 400) {
            LogThrottle::warn($prefix.'.redirect', $prefix.'.redirect', [
                'status' => $status,
                'location' => $response->getHeaderLine('Location'),
                'hint' => 'The ingest endpoint returned a redirect, which the SDK deliberately '
                    .'does not follow — that would silently downgrade the POST to a GET. The '
                    .'cloud ingest URL never redirects; if you run a self-hosted UptimeX '
                    .'server, check that `ingest_url` in your published config/uptimex.php is '
                    .'the bare server origin over https:// with no trailing path.',
            ]);

            return;
        }

        LogThrottle::warn($prefix.'.non_2xx', $prefix.'.non_2xx', [
            'status' => $status,
            'body_preview' => substr((string) $response->getBody(), 0, 500),
        ]);
    }
}
