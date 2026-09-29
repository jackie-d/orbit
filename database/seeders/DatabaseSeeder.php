<?php

namespace Database\Seeders;

use App\Enums\LinkType;
use App\Models\Contact;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed a demo account with contacts, interactions and links.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Demo User',
            'email' => 'demo@orbit.test',
            'password' => 'password',
        ]);

        $contacts = Contact::factory()->count(15)->withDetails()->for($user)->create();

        $shops = [
            ['label' => 'Blue Bottle Coffee', 'url' => 'https://bluebottlecoffee.com'],
            ['label' => 'Feltrinelli', 'url' => 'https://www.lafeltrinelli.it'],
        ];
        $services = [
            ['label' => 'Spotify', 'url' => 'https://open.spotify.com'],
            ['label' => 'Airbnb', 'url' => 'https://www.airbnb.com'],
        ];

        Interaction::factory()->count(40)->for($user)->create()->each(function (Interaction $interaction) use ($contacts, $shops, $services) {
            $interaction->contacts()->attach(
                $contacts->random(fake()->numberBetween(1, 3))->pluck('id')->mapWithKeys(fn ($id) => [$id => ['role' => 'met']])
            );

            $interaction->links()->create(['type' => LinkType::Shop, ...fake()->randomElement($shops)]);

            if (fake()->boolean()) {
                $interaction->links()->create(['type' => LinkType::Service, 'note' => 'Recommended', ...fake()->randomElement($services)]);
            }

            if (fake()->boolean(30)) {
                $other = $contacts->random();
                $interaction->links()->create([
                    'type' => LinkType::Person,
                    'label' => $other->full_name,
                    'linked_contact_id' => $other->id,
                    'note' => 'Talked about them',
                ]);
            }
        });
    }
}
