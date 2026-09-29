<?php

namespace Tests\Feature;

use App\Jobs\ProcessContactPhoto;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContactPhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        $this->user = User::factory()->create();
        $this->contact = Contact::factory()->for($this->user)->create();
        Sanctum::actingAs($this->user, ['*']);
    }

    public function test_upload_stores_the_photo_and_queues_processing(): void
    {
        Queue::fake();

        $response = $this->post("/api/v1/contacts/{$this->contact->id}/photo", [
            'photo' => UploadedFile::fake()->image('me.png', 2000, 1000),
        ])->assertOk();

        $path = $this->contact->fresh()->photo_path;
        Storage::disk('public')->assertExists($path);
        $this->assertNotNull($response->json('data.photo_url'));

        Queue::assertPushed(ProcessContactPhoto::class, fn ($job) => $job->path === $path);
    }

    public function test_the_job_resizes_the_photo_and_creates_a_thumbnail(): void
    {
        $this->post("/api/v1/contacts/{$this->contact->id}/photo", [
            'photo' => UploadedFile::fake()->image('me.png', 2000, 1000),
        ])->assertOk(); // sync queue in tests: the job already ran

        $contact = $this->contact->fresh();
        $this->assertStringEndsWith('.jpg', $contact->photo_path);
        $this->assertStringEndsWith('_thumb.jpg', $contact->photo_thumb_path);

        [$w, $h] = getimagesizefromstring(Storage::disk('public')->get($contact->photo_path));
        $this->assertSame([1024, 512], [$w, $h]);

        [$tw, $th] = getimagesizefromstring(Storage::disk('public')->get($contact->photo_thumb_path));
        $this->assertSame([256, 256], [$tw, $th]);

        // The original PNG was replaced by the normalized JPEG.
        $this->assertCount(2, Storage::disk('public')->allFiles());
    }

    public function test_non_images_are_rejected(): void
    {
        $this->post("/api/v1/contacts/{$this->contact->id}/photo", [
            'photo' => UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('photo');
    }

    public function test_the_photo_can_be_removed(): void
    {
        $this->post("/api/v1/contacts/{$this->contact->id}/photo", [
            'photo' => UploadedFile::fake()->image('me.jpg'),
        ])->assertOk();

        $this->deleteJson("/api/v1/contacts/{$this->contact->id}/photo")
            ->assertOk()
            ->assertJsonPath('data.photo_url', null);

        $this->assertEmpty(Storage::disk('public')->allFiles());
    }
}
