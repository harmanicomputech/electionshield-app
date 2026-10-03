<?php

namespace App\Services\Ai;

use Anthropic\Client;
use App\Support\Settings;
use RuntimeException;

/**
 * One structured request to Claude for the election-day assistance
 * (incident triage, result photo checks, situation briefs). Answers are
 * JSON that matches the given schema. The AI only ever suggests: nothing it
 * returns changes a result or an incident's status.
 */
class Claude
{
    /**
     * Configured (an API key) and not switched off on the System page.
     */
    public function enabled(): bool
    {
        return filled(config('services.anthropic.key')) && Settings::get('ai.assist', '1') === '1';
    }

    /**
     * @param  list<array<string, mixed>>|string  $content  text, or content blocks (images, documents, text)
     * @param  array<string, mixed>  $schema  JSON schema of the answer
     * @return array<string, mixed>
     */
    public function json(array|string $content, array $schema, string $effort = 'low', int $maxTokens = 4000): array
    {
        if (! $this->enabled()) {
            throw new RuntimeException('AI assistance is off: set ANTHROPIC_API_KEY in .env and switch it on on the System page.');
        }

        $data = json_decode($this->request(is_string($content) ? [['type' => 'text', 'text' => $content]] : $content, $schema, $effort, $maxTokens), true);

        if (! is_array($data)) {
            throw new RuntimeException('The AI answer could not be read.');
        }

        return $data;
    }

    /**
     * The request itself; returns the JSON text of the answer.
     *
     * @param  list<array<string, mixed>>  $content
     * @param  array<string, mixed>  $schema
     */
    protected function request(array $content, array $schema, string $effort, int $maxTokens): string
    {
        $client = new Client(apiKey: config('services.anthropic.key'), baseUrl: config('services.anthropic.base_url') ?: null);

        $message = $client->beta->messages->create(
            model: config('services.anthropic.model'),
            maxTokens: $maxTokens,
            messages: [['role' => 'user', 'content' => $content]],
            outputConfig: ['effort' => $effort, 'format' => ['type' => 'json_schema', 'schema' => $schema]],
            // If the model declines, the API retries on a fallback model.
            betas: ['server-side-fallback-2026-07-01'],
            fallbacks: 'default',
        );

        if ($message->stopReason === 'refusal') {
            throw new RuntimeException('The AI declined this one.');
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                return $block->text;
            }
        }

        throw new RuntimeException('The AI gave no answer.');
    }
}
