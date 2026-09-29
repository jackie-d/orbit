<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InteractionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_it_creates_an_interaction_with_contacts_and_links(): void
    {
        [$anna, $marco, $luca] = Contact::factory()->count(3)->for($this->user)->create();

        $response = $this->postJson('/api/v1/interactions', [
            'occurred_at' => '2026-09-20T19:30:00+02:00',
            'title' => 'Dinner at the lake',
            'note' => 'Long talk about the new job.',
            'mood' => 'good',
            'thoughts' => 'Should see them more often.',
            'outcome' => 'positive',
            'issues' => 'Marco is worried about moving.',
            'location_name' => 'Lago di Como',
            'latitude' => 45.9870,
            'longitude' => 9.2572,
            'contacts' => [
                ['id' => $anna->id, 'role' => 'host'],
                ['id' => $marco->id],
            ],
            'links' => [
                ['type' => 'shop', 'label' => 'Trattoria Da Mario', 'url' => 'https://www.damario.it/menu', 'note' => 'Great risotto'],
                ['type' => 'service', 'label' => 'Booking', 'url' => 'https://booking.com'],
                ['type' => 'person', 'linked_contact_id' => $luca->id, 'note' => 'They suggested calling Luca'],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Dinner at the lake')
            ->assertJsonPath('data.occurred_at', '2026-09-20T17:30:00+00:00')
            ->assertJsonPath('data.mood', 'good')
            ->assertJsonPath('data.outcome', 'positive')
            ->assertJsonPath('data.location.name', 'Lago di Como')
            ->assertJsonPath('data.location.latitude', 45.987)
            ->assertJsonCount(2, 'data.contacts')
            ->assertJsonPath('data.contacts.0.role', 'host')
            ->assertJsonCount(3, 'data.links')
            ->assertJsonPath('data.links.0.url_host', 'damario.it')
            ->assertJsonPath('data.links.0.note', 'Great risotto')
            ->assertJsonPath('data.links.2.label', $luca->full_name)
            ->assertJsonPath('data.links.2.linked_contact.id', $luca->id);

        $this->assertDatabaseHas('interaction_links', ['user_id' => $this->user->id, 'note' => 'They suggested calling Luca']);
    }

    public function test_contact_ids_shorthand_is_supported(): void
    {
        $contacts = Contact::factory()->count(2)->for($this->user)->create();

        $this->postJson('/api/v1/interactions', [
            'occurred_at' => now()->toIso8601String(),
            'contact_ids' => $contacts->pluck('id')->all(),
        ])->assertCreated()->assertJsonCount(2, 'data.contacts');
    }

    public function test_it_validates_enums_links_and_contact_ownership(): void
    {
        $foreign = Contact::factory()->create();

        $this->postJson('/api/v1/interactions', [
            'mood' => 'ecstatic',
            'outcome' => 'maybe',
            'latitude' => 120,
            'contact_ids' => [$foreign->id],
            'links' => [
                ['type' => 'planet', 'label' => 'x'],
                ['type' => 'shop'],
                ['type' => 'shop', 'label' => 'x', 'url' => 'not-a-url', 'note' => str_repeat('a', 1001)],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'occurred_at', 'mood', 'outcome', 'latitude', 'longitude', 'contact_ids.0',
            'links.0.type', 'links.1.label', 'links.2.url', 'links.2.note',
        ]);
    }

    public function test_update_keeps_link_ids_stable_and_removes_missing_links(): void
    {
        $interaction = Interaction::factory()->for($this->user)->create();
        $keep = $interaction->links()->create(['type' => 'shop', 'label' => 'Old label']);
        $interaction->links()->create(['type' => 'service', 'label' => 'To remove']);

        $this->patchJson("/api/v1/interactions/{$interaction->id}", [
            'mood' => 'bad',
            'links' => [
                ['id' => $keep->id, 'type' => 'shop', 'label' => 'New label', 'note' => 'Updated'],
                ['type' => 'other', 'label' => 'Brand new'],
            ],
        ])->assertOk()
            ->assertJsonPath('data.mood', 'bad')
            ->assertJsonCount(2, 'data.links')
            ->assertJsonPath('data.links.0.id', $keep->id)
            ->assertJsonPath('data.links.0.label', 'New label')
            ->assertJsonPath('data.links.0.note', 'Updated');

        $this->assertDatabaseMissing('interaction_links', ['label' => 'To remove']);
    }

    public function test_it_lists_and_filters_interactions(): void
    {
        $anna = Contact::factory()->for($this->user)->create();

        $a = Interaction::factory()->for($this->user)->create(['occurred_at' => '2026-01-10', 'mood' => 'great', 'outcome' => 'positive', 'note' => 'Coffee chat']);
        $b = Interaction::factory()->for($this->user)->create(['occurred_at' => '2026-03-10', 'mood' => 'bad', 'outcome' => 'negative', 'note' => 'Argument']);
        Interaction::factory()->create(); // someone else's
        $a->contacts()->attach($anna);

        $this->getJson('/api/v1/interactions')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.id', $b->id);
        $this->getJson('/api/v1/interactions?mood=great')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/v1/interactions?outcome=negative')->assertJsonPath('data.0.id', $b->id);
        $this->getJson('/api/v1/interactions?from=2026-02-01')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        $this->getJson('/api/v1/interactions?to=2026-02-01')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/v1/interactions?q=coffee')->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/interactions?contact_id={$anna->id}")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/contacts/{$anna->id}/interactions")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
    }

    public function test_interactions_of_other_users_are_not_found(): void
    {
        $foreign = Interaction::factory()->create();

        $this->getJson("/api/v1/interactions/{$foreign->id}")->assertNotFound();
        $this->deleteJson("/api/v1/interactions/{$foreign->id}")->assertNotFound();
    }

    public function test_it_soft_deletes_an_interaction(): void
    {
        $interaction = Interaction::factory()->for($this->user)->create();

        $this->deleteJson("/api/v1/interactions/{$interaction->id}")->assertNoContent();

        $this->assertSoftDeleted($interaction);
    }

    public function test_stats_overview(): void
    {
        $contact = Contact::factory()->for($this->user)->create();
        $interactions = Interaction::factory()->count(3)->for($this->user)->create(['mood' => 'good', 'outcome' => 'positive', 'occurred_at' => now()->subDay()]);
        $contact->interactions()->attach($interactions);

        $this->getJson('/api/v1/stats/overview')
            ->assertOk()
            ->assertJsonPath('data.contacts', 1)
            ->assertJsonPath('data.interactions', 3)
            ->assertJsonPath('data.interactions_last_30_days', 3)
            ->assertJsonPath('data.by_mood.good', 3)
            ->assertJsonPath('data.by_outcome.positive', 3)
            ->assertJsonPath('data.top_contacts.0.interactions', 3);
    }
}
