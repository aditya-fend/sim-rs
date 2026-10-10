<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * architecture.md §Integration — outbound webhook subscribers.
 *
 * The dispatcher already fans clinical events out to whatever is registered;
 * this is the missing half, the administration of that registry. Registration
 * is admin-only because an endpoint is a standing exfiltration route for
 * patient data, so the blast radius of a mistake is the whole record set.
 */
final class WebhookController extends Controller
{
    public function index(): View
    {
        return view('admin.webhooks.index', [
            'endpoints' => WebhookEndpoint::query()->orderBy('label')->get(),
            // Read from the dispatcher so the form cannot offer a
            // subscription that would never fire.
            'events' => WebhookDispatcher::EVENTS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', WebhookDispatcher::EVENTS)],
        ], [
            'events.required' => 'Pilih minimal satu event.',
            'events.*.in' => 'Event tidak dikenal.',
            'url.required' => 'URL endpoint wajib diisi.',
        ]);

        // The shared secret is generated, never supplied: a subscriber that
        // chooses its own secret can also be replayed by whoever chose it.
        WebhookEndpoint::create([
            'label' => $validated['label'],
            'url' => $validated['url'],
            'events' => $validated['events'],
            'secret_encrypted' => Str::random(48),
            'is_active' => true,
        ]);

        return back()->with('status', 'Endpoint webhook terdaftar. Berikan secret ini kepada integrator.');
    }

    public function update(Request $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url', 'max:500'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:'.implode(',', WebhookDispatcher::EVENTS)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $webhookEndpoint->update([
            'label' => $validated['label'],
            'url' => $validated['url'],
            'events' => $validated['events'],
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return back()->with('status', 'Endpoint webhook diperbarui.');
    }

    public function destroy(WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $webhookEndpoint->delete();

        return back()->with('status', 'Endpoint webhook dihapus.');
    }
}
