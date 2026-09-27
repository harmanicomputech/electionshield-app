<?php

namespace App\Services\Irev;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * INEC's IReV result viewing portal, read the way its own web page reads it.
 * INEC publishes no official API; this follows the JSON routes the IReV web
 * app uses (IREV_API_URL, https://dolphin-app-sleqh.ondigitalocean.app/api/v1
 * as of 2026). Elections are addressed by their Mongo `_id` in the path and
 * their integer `election_id` in the query. IReV gives each polling unit's
 * EC8A image link and upload time, never the figures.
 */
class IrevClient
{
    /**
     * @return list<array<string, mixed>>
     */
    public function elections(): array
    {
        return (array) ($this->get('elections')['data'] ?? []);
    }

    /**
     * LGAs of the election's state, each with its wards.
     *
     * @return list<array<string, mixed>>
     */
    public function lgas(string $electionOid, int $electionId): array
    {
        return (array) ($this->get("elections/{$electionOid}/lga", ['election' => $electionId])['data'] ?? []);
    }

    /**
     * The polling units of a ward with their EC8A uploads.
     *
     * @return list<array<string, mixed>>
     */
    public function pollingUnits(string $electionOid, int $electionId, string $wardOid): array
    {
        return (array) ($this->get("elections/{$electionOid}/pus", ['ward' => $wardOid, 'election' => $electionId])['data'] ?? []);
    }

    /**
     * The most recent uploads for the election (a quick check between full sweeps).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $electionOid, int $electionId): array
    {
        $body = $this->get("elections/{$electionOid}/pus/recent", ['election' => $electionId]);

        return (array) ($body['data'] ?? []);
    }

    /**
     * Download an EC8A image. IReV's image store only serves requests that
     * look like they come from the IReV site, so this sends its address.
     *
     * @return array{0: string, 1: string} bytes and MIME type
     */
    public function download(string $url): array
    {
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('Not an https link.');
        }

        $response = $this->http()->accept('image/*,application/pdf')->timeout(60)->get($url);

        if ($response->status() === 403) {
            throw new IrevBlocked('IReV refused the download (403): its image store only serves approved sites.');
        }

        if (! $response->successful()) {
            throw new RuntimeException("IReV answered {$response->status()} for the sheet.");
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        if (! in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            $type = match (true) {
                str_starts_with($body, "\xFF\xD8") => 'image/jpeg',
                str_starts_with($body, "\x89PNG") => 'image/png',
                str_starts_with($body, '%PDF') => 'application/pdf',
                default => throw new RuntimeException("IReV sent something that isn't a sheet ({$type})."),
            };
        }

        return [$body, $type];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->http()->acceptJson()->timeout(30)->retry(2, 1000, throw: false)
            ->get(rtrim((string) config('services.irev.api_url'), '/').'/'.$path, $query);

        if (! $response->successful() || $response->json('success') === false) {
            throw new RuntimeException("IReV answered {$response->status()} for /{$path}".($response->json('message') ? ': '.$response->json('message') : '').'.');
        }

        return (array) $response->json();
    }

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'Referer' => 'https://www.inecelectionresults.ng/',
            'Origin' => 'https://www.inecelectionresults.ng',
            'User-Agent' => 'Mozilla/5.0 (compatible; ElectionShield/1.0; party situation room)',
        ]);
    }
}
