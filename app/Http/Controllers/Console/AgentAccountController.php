<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Agent;
use App\Services\UssdApi;
use App\Services\UssdIngestor;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Adding agents and resetting PINs from the web app. Agents live in the USSD
 * service (they dial in there too), so these go through its API and the
 * returned agent is stored here. The PIN works on USSD and for the agent's
 * web sign-in.
 */
class AgentAccountController extends Controller
{
    public function store(Request $request, UssdApi $api, UssdIngestor $ingestor): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone_number' => ['required', 'string', 'max:20'],
            'polling_unit' => ['nullable', 'string', 'max:30'],
            'pin' => ['nullable', 'digits:4'],
            'sms_pin' => ['nullable', 'boolean'],
        ]);

        $response = $this->call($api, 'agents', [
            'name' => $validated['name'],
            'phone_number' => $validated['phone_number'],
            'polling_unit' => $validated['polling_unit'] ?? null,
            'pin' => $validated['pin'] ?? null,
            'sms_pin' => $request->boolean('sms_pin') ? 1 : 0,
            'by' => $request->user()->name,
        ]);

        if (is_string($response)) {
            return back()->withInput()->with('error', $response);
        }

        $agent = $ingestor->agent($response['agent']);
        $created = (bool) ($response['created'] ?? false);
        Audit::record($created ? 'agent.created' : 'agent.updated', ($created ? 'Added' : 'Updated')." agent {$agent->name} ({$agent->phone_number})".($agent->polling_unit_code ? " for PU {$agent->polling_unit_code}" : ''));

        return back()->with('status', ($created ? "Added {$agent->name}." : "Updated {$agent->name}.").$this->pinNote($response['pin'] ?? null, $request->boolean('sms_pin')));
    }

    public function resetPin(Request $request, Agent $agent, UssdApi $api, UssdIngestor $ingestor): RedirectResponse
    {
        $validated = $request->validate([
            'pin' => ['nullable', 'digits:4'],
            'sms_pin' => ['nullable', 'boolean'],
        ]);

        $response = $this->call($api, 'agents/reset-pin', [
            'phone_number' => $agent->phone_number,
            'pin' => $validated['pin'] ?? null,
            'sms_pin' => $request->boolean('sms_pin') ? 1 : 0,
            'by' => $request->user()->name,
        ]);

        if (is_string($response)) {
            return back()->with('error', $response);
        }

        $ingestor->agent($response['agent']);
        Audit::record('agent.pin_reset', "Reset the PIN for {$agent->name} ({$agent->phone_number})");

        return back()->with('status', "New PIN set for {$agent->name} (and unlocked).".$this->pinNote($response['pin'] ?? null, $request->boolean('sms_pin')));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|string the response body, or an error message
     */
    private function call(UssdApi $api, string $path, array $data): array|string
    {
        if (! $api->enabled()) {
            return 'The USSD service is not connected: set USSD_API_TOKEN in .env.';
        }

        try {
            $response = $api->post($path, array_filter($data, fn ($value) => $value !== null));
        } catch (Throwable $e) {
            report($e);

            return 'Could not reach the USSD service. Try again in a moment.';
        }

        if (! $response->successful()) {
            return (string) ($response->json('message') ?: collect($response->json('errors') ?? [])->flatten()->first() ?: 'The USSD service refused this ('.$response->status().').');
        }

        return (array) $response->json();
    }

    private function pinNote(?string $pin, bool $sent): string
    {
        if ($pin === null) {
            return ' Their PIN is unchanged.';
        }

        return $sent ? ' Their PIN was sent to them by SMS.' : " Their PIN is {$pin}: give it to them privately (it is not shown again).";
    }
}
