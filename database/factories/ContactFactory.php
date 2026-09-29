<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactPhoneNumber;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contact>
 */
class ContactFactory extends Factory
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
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'nickname' => fake()->optional(0.3)->userName(),
            'company' => fake()->optional()->company(),
            'job_title' => fake()->optional()->jobTitle(),
            'birthday' => fake()->optional()->dateTimeBetween('-70 years', '-18 years')?->format('Y-m-d'),
            'notes' => fake()->optional()->sentence(),
            'is_favorite' => fake()->boolean(20),
        ];
    }

    public function favorite(): static
    {
        return $this->state(fn () => ['is_favorite' => true]);
    }

    /**
     * Give the contact a primary mobile number and a primary email.
     */
    public function withDetails(): static
    {
        return $this->afterCreating(function (Contact $contact) {
            $contact->phoneNumbers()->save(new ContactPhoneNumber([
                'label' => 'mobile',
                'number' => fake()->e164PhoneNumber(),
                'is_primary' => true,
            ]));
            $contact->emails()->save(new ContactEmail([
                'label' => 'home',
                'email' => fake()->unique()->safeEmail(),
                'is_primary' => true,
            ]));
        });
    }
}
