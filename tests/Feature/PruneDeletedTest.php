<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Interaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneDeletedTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_permanently_removes_old_soft_deleted_records(): void
    {
        $old = Contact::factory()->create();
        $old->delete();
        $old->forceFill(['deleted_at' => now()->subDays(31)])->saveQuietly();

        $recent = Contact::factory()->create();
        $recent->delete();

        $oldInteraction = Interaction::factory()->create();
        $oldInteraction->delete();
        $oldInteraction->forceFill(['deleted_at' => now()->subDays(40)])->saveQuietly();

        $this->artisan('orbit:prune-deleted')
            ->expectsOutputToContain('Pruned 1 contact(s) and 1 interaction(s)')
            ->assertSuccessful();

        $this->assertDatabaseMissing('contacts', ['id' => $old->id]);
        $this->assertDatabaseHas('contacts', ['id' => $recent->id]);
        $this->assertDatabaseMissing('interactions', ['id' => $oldInteraction->id]);
    }
}
