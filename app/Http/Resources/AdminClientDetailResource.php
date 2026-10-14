<?php

namespace App\Http\Resources;

use App\Models\ClientAccount;
use App\Models\ClientLoginHistory;
use App\Models\Domain;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One client (an account) for its page in the admin panel: who, the team, and a look at tickets, domains and sign-ins. @property ClientAccount $resource */
class AdminClientDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $account = $this->resource;
        $account->loadMissing(['owner', 'members.user']);
        $memberIds = $account->members->pluck('user_id');

        $tickets = Ticket::where('account_id', $account->getKey());
        $domains = Domain::where('account_id', $account->getKey());
        $owner = $account->owner;

        return [
            'id' => (string) $account->getKey(),
            'name' => $account->name,
            'company' => $account->company,
            'group' => $account->client_group->value,
            'status' => $account->status->value,
            'notes' => $account->notes,
            'creditBalance' => (float) $account->credit_balance,
            'joinedOn' => $account->created_at->copy()->timezone(config('everdigitech.timezone'))->toDateString(),
            'owner' => $owner ? [
                'id' => (string) $owner->getKey(),
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->phone,
                'emailVerified' => $owner->hasVerifiedEmail(),
                'phoneVerified' => $owner->hasVerifiedPhone(),
                'twoFactorOn' => $owner->hasTwoFactorEnabled(),
                'suspended' => $owner->isSuspended(),
                'lastLoginAt' => $owner->last_login_at?->toIso8601ZuluString(),
            ] : null,
            'members' => $account->members->sortBy(fn ($m) => [$m->role->value === 'owner' ? 0 : 1, $m->id])->values()->map(fn ($m) => [
                'id' => (string) $m->getKey(),
                'userId' => (string) $m->user_id,
                'name' => $m->user?->name,
                'email' => $m->user?->email,
                'role' => $m->role->value,
                'status' => $m->status->value,
                'joinedAt' => $m->joined_at?->toDateString(),
                'lastLoginAt' => $m->user?->last_login_at?->toIso8601ZuluString(),
            ])->all(),
            'stats' => [
                // TODO: services, balance and overdue come from the placeholder columns until services and invoices exist.
                'services' => $account->tmp_services_count,
                'balanceDue' => (float) $account->tmp_balance_due,
                'overdue' => $account->tmp_has_overdue,
                'domains' => (clone $domains)->count(),
                'domainsEndingSoon' => (clone $domains)->where('status', 'active')->whereBetween('expires_at', [now()->toDateString(), now()->addDays(30)->toDateString()])->count(),
                'tickets' => (clone $tickets)->count(),
                'ticketsOpen' => (clone $tickets)->where('status', 'open')->count(),
            ],
            'recentTickets' => (clone $tickets)->latest('last_reply_at')->latest('id')->limit(5)->get()->map(fn (Ticket $t) => [
                'id' => (string) $t->getKey(),
                'number' => $t->number,
                'subject' => $t->subject,
                'status' => $t->status->value,
                'priority' => $t->priority->value,
                'lastReplyAt' => $t->last_reply_at?->toIso8601ZuluString(),
            ])->all(),
            'recentDomains' => (clone $domains)->orderByRaw('expires_at is null')->orderBy('expires_at')->limit(5)->get()->map(fn (Domain $d) => [
                'id' => (string) $d->getKey(),
                'name' => $d->name,
                'status' => $d->status->value,
                'expiresAt' => $d->expires_at?->toDateString(),
            ])->all(),
            'recentLogins' => ClientLoginHistory::with('user:id,name')->whereIn('client_user_id', $memberIds)->latest('id')->limit(10)->get()->map(fn (ClientLoginHistory $h) => [
                'id' => (string) $h->getKey(),
                'userName' => $h->user?->name,
                'ip' => $h->ip_address,
                'browser' => $h->browser,
                'platform' => $h->platform,
                'successful' => $h->successful,
                'failureReason' => $h->failure_reason,
                'impersonated' => $h->impersonated_by_admin_id !== null,
                'createdAt' => $h->created_at->toIso8601ZuluString(),
            ])->all(),
        ];
    }
}
