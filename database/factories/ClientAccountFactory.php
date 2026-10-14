<?php

namespace Database\Factories;

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ClientAccount> */
class ClientAccountFactory extends Factory
{
    protected $model = ClientAccount::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company' => fake()->optional(0.6)->company(),
            'owner_user_id' => ClientUser::factory(),
            'client_group' => ClientGroup::Regular,
            'status' => ClientStatus::Active,
            'credit_balance' => 0,
            'notes' => null,
        ];
    }

    /** Every account has its owner as an `owner` member, so the owner can reach it through the same table as everyone else. */
    public function configure(): static
    {
        return $this->afterCreating(function (ClientAccount $account) {
            $account->members()->firstOrCreate(['user_id' => $account->owner_user_id], [
                'role' => MemberRole::Owner,
                'status' => MemberStatus::Active,
                'joined_at' => $account->created_at,
            ]);
        });
    }

    public function group(ClientGroup $group): static
    {
        return $this->state(['client_group' => $group]);
    }

    public function status(ClientStatus $status): static
    {
        return $this->state(['status' => $status]);
    }

    /** Placeholder numbers for the columns that will come from services, domains and invoices. */
    public function summary(int $services, int $domains, float $balanceDue = 0, bool $overdue = false): static
    {
        return $this->state([
            'tmp_services_count' => $services,
            'tmp_domains_count' => $domains,
            'tmp_balance_due' => $balanceDue,
            'tmp_has_overdue' => $overdue,
        ]);
    }

    public function joinedOn(string $date): static
    {
        return $this->state(['created_at' => $date, 'updated_at' => $date]);
    }
}
