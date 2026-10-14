<?php

namespace Database\Factories;

use App\Enums\AdminStatus;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<Admin> */
class AdminFactory extends Factory
{
    protected $model = Admin::class;

    protected static ?string $password = null;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->optional()->numerify('+8801#########'),
            'password' => static::$password ??= Hash::make('password'),
            'status' => AdminStatus::Active,
            'remember_token' => str()->random(10),
        ];
    }

    public function suspended(): static
    {
        return $this->state(['status' => AdminStatus::Suspended]);
    }

    public function locked(int $minutes = 15): static
    {
        return $this->state(['failed_login_attempts' => 5, 'locked_until' => now()->addMinutes($minutes)]);
    }
}
