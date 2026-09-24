<?php

namespace Tests\Concerns;

use Illuminate\Testing\TestResponse;

/**
 * Signed webhook deliveries and payloads in the USSD service's shapes
 * (see docs/WEB-APP-HANDOFF.md).
 */
trait SendsUssdEvents
{
    /**
     * @param  array<string, mixed>  $data
     */
    protected function sendEvent(string $event, array $data, ?string $key = null, ?string $secret = 'test-secret', ?string $token = 'test-token'): TestResponse
    {
        $body = json_encode(['event' => $event, 'sent_at' => '2027-02-06T15:04:11+00:00', 'data' => $data], JSON_UNESCAPED_SLASHES);

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Idempotency-Key' => $key ?? $event.':'.($data['reference'] ?? $data['id'] ?? 'x'),
            'X-Election-Shield-Event' => $event,
        ];

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        if ($secret !== null) {
            $headers['X-Election-Shield-Signature'] = 'sha256='.hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/api/ussd-events', [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function resultPayload(array $overrides = []): array
    {
        $votes = $overrides['votes'] ?? ['APC' => 610, 'PDP' => 402, 'LP' => 95, 'OTHERS' => 18];

        return array_replace([
            'reference' => 'RS784321',
            'status' => 'accepted',
            'polling_unit' => $this->unit(),
            'accredited_voters' => 1200,
            'votes' => $votes,
            'total_valid_votes' => array_sum($votes),
            'rejected_votes' => 21,
            'total_votes_cast' => array_sum($votes) + 21,
            'corrects_reference' => null,
            'agent' => ['name' => 'Ada Obi', 'phone_number' => '+2348012345678'],
            'submitted_at' => '2027-02-06T15:04:10+00:00',
            'reviewed_at' => null,
            'reviewed_by' => null,
            'review_note' => null,
            'rehearsal' => false,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    protected function unit(string $code = '21202633007', string $lga = 'Abakaliki', string $ward = 'Abakaliki Ward 01', int $registered = 1507): array
    {
        return ['code' => $code, 'name' => "Polling Unit {$code}", 'ward' => $ward, 'lga' => $lga, 'registered_voters' => $registered];
    }
}
