<?php

namespace Database\Factories;

use App\Enums\ClientUserStatus;
use App\Models\ClientUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<ClientUser> */
class ClientUserFactory extends Factory
{
    protected $model = ClientUser::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            // Bangladeshi mobile: +8801 then 3-9, then 8 digits.
            'phone' => '+8801'.fake()->numberBetween(3, 9).fake()->numerify('########'),
            'password' => static::$password ??= Hash::make('password'),
            'email_verified_at' => now(),
            'status' => ClientUserStatus::Active,
            'remember_token' => str()->random(10),
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => ClientUserStatus::Suspended]);
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }
}
