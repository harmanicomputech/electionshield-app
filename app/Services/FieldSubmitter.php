<?php

namespace App\Services;

use App\Models\Agent;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Sends an agent's web submission to the USSD service, which applies its
 * rules and records it (SMS receipt, alerts, webhook, all as for USSD), and
 * stores the returned record here straight away so it shows at once. The
 * webhook that follows is a harmless duplicate.
 */
class FieldSubmitter
{
    public function __construct(private UssdApi $api, private UssdIngestor $ingestor) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{ok: bool, status: int, message: string, reference: ?string, retry: bool, record?: array<string, mixed>}
     */
    public function submit(string $kind, Agent $agent, array $data): array
    {
        [$path, $key] = match ($kind) {
            'result' => ['field/results', 'result'],
            'incident' => ['field/incidents', 'incident'],
            'presence' => ['field/presence', 'presence'],
            'materials' => ['field/materials', 'materials'],
        };

        if (! $this->api->enabled()) {
            return $this->fail(503, 'The USSD service is not connected. Tell your coordinator.', retry: true);
        }

        try {
            $response = $this->api->post($path, ['phone_number' => $agent->phone_number, ...$data]);
        } catch (ConnectionException) {
            return $this->fail(503, 'Could not reach the USSD service. It will be sent again when the connection is back.', retry: true);
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?: collect($response->json('errors') ?? [])->flatten()->first();

            return $this->fail($response->status(), (string) ($message ?: 'The USSD service could not take this ('.$response->status().').'), retry: $response->serverError());
        }

        $record = (array) $response->json($key);

        try {
            match ($kind) {
                'result' => $this->ingestor->result($record, (bool) ($record['rehearsal'] ?? false)),
                'incident' => $this->ingestor->incident($record, (bool) ($record['rehearsal'] ?? false)),
                'presence' => $this->ingestor->presence($record, (bool) ($record['rehearsal'] ?? false)),
                'materials' => $this->ingestor->materials($record, (bool) ($record['rehearsal'] ?? false)),
            };
        } catch (Throwable $e) {
            // Recorded on the USSD side; the webhook or sync brings it here.
            report($e);
        }

        return ['ok' => true, 'status' => 201, 'message' => (string) $response->json('message'), 'reference' => $record['reference'] ?? null, 'retry' => false, 'record' => $record];
    }

    /**
     * @return array{ok: bool, status: int, message: string, reference: ?string, retry: bool}
     */
    private function fail(int $status, string $message, bool $retry): array
    {
        return ['ok' => false, 'status' => $status, 'message' => $message, 'reference' => null, 'retry' => $retry];
    }
}
