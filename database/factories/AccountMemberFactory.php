<?php

namespace Database\Factories;

use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\ResourceScope;
use App\Models\AccountMember;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountMember> */
class AccountMemberFactory extends Factory
{
    protected $model = AccountMember::class;

    public function definition(): array
    {
        return [
            'account_id' => ClientAccount::factory(),
            'user_id' => ClientUser::factory(),
            'role' => MemberRole::Member,
            'permissions' => ['invoices.view', 'hosting.view'],
            'hosting_scope' => ResourceScope::All,
            'domain_scope' => ResourceScope::All,
            'status' => MemberStatus::Active,
            'joined_at' => now(),
        ];
    }

    public function invited(): static
    {
        return $this->state(['status' => MemberStatus::Invited, 'joined_at' => null]);
    }

    public function selectedOnly(): static
    {
        return $this->state(['hosting_scope' => ResourceScope::Selected, 'domain_scope' => ResourceScope::Selected]);
    }
}
