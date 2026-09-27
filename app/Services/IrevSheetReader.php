<?php

namespace App\Services;

use Anthropic\Client;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads the figures off a scanned EC8A result sheet (an IReV image or PDF,
 * or a photo) with Claude, so a person only has to check them. The reading
 * never sees our agent's figures, and nothing is saved until a person
 * presses Save on the official result form.
 */
class IrevSheetReader
{
    public const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public const MAX_BYTES = 20 * 1024 * 1024;

    public function enabled(): bool
    {
        return filled(config('services.anthropic.key'));
    }

    /**
     * Download a sheet from an IReV document link (https, on an allowed host).
     *
     * @return array{0: string, 1: string} the file's bytes and MIME type
     */
    public function download(string $url): array
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $allowed = collect(config('services.irev.document_hosts'))->contains(fn (string $suffix) => $host === $suffix || str_ends_with($host, '.'.$suffix));

        if (($parts['scheme'] ?? '') !== 'https' || ! $allowed) {
            throw new RuntimeException('Paste the link of the sheet on IReV (an https link on '.implode(', ', config('services.irev.document_hosts')).').');
        }

        $response = Http::timeout(30)->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]])->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("IReV answered {$response->status()} for that link.");
        }

        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $body = $response->body();

        if (strlen($body) > self::MAX_BYTES) {
            throw new RuntimeException('That file is larger than 20 MB.');
        }

        return [$body, $type === 'application/octet-stream' ? $this->sniff($body) : $type];
    }

    /**
     * @param  list<string>  $parties
     * @return array{legible: bool, pu_code: ?string, accredited_voters: ?int, rejected_votes: ?int, votes: array<string, ?int>, notes: ?string}
     */
    public function read(string $bytes, string $mime, array $parties): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('Set ANTHROPIC_API_KEY in .env to read sheets with AI.');
        }

        if ($mime === 'application/pdf') {
            $sheet = ['type' => 'document', 'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($bytes)]];
        } elseif (in_array($mime, self::IMAGE_TYPES, true)) {
            $sheet = ['type' => 'image', 'source' => ['type' => 'base64', 'mediaType' => $mime, 'data' => base64_encode($bytes)]];
        } else {
            throw new RuntimeException('Use a JPEG, PNG or PDF of the result sheet.');
        }

        $text = $this->ask([$sheet, ['type' => 'text', 'text' => $this->instructions($parties)]], $this->schema($parties));
        $data = json_decode($text, true);

        if (! is_array($data)) {
            throw new RuntimeException('The sheet could not be read. Enter the figures by hand.');
        }

        $number = fn ($value) => is_numeric($value) && $value >= 0 ? (int) $value : null;

        return [
            'legible' => (bool) ($data['legible'] ?? false),
            'pu_code' => filled($data['pu_code'] ?? null) ? (string) $data['pu_code'] : null,
            'accredited_voters' => $number($data['accredited_voters'] ?? null),
            'rejected_votes' => $number($data['rejected_votes'] ?? null),
            'votes' => collect($parties)->mapWithKeys(fn (string $party) => [$party => $number($data['votes'][$party] ?? null)])->all(),
            'notes' => filled($data['notes'] ?? null) ? mb_substr((string) $data['notes'], 0, 500) : null,
        ];
    }

    /**
     * One request to Claude; returns the JSON text of the answer.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     */
    protected function ask(array $content, array $schema): string
    {
        $client = new Client(apiKey: config('services.anthropic.key'), baseUrl: config('services.anthropic.base_url') ?: null);

        $message = $client->beta->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: 4000,
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: ['effort' => 'medium', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            // If the model declines, the API retries on a fallback model.
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The AI would not read this file. Enter the figures by hand.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new RuntimeException('The AI gave no answer. Enter the figures by hand.');
    }

    /**
     * @param  list<string>  $parties
     */
    private function instructions(array $parties): string
    {
        return 'This is a Nigerian INEC Form EC8A (polling unit result sheet) for a governorship election, '
            .'as published on IReV or photographed at the polling unit. Read the figures exactly as written on the sheet, '
            .'using the figures in words to settle any digit that is unclear. Report: the polling unit code as printed, '
            .'the number of accredited voters, the number of rejected ballots, and the votes for each of these parties: '
            .implode(', ', $parties).'. '
            .(in_array('OTHERS', $parties, true) ? 'For OTHERS, add up the votes of every party not listed separately. ' : '')
            .'Use null for any figure you cannot read with confidence; never guess. Set legible to false if the sheet is not an EC8A '
            .'or is too unclear to read. In notes, mention anything a checker should look at (overwritten or altered figures, '
            .'missing signatures or stamp, totals that do not add up).';
    }

    /**
     * @param  list<string>  $parties
     * @return array<string, mixed>
     */
    private function schema(array $parties): array
    {
        $count = ['type' => ['integer', 'null']];

        return [
            'type' => 'object',
            'properties' => [
                'legible' => ['type' => 'boolean'],
                'pu_code' => ['type' => ['string', 'null']],
                'accredited_voters' => $count,
                'rejected_votes' => $count,
                'votes' => [
                    'type' => 'object',
                    'properties' => collect($parties)->mapWithKeys(fn (string $party) => [$party => $count])->all(),
                    'required' => $parties,
                    'additionalProperties' => false,
                ],
                'notes' => ['type' => ['string', 'null']],
            ],
            'required' => ['legible', 'pu_code', 'accredited_voters', 'rejected_votes', 'votes', 'notes'],
            'additionalProperties' => false,
        ];
    }

    private function sniff(string $bytes): string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF') => 'application/pdf',
            str_starts_with($bytes, "\xFF\xD8") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
