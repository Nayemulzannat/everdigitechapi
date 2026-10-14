<?php

namespace App\Http\Resources;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A domain in the admin panel (camelCase). Load `account` first; `events` when it is the detail. @property Domain $resource */
class AdminDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $d = $this->resource;

        return [
            'id' => (string) $d->getKey(),
            'name' => $d->name,
            'account' => ['id' => (string) $d->account_id, 'name' => $d->account?->name],
            'registrar' => $d->registrar,
            'status' => $d->status->value,
            'registeredAt' => $d->registered_at?->toDateString(),
            'expiresAt' => $d->expires_at?->toDateString(),
            'daysLeft' => $d->daysLeft(),
            'autoRenew' => $d->auto_renew,
            'locked' => $d->locked,
            'whoisPrivacy' => $d->whois_privacy,
            'nameservers' => array_values((array) ($d->nameservers ?? [])),
            'notes' => $d->notes,
            $this->mergeWhen($d->relationLoaded('events'), fn () => [
                'events' => $d->events->map(fn ($event) => [
                    'type' => $event->type,
                    'by' => $event->admin?->name,
                    'properties' => $event->properties,
                    'createdAt' => $event->created_at->toIso8601ZuluString(),
                ])->all(),
            ]),
        ];
    }
}
