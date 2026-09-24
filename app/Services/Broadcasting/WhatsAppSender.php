<?php

namespace App\Services\Broadcasting;

use App\Models\Broadcast;
use App\Models\BroadcastMessage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * WhatsApp Business Platform (Meta Cloud API). Outside a 24-hour customer
 * conversation only pre-approved templates can be sent, so a broadcast
 * names a template; the message field holds its body parameters, one per
 * line, where {name} becomes the recipient's name.
 */
class WhatsAppSender
{
    public function configured(): bool
    {
        return filled(config('services.whatsapp.phone_number_id')) && filled(config('services.whatsapp.token'));
    }

    /**
     * @param  Collection<int, BroadcastMessage>  $messages
     */
    public function send(Broadcast $broadcast, Collection $messages): void
    {
        $config = config('services.whatsapp');

        if (! $this->configured()) {
            throw new RuntimeException('Set WHATSAPP_PHONE_NUMBER_ID and WHATSAPP_TOKEN in .env to send WhatsApp messages.');
        }

        $parameters = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $broadcast->message) ?: []), fn ($line) => $line !== ''));

        foreach ($messages as $message) {
            $template = ['name' => $broadcast->template, 'language' => ['code' => $broadcast->template_language ?: 'en']];

            if ($parameters !== []) {
                $template['components'] = [[
                    'type' => 'body',
                    'parameters' => array_map(fn ($text) => ['type' => 'text', 'text' => str_replace('{name}', $message->name ?: 'friend', $text)], $parameters),
                ]];
            }

            try {
                $id = Http::withToken($config['token'])
                    ->acceptJson()
                    ->timeout(20)
                    ->post("https://graph.facebook.com/{$config['api_version']}/{$config['phone_number_id']}/messages", [
                        'messaging_product' => 'whatsapp',
                        'to' => ltrim($message->phone, '+'),
                        'type' => 'template',
                        'template' => $template,
                    ])
                    ->throw()
                    ->json('messages.0.id');

                $message->forceFill(['status' => 'sent', 'provider_id' => $id, 'failure_reason' => null, 'sent_at' => now()])->save();
            } catch (RequestException $e) {
                // A bad number or template fails this message, not the batch.
                if ($e->response->serverError()) {
                    throw $e;
                }

                $message->forceFill([
                    'status' => 'failed',
                    'failure_reason' => Str::limit((string) ($e->response->json('error.message') ?? $e->getMessage()), 250),
                    'sent_at' => now(),
                ])->save();
            }
        }
    }
}
