<?php

namespace App\Http\Resources;

use App\Models\ClientAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The frontend's Client (types/clients.ts), one row of the Clients list. Load `owner` first.
 *
 * @property ClientAccount $resource
 */
class ClientResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->resource;

        return [
            'id' => (string) $account->getKey(),
            'name' => $account->name,
            'email' => $account->owner->email,
            'company' => $account->company,
            // TODO: replace the tmp_* columns with the real counts and sums once services, domains and invoices exist.
            'services' => $account->tmp_services_count,
            'domains' => $account->tmp_domains_count,
            'balanceDue' => (float) $account->tmp_balance_due,
            'overdue' => $account->tmp_has_overdue,
            'group' => $account->client_group->value,
            'status' => $account->status->value,
            // A calendar date in the business timezone, not UTC.
            'joinedOn' => $account->created_at->copy()->timezone(config('everdigitech.timezone'))->toDateString(),
        ];
    }
}
