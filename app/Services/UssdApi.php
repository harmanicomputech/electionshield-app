<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Calls to the USSD service's API (server side only: the token exposes
 * every agent's phone number).
 */
class UssdApi
{
    public function enabled(): bool
    {
        return filled(config('services.ussd.api_url')) && filled(config('services.ussd.api_token'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function post(string $path, array $data = []): Response
    {
        return $this->client()->asForm()->post($path, $data);
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->client()->get($path, $query);
    }

    private function client(): PendingRequest
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Set USSD_API_URL and USSD_API_TOKEN in .env to reach the USSD service.');
        }

        return Http::baseUrl(config('services.ussd.api_url'))
            ->withToken(config('services.ussd.api_token'))
            ->acceptJson()
            ->timeout(config('services.ussd.timeout'));
    }
}
