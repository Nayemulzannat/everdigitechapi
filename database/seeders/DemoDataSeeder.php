<?php

namespace Database\Seeders;

use App\Enums\ClientGroup;
use App\Enums\ClientStatus;
use App\Enums\MemberRole;
use App\Enums\MemberStatus;
use App\Enums\ResourceScope;
use App\Models\AccountMember;
use App\Models\ClientAccount;
use App\Models\ClientUser;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * About 60 Bangladeshi clients for development: a mix of statuses and groups, some with a team.
 * Only runs when APP_ENV=local. The data is the same on every run (fixed random seed).
 * Emails use reserved example domains, so nothing can reach a real person. Every demo user's password is "Demo-Password-123".
 */
class DemoDataSeeder extends Seeder
{
    public const CLIENTS = 60;

    private const FIRST = [
        'Rahim', 'Karim', 'Abdul', 'Mizanur', 'Shakil', 'Imran', 'Kamal', 'Fahim', 'Ashraf', 'Habib', 'Zahid', 'Tariq', 'Mamun',
        'Nayeem', 'Sohel', 'Rafiq', 'Mahmudul', 'Arif', 'Sabbir', 'Tanvir', 'Nusrat', 'Farhana', 'Sharmin', 'Tania', 'Mithila',
        'Jannat', 'Rumana', 'Sadia', 'Nadia', 'Lubna', 'Rokeya', 'Shirin', 'Afsana', 'Popy', 'Mou', 'Taslima', 'Salma', 'Ruma',
    ];

    private const LAST = [
        'Islam', 'Rahman', 'Hossain', 'Ahmed', 'Chowdhury', 'Khan', 'Akter', 'Begum', 'Sarker', 'Mia', 'Talukder', 'Siddique',
        'Uddin', 'Hasan', 'Sultana', 'Jahan', 'Bhuiyan', 'Mondal', 'Biswas', 'Roy', 'Das', 'Majumder', 'Kabir', 'Alam',
    ];

    private const COMPANIES = [
        'Padma Travels', 'Meghna Logistics', 'Dhaka Prints', 'Green Leaf Foods', 'Royal Tailors', 'Shapla Crafts', 'Nirob IT',
        'Karnaphuli Agro', 'Sonar Bangla Shop', 'Bloom Florist', 'Swapno Studio', 'Bay Tech Solutions', 'Jamuna Electronics',
        'Surma Tea Traders', 'Modhumoti Books', 'Banglar Hat', 'Chittagong Marine Supply', 'Sylhet Spices', 'Rupsha Fisheries',
        'Uttara Dental Care', 'Gulshan Realty', 'Motijheel Capital Advisors', 'Dhanmondi Coaching Center', 'Mirpur Sweets',
        'Khulna Shipping Agency', 'Rajshahi Silk House', 'Cox Bazar Resorts', 'Comilla Khadi Mela', 'Barishal Boat Works',
        'Tangail Saree Mart', 'Bogura Dairy', 'Rangpur Agro Tech',
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoDataSeeder only runs when APP_ENV=local. Nothing created.');

            return;
        }
        if (ClientAccount::withTrashed()->exists()) {
            $this->command?->warn('Clients already exist, so no demo clients were added. Use migrate:fresh --seed to start over.');

            return;
        }

        mt_srand(2026);
        $password = Hash::make('Demo-Password-123');

        // Exact mix, then shuffled: 45 active / 9 inactive / 6 closed, and 42 regular / 12 reseller / 6 vip.
        $statuses = $this->shuffled([...array_fill(0, 45, ClientStatus::Active), ...array_fill(0, 9, ClientStatus::Inactive), ...array_fill(0, 6, ClientStatus::Closed)]);
        $groups = $this->shuffled([...array_fill(0, 42, ClientGroup::Regular), ...array_fill(0, 12, ClientGroup::Reseller), ...array_fill(0, 6, ClientGroup::Vip)]);

        $usedEmails = [];
        for ($i = 0; $i < self::CLIENTS; $i++) {
            $name = $this->pick(self::FIRST).' '.$this->pick(self::LAST);
            $company = mt_rand(1, 100) <= 60 ? $this->pick(self::COMPANIES) : null;
            $status = $statuses[$i];
            $joined = Carbon::now('Asia/Dhaka')->subDays(mt_rand(5, 1400))->setTime(mt_rand(9, 21), mt_rand(0, 59))->utc();

            $owner = ClientUser::create([
                'name' => $name,
                'email' => $this->email($name, $company, $usedEmails),
                'phone' => '+8801'.mt_rand(3, 9).str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT),
                'password' => $password,
                'email_verified_at' => $joined,
                'status' => $status === ClientStatus::Closed ? 'suspended' : 'active',
                'last_login_at' => $status === ClientStatus::Active ? now()->subDays(mt_rand(0, 40)) : null,
            ]);

            $services = $status === ClientStatus::Closed ? 0 : mt_rand(0, 8);
            $hasDue = $status !== ClientStatus::Closed && mt_rand(1, 100) <= 30;

            $account = ClientAccount::create([
                'name' => $name,
                'company' => $company,
                'owner_user_id' => $owner->id,
                'client_group' => $groups[$i],
                'status' => $status,
                'credit_balance' => mt_rand(1, 100) <= 15 ? mt_rand(5, 60) * 100 : 0,
                'notes' => mt_rand(1, 100) <= 20 ? 'Prefers bKash. Call before renewal.' : null,
                // TODO: placeholders until the services, domains and invoices tables exist.
                'tmp_services_count' => $services,
                'tmp_domains_count' => $services + mt_rand(0, 3),
                'tmp_balance_due' => $hasDue ? mt_rand(10, 180) * 50 : 0,
                'tmp_has_overdue' => $hasDue && mt_rand(1, 100) <= 40,
                'created_at' => $joined,
                'updated_at' => $joined,
            ]);

            AccountMember::create([
                'account_id' => $account->id, 'user_id' => $owner->id, 'role' => MemberRole::Owner,
                'status' => MemberStatus::Active, 'joined_at' => $joined,
            ]);

            // Roughly one account in five has a team: a colleague with limited access.
            if ($status !== ClientStatus::Closed && mt_rand(1, 100) <= 20) {
                $this->addTeamMember($account, $owner, $password, $usedEmails);
            }
        }
    }

    /** @param  array<string, true>  $usedEmails */
    private function addTeamMember(ClientAccount $account, ClientUser $owner, string $password, array &$usedEmails): void
    {
        $name = $this->pick(self::FIRST).' '.$this->pick(self::LAST);
        $user = ClientUser::create([
            'name' => $name,
            'email' => $this->email($name, $account->company, $usedEmails),
            'phone' => '+8801'.mt_rand(3, 9).str_pad((string) mt_rand(0, 99999999), 8, '0', STR_PAD_LEFT),
            'password' => $password,
            'email_verified_at' => $account->created_at,
        ]);

        $member = AccountMember::create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'role' => MemberRole::Member,
            'permissions' => ['invoices.view', 'hosting.view', 'tickets.create'],
            'hosting_scope' => mt_rand(0, 1) ? ResourceScope::All : ResourceScope::Selected,
            'domain_scope' => ResourceScope::All,
            'status' => MemberStatus::Active,
            'invited_by' => $owner->id,
            'joined_at' => $account->created_at->copy()->addDays(mt_rand(1, 30)),
        ]);

        // TODO: when hosting services exist, point these at real service ids.
    }

    /** @param  array<string, true>  $used */
    private function email(string $name, ?string $company, array &$used): string
    {
        $local = strtolower(str_replace(' ', '.', $name));
        $domain = $company ? strtolower(preg_replace('/[^A-Za-z0-9]/', '', $company)).'.example' : 'example.com';
        $email = "{$local}@{$domain}";
        for ($n = 2; isset($used[$email]); $n++) {
            $email = "{$local}{$n}@{$domain}";
        }
        $used[$email] = true;

        return $email;
    }

    private function pick(array $list): string
    {
        return $list[mt_rand(0, count($list) - 1)];
    }

    /** Fisher-Yates with mt_rand, so the order is repeatable. */
    private function shuffled(array $items): array
    {
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }

        return $items;
    }
}
