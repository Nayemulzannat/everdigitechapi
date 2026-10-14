<?php

namespace Database\Factories;

use App\Models\AdminLoginHistory;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AdminLoginHistory> */
class AdminLoginHistoryFactory extends Factory
{
    protected $model = AdminLoginHistory::class;

    public function definition(): array
    {
        return [
            'email_attempted' => fake()->safeEmail(),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'browser' => 'Chrome',
            'platform' => 'Windows',
            'successful' => true,
            'created_at' => fake()->dateTimeBetween('-30 days'),
        ];
    }
}
