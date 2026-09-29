<?php

namespace Database\Factories;

use App\Enums\LinkType;
use App\Models\Interaction;
use App\Models\InteractionLink;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InteractionLink>
 */
class InteractionLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'interaction_id' => Interaction::factory(),
            'type' => fake()->randomElement([LinkType::Service, LinkType::Shop, LinkType::Other]),
            'label' => fake()->company(),
            'url' => fake()->optional()->url(),
            'note' => fake()->optional()->sentence(),
        ];
    }
}
