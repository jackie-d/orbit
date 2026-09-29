<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_it_creates_a_contact_with_nested_details(): void
    {
        $response = $this->postJson('/api/v1/contacts', [
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'company' => 'US Navy',
            'birthday' => '1906-12-09',
            'is_favorite' => true,
            'phone_numbers' => [
                ['label' => 'mobile', 'number' => '+1 555 0100'],
                ['label' => 'work', 'number' => '+1 555 0199', 'is_primary' => true],
            ],
            'emails' => [['email' => 'grace@example.com']],
            'urls' => [['label' => 'blog', 'url' => 'https://example.com/grace']],
            'addresses' => [['label' => 'home', 'city' => 'Arlington', 'country' => 'US']],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.full_name', 'Grace Hopper')
            ->assertJsonPath('data.birthday', '1906-12-09')
            ->assertJsonPath('data.is_favorite', true)
            ->assertJsonCount(2, 'data.phone_numbers')
            ->assertJsonPath('data.phone_numbers.0.is_primary', false)
            ->assertJsonPath('data.phone_numbers.1.is_primary', true)
            ->assertJsonPath('data.emails.0.label', 'home')
            ->assertJsonPath('data.emails.0.is_primary', true)
            ->assertJsonPath('data.urls.0.url', 'https://example.com/grace')
            ->assertJsonPath('data.addresses.0.city', 'Arlington');

        $this->assertDatabaseHas('contacts', ['user_id' => $this->user->id, 'first_name' => 'Grace']);
    }

    public function test_it_validates_input(): void
    {
        $this->postJson('/api/v1/contacts', [
            'phone_numbers' => [['number' => 'not a phone!']],
            'emails' => [['email' => 'nope']],
            'birthday' => now()->addYear()->toDateString(),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'phone_numbers.0.number', 'emails.0.email', 'birthday']);
    }

    public function test_update_replaces_only_the_collections_that_are_sent(): void
    {
        $contact = Contact::factory()->withDetails()->for($this->user)->create();

        $this->patchJson("/api/v1/contacts/{$contact->id}", [
            'nickname' => 'Gigi',
            'phone_numbers' => [['label' => 'home', 'number' => '+39 02 1234']],
        ])->assertOk()
            ->assertJsonPath('data.nickname', 'Gigi')
            ->assertJsonCount(1, 'data.phone_numbers')
            ->assertJsonPath('data.phone_numbers.0.number', '+39 02 1234')
            ->assertJsonCount(1, 'data.emails');

        $this->assertDatabaseCount('contact_phone_numbers', 1);
    }

    public function test_it_lists_searches_and_filters_contacts(): void
    {
        Contact::factory()->for($this->user)->create(['first_name' => 'Alice', 'last_name' => 'Smith', 'is_favorite' => true]);
        Contact::factory()->for($this->user)->create(['first_name' => 'Bob', 'last_name' => 'Jones', 'is_favorite' => false]);
        $carol = Contact::factory()->withDetails()->for($this->user)->create(['first_name' => 'Carol', 'is_favorite' => false]);
        Contact::factory()->create(['first_name' => 'Alice']); // someone else's

        $this->getJson('/api/v1/contacts')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/contacts?q=ali')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.first_name', 'Alice');
        $this->getJson('/api/v1/contacts?q='.urlencode($carol->emails->first()->email))->assertJsonPath('data.0.id', $carol->id);
        $this->getJson('/api/v1/contacts?favorite=1')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/contacts?sort=-first_name')->assertJsonPath('data.0.first_name', 'Carol');
    }

    public function test_show_includes_interaction_count_and_last_interaction(): void
    {
        $contact = Contact::factory()->for($this->user)->create();
        $interactions = Interaction::factory()->count(2)->for($this->user)->sequence(
            ['occurred_at' => '2026-01-01 10:00:00'],
            ['occurred_at' => '2026-02-01 10:00:00'],
        )->create();
        $contact->interactions()->attach($interactions);

        $this->getJson("/api/v1/contacts/{$contact->id}")
            ->assertOk()
            ->assertJsonPath('data.interactions_count', 2)
            ->assertJsonPath('data.last_interaction_at', '2026-02-01T10:00:00+00:00');
    }

    public function test_it_soft_deletes_a_contact(): void
    {
        $contact = Contact::factory()->for($this->user)->create();

        $this->deleteJson("/api/v1/contacts/{$contact->id}")->assertNoContent();

        $this->assertSoftDeleted($contact);
        $this->getJson("/api/v1/contacts/{$contact->id}")->assertNotFound();
    }

    public function test_contacts_of_other_users_are_not_found(): void
    {
        $foreign = Contact::factory()->create();

        $this->getJson("/api/v1/contacts/{$foreign->id}")->assertNotFound();
        $this->patchJson("/api/v1/contacts/{$foreign->id}", ['first_name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/v1/contacts/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/contacts/{$foreign->id}/interactions")->assertNotFound();
    }
}
