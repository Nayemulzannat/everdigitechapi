<?php

namespace App\Http\Resources;

use App\Models\ClientSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One signed-in browser, for the "Your devices" list. `id` is the row id: the Laravel session id is a secret and
 * never leaves the server.
 *
 * @property ClientSession $resource
 */
class ClientSessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $session = $this->resource;

        return [
            'id' => (string) $session->getKey(),
            'device' => implode(' on ', array_filter([$session->browser, $session->platform])) ?: 'Unknown device',
            'browser' => $session->browser,
            'platform' => $session->platform,
            'ip_address' => $session->ip_address,
            'last_active_at' => $session->last_active_at,
            'is_current' => $request->hasSession() && $session->session_id === $request->session()->getId(),
        ];
    }
}
