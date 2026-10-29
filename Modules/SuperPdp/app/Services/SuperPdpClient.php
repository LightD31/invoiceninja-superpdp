<?php

namespace Modules\SuperPdp\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Modules\SuperPdp\Exceptions\SuperPdpException;

/**
 * Thin client for the SUPER PDP v1.beta API (OAuth2 client credentials).
 *
 * @see https://www.superpdp.tech/openapi
 */
class SuperPdpClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $clientId,
        private readonly ?string $clientSecret,
    ) {}

    public function configured(): bool
    {
        return filled($this->clientId) && filled($this->clientSecret);
    }

    /** GET /companies/me, cached for an hour. */
    public function me(bool $fresh = false): array
    {
        $key = $this->cacheKey('me');

        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, 3600, fn () => $this->json('get', '/v1.beta/companies/me'));
    }

    /**
     * Turn an EN16931 JSON invoice plus the human-readable PDF into a
     * Factur-X. Works without credentials (public endpoint).
     */
    public function convertToFacturX(array $invoice, string $pdf, string $filename): string
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->attach('invoice', json_encode($invoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'invoice.json', ['Content-Type' => 'application/json'])
            ->attach('pdf', $pdf, $filename, ['Content-Type' => 'application/pdf'])
            ->post('/v1.beta/invoices/convert?from=en16931&to=factur-x'), auth: $this->configured());

        return $response->body();
    }

    /** Convert an EN16931 JSON invoice to CII XML (used by dry runs). */
    public function convertToCii(array $invoice): string
    {
        return $this->call(fn (PendingRequest $http) => $http
            ->withBody(json_encode($invoice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), 'application/json')
            ->post('/v1.beta/invoices/convert?from=en16931&to=cii'), auth: $this->configured())->body();
    }

    /** POST /validation_reports for one file; returns its report. */
    public function validate(string $content, string $filename, string $mime = 'application/pdf'): array
    {
        $response = $this->call(fn (PendingRequest $http) => $http
            ->attach('file', $content, $filename, ['Content-Type' => $mime])
            ->post('/v1.beta/validation_reports'), auth: $this->configured());

        return $response->json('data.0') ?? throw new SuperPdpException('Empty validation report', $response->status(), $response->body());
    }

    /** POST /invoices with a Factur-X body. Returns the SUPER PDP invoice. */
    public function sendInvoice(string $facturx, string $externalId, string $processingRule, bool $disablePreCheck = false): array
    {
        $query = http_build_query(array_filter([
            'external_id' => mb_substr($externalId, 0, 64),
            'processing_rule' => $processingRule,
            'disable_pre_check' => $disablePreCheck ? 'true' : null,
        ]));

        return $this->call(fn (PendingRequest $http) => $http
            ->withBody($facturx, 'application/pdf')
            ->post('/v1.beta/invoices?'.$query))->json();
    }

    /** GET /invoices/{id} as JSON (format en16931) or raw bytes for another format. */
    public function invoice(int $id, ?string $format = null): array|string
    {
        $response = $this->call(fn (PendingRequest $http) => $http->get("/v1.beta/invoices/{$id}", array_filter(['format' => $format])));

        return in_array($format, [null, 'en16931'], true) ? $response->json() : $response->body();
    }

    /** GET /invoices page (ids strictly greater than $after). */
    public function invoices(string $direction, int $after, int $limit = 100): array
    {
        return $this->json('get', '/v1.beta/invoices', [
            'direction' => $direction,
            'starting_after_id' => $after,
            'limit' => $limit,
        ]);
    }

    /** The newest invoices first. */
    public function recentInvoices(string $direction, int $limit = 100): array
    {
        return $this->json('get', '/v1.beta/invoices', [
            'direction' => $direction,
            'order' => 'desc',
            'limit' => $limit,
        ]);
    }

    /** GET /invoice_events page (ids strictly greater than $after). */
    public function events(int $after, int $limit = 500): array
    {
        return $this->json('get', '/v1.beta/invoice_events', [
            'starting_after_id' => $after,
            'limit' => $limit,
        ]);
    }

    /** POST /invoice_events (e.g. fr:212). */
    public function createEvent(array $payload): array
    {
        return $this->call(fn (PendingRequest $http) => $http->asJson()->post('/v1.beta/invoice_events', $payload))->json();
    }

    /** Active entries of the French e-invoicing directory for a SIREN. */
    public function directoryEntries(string $siren): array
    {
        return $this->json('get', '/v1.beta/french_directory/entries', ['number' => $siren])['data'] ?? [];
    }

    private function json(string $method, string $path, array $query = []): array
    {
        return $this->call(fn (PendingRequest $http) => $http->{$method}($path, $query))->json() ?? [];
    }

    /**
     * Run a request with the bearer token, refreshing it once on 401, and
     * turn every non-2xx answer into a SuperPdpException.
     */
    private function call(callable $send, bool $auth = true): Response
    {
        for ($attempt = 0; ; $attempt++) {
            $http = Http::baseUrl($this->baseUrl)->acceptJson()->timeout(120)->connectTimeout(15);

            if ($auth) {
                $http = $http->withToken($this->token($attempt > 0));
            }

            try {
                $response = $send($http);
            } catch (ConnectionException $e) {
                throw new SuperPdpException('SUPER PDP unreachable: '.$e->getMessage());
            }

            if ($response->status() === 401 && $auth && $attempt === 0) {
                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            throw new SuperPdpException(
                sprintf('SUPER PDP HTTP %d: %s', $response->status(), $response->json('message') ?? mb_substr($response->body(), 0, 500)),
                $response->status(),
                $response->body(),
            );
        }
    }

    private function token(bool $fresh = false): string
    {
        if (! $this->configured()) {
            throw new SuperPdpException('SUPERPDP_CLIENT_ID / SUPERPDP_CLIENT_SECRET are not set', 401);
        }

        $key = $this->cacheKey('token');

        if (! $fresh && ($token = Cache::get($key))) {
            return $token;
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(30)->post($this->baseUrl.'/oauth2/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
        } catch (ConnectionException $e) {
            throw new SuperPdpException('SUPER PDP unreachable: '.$e->getMessage());
        }

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new SuperPdpException('SUPER PDP token request failed (HTTP '.$response->status().')', $response->status() ?: 401);
        }

        $ttl = max(60, (int) $response->json('expires_in', 1800) - 120);
        Cache::put($key, $response->json('access_token'), $ttl);

        return $response->json('access_token');
    }

    private function cacheKey(string $what): string
    {
        return 'superpdp:'.$what.':'.sha1($this->baseUrl.'|'.$this->clientId);
    }
}
