<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InteractionLinkExportTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private string $token;

    private Contact $anna;

    private Contact $luca;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->token = $this->user->createToken('analytics', ['links:read'])->plainTextToken;
        $this->anna = Contact::factory()->for($this->user)->create(['first_name' => 'Anna', 'last_name' => 'Rossi']);
        $this->luca = Contact::factory()->for($this->user)->create(['first_name' => 'Luca', 'last_name' => 'Bianchi']);

        // Three visits to the same coffee shop (via two URL spellings), with Anna.
        foreach (['2026-01-01 09:00', '2026-01-11 09:00', '2026-01-31 09:00'] as $i => $date) {
            $interaction = Interaction::factory()->for($this->user)->create([
                'occurred_at' => $date, 'mood' => $i === 2 ? 'bad' : 'good', 'outcome' => 'positive',
            ]);
            $interaction->contacts()->attach($this->anna, ['role' => 'met']);
            $interaction->links()->create([
                'type' => 'shop',
                'label' => 'Blue Bottle',
                'url' => $i === 0 ? 'https://www.bluebottlecoffee.com/' : 'https://bluebottlecoffee.com/menu',
                'note' => "visit {$i}",
            ]);
        }

        // One interaction that points to another person and a service.
        $other = Interaction::factory()->for($this->user)->create(['occurred_at' => '2026-02-15 18:00', 'mood' => 'great', 'outcome' => 'neutral']);
        $other->links()->create(['type' => 'person', 'label' => 'Luca', 'linked_contact_id' => $this->luca->id]);
        $other->links()->create(['type' => 'service', 'label' => 'Spotify', 'url' => 'https://open.spotify.com/x']);

        // Links of deleted interactions and of other users must never be exported.
        $deleted = Interaction::factory()->for($this->user)->create();
        $deleted->links()->create(['type' => 'shop', 'label' => 'Ghost']);
        $deleted->delete();
        Interaction::factory()->create()->links()->create(['type' => 'shop', 'label' => 'Not mine']);
    }

    public function test_it_requires_the_links_read_ability(): void
    {
        $this->getJson('/api/v1/exports/interaction-links')->assertUnauthorized();

        $noAbility = $this->user->createToken('other', ['something:else'])->plainTextToken;
        $this->withToken($noAbility)->getJson('/api/v1/exports/interaction-links')->assertForbidden();
        $this->app['auth']->forgetGuards();

        // A full-access (login) token can use the export too.
        $full = $this->user->createToken('app', ['*'])->plainTextToken;
        $this->withToken($full)->getJson('/api/v1/exports/interaction-links')->assertOk();
    }

    public function test_json_export_returns_flat_rows(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links')->assertOk();

        $response->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.type', 'shop')
            ->assertJsonPath('data.0.url_host', 'bluebottlecoffee.com')
            ->assertJsonPath('data.0.note', 'visit 0')
            ->assertJsonPath('data.0.interaction.occurred_at', '2026-01-01T09:00:00+00:00')
            ->assertJsonPath('data.0.interaction.mood', 'good')
            ->assertJsonPath('data.0.interaction.mood_score', 1)
            ->assertJsonPath('data.0.contacts.0.name', 'Anna Rossi')
            ->assertJsonPath('data.0.contacts.0.role', 'met')
            ->assertJsonPath('data.3.linked_contact.name', 'Luca Bianchi')
            ->assertJsonPath('meta.next_cursor', null);

        $this->assertNotContains('Ghost', $response->json('data.*.label'));
        $this->assertNotContains('Not mine', $response->json('data.*.label'));
    }

    public function test_json_export_is_cursor_paginated(): void
    {
        $first = $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links?per_page=2')->assertOk();
        $first->assertJsonCount(2, 'data');
        $cursor = $first->json('meta.next_cursor');
        $this->assertNotNull($cursor);

        $second = $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links?per_page=2&cursor='.$cursor)->assertOk();
        $second->assertJsonCount(2, 'data');
        $this->assertEmpty(array_intersect($first->json('data.*.id'), $second->json('data.*.id')));
    }

    public function test_filters(): void
    {
        $get = fn (string $query) => $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links?'.$query)->assertOk();

        $get('type=shop')->assertJsonCount(3, 'data');
        $get('type[]=person&type[]=service')->assertJsonCount(2, 'data');
        $get('type=person,service')->assertJsonCount(2, 'data');
        $get('from=2026-01-10&to=2026-01-31T23:59:59Z')->assertJsonCount(2, 'data');
        $get("contact_id={$this->anna->id}")->assertJsonCount(3, 'data');
        $get("linked_contact_id={$this->luca->id}")->assertJsonCount(1, 'data');
        $get('host=www.BlueBottleCoffee.com')->assertJsonCount(3, 'data');
        $get('q=visit%201')->assertJsonCount(1, 'data');

        $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links?type=planet&per_page=9999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type.0', 'per_page']);
    }

    public function test_csv_export_streams_all_rows(): void
    {
        $response = $this->withToken($this->token)->get('/api/v1/exports/interaction-links?format=csv')->assertOk();

        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="interaction-links-', $response->headers->get('Content-Disposition'));

        $lines = array_values(array_filter(explode("\n", $response->streamedContent())));
        $this->assertCount(6, $lines); // header + 5 rows
        $this->assertStringStartsWith('id,type,label,url,url_host,note,', $lines[0]);

        $row = array_combine(str_getcsv($lines[0], escape: ''), str_getcsv($lines[1], escape: ''));
        $this->assertSame('bluebottlecoffee.com', $row['url_host']);
        $this->assertSame('Anna Rossi', $row['contact_names']);
    }

    public function test_ndjson_export_streams_one_document_per_line(): void
    {
        $response = $this->withToken($this->token)->get('/api/v1/exports/interaction-links?format=ndjson&type=shop')->assertOk();

        $this->assertSame('application/x-ndjson', $response->headers->get('Content-Type'));

        $rows = array_map(fn ($line) => json_decode($line, true), array_filter(explode("\n", $response->streamedContent())));
        $this->assertCount(3, $rows);
        $this->assertSame(['visit 0', 'visit 1', 'visit 2'], array_column($rows, 'note'));
    }

    public function test_recurrence_groups_links_by_target(): void
    {
        $response = $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links/recurrence')->assertOk();

        $response->assertJsonPath('meta.group_by', 'target')
            ->assertJsonPath('meta.groups', 3)
            ->assertJsonPath('meta.links', 5);

        $shop = $response->json('data.0');
        $this->assertSame('host:bluebottlecoffee.com', $shop['key']);
        $this->assertSame('shop', $shop['type']);
        $this->assertSame(3, $shop['occurrences']);
        $this->assertSame(3, $shop['distinct_days']);
        $this->assertSame('2026-01-01T09:00:00+00:00', $shop['first_seen']);
        $this->assertSame('2026-01-31T09:00:00+00:00', $shop['last_seen']);
        $this->assertEquals(15, $shop['avg_days_between']); // (10 + 20) / 2
        $this->assertSame(['good' => 2, 'bad' => 1], $shop['mood_counts']);
        $this->assertEquals(0.33, $shop['avg_mood_score']);
        $this->assertSame(['positive' => 3], $shop['outcome_counts']);

        $keys = $response->json('data.*.key');
        $this->assertContains("contact:{$this->luca->id}", $keys);
        $this->assertContains('host:open.spotify.com', $keys);
    }

    public function test_recurrence_group_by_type_and_month_and_min_occurrences(): void
    {
        $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links/recurrence?group_by=type')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'type:shop')
            ->assertJsonPath('data.0.occurrences', 3)
            ->assertJsonPath('meta.groups', 3);

        $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links/recurrence?group_by=month')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'month:2026-01')
            ->assertJsonPath('data.0.types', ['shop' => 3])
            ->assertJsonPath('data.1.key', 'month:2026-02');

        $this->withToken($this->token)->getJson('/api/v1/exports/interaction-links/recurrence?min_occurrences=2')
            ->assertOk()
            ->assertJsonPath('meta.groups', 1);
    }
}
