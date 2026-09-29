<?php

namespace Database\Factories;

use App\Enums\Mood;
use App\Enums\Outcome;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interaction>
 */
class InteractionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'occurred_at' => fake()->dateTimeBetween('-1 year'),
            'title' => fake()->optional()->sentence(4),
            'note' => fake()->paragraph(),
            'mood' => fake()->randomElement(Mood::cases()),
            'thoughts' => fake()->optional()->sentence(),
            'outcome' => fake()->randomElement(Outcome::cases()),
            'issues' => fake()->optional(0.3)->sentence(),
            'location_name' => fake()->optional()->city(),
            'latitude' => fake()->optional()->latitude(),
            'longitude' => fake()->optional()->longitude(),
        ];
    }
}
