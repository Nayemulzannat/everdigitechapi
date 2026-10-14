<?php

namespace App\Http\Resources;

use App\Models\ClientLoginHistory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property ClientLoginHistory $resource */
class ClientLoginHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        return [
            'id' => (string) $entry->getKey(),
            'ip_address' => $entry->ip_address,
            'browser' => $entry->browser,
            'platform' => $entry->platform,
            'successful' => $entry->successful,
            'failure_reason' => $entry->failure_reason,
            // True when a staff member signed in as this client ("log in as client").
            'impersonated' => $entry->impersonated_by_admin_id !== null,
            'created_at' => $entry->created_at,
        ];
    }
}
