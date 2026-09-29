<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Jobs\ProcessContactPhoto;
use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContactPhotoController extends Controller
{
    /**
     * POST /contacts/{contact}/photo (multipart, field "photo").
     *
     * The original is stored immediately; resizing and thumbnailing happen
     * on the queue worker (ProcessContactPhoto).
     */
    public function store(Request $request, Contact $contact): ContactResource
    {
        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:'.config('orbit.photos.max_kb')],
        ]);

        $this->deleteFiles($contact);

        $file = $request->file('photo');
        $path = $file->storeAs(
            "photos/{$contact->user_id}/{$contact->id}",
            Str::uuid().'.'.$file->extension(),
            ['disk' => config('orbit.photos.disk')],
        );

        $contact->forceFill(['photo_path' => $path, 'photo_thumb_path' => null])->save();

        ProcessContactPhoto::dispatch($contact, $path);

        return new ContactResource($contact->fresh());
    }

    public function destroy(Contact $contact): ContactResource
    {
        $this->deleteFiles($contact);

        $contact->forceFill(['photo_path' => null, 'photo_thumb_path' => null])->save();

        return new ContactResource($contact);
    }

    private function deleteFiles(Contact $contact): void
    {
        $paths = array_filter([$contact->photo_path, $contact->photo_thumb_path]);

        if ($paths !== []) {
            Contact::photoDisk()->delete($paths);
        }
    }
}
