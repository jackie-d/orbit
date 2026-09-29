<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates contacts together with their nested, labeled details.
 *
 * Nested collections behave like a phone's contacts app: when a collection
 * (e.g. "phone_numbers") is present in the payload it replaces the existing
 * one entirely; when it is absent it is left untouched.
 */
class ContactWriter
{
    /**
     * Payload key => relation name.
     */
    private const COLLECTIONS = [
        'phone_numbers' => 'phoneNumbers',
        'emails' => 'emails',
        'urls' => 'urls',
        'addresses' => 'addresses',
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Contact
    {
        return DB::transaction(function () use ($user, $data) {
            $contact = $user->contacts()->create(Arr::except($data, array_keys(self::COLLECTIONS)));

            $this->syncCollections($contact, $data);

            return $contact;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Contact $contact, array $data): Contact
    {
        return DB::transaction(function () use ($contact, $data) {
            $contact->update(Arr::except($data, array_keys(self::COLLECTIONS)));

            $this->syncCollections($contact, $data);

            return $contact;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncCollections(Contact $contact, array $data): void
    {
        foreach (self::COLLECTIONS as $key => $relation) {
            if (! array_key_exists($key, $data)) {
                continue;
            }

            $contact->{$relation}()->delete();

            $items = collect($data[$key] ?? [])->values();

            // Exactly one primary entry per collection: the flagged one, or the first.
            $primary = $items->search(fn ($item) => (bool) ($item['is_primary'] ?? false));
            $primary = $primary === false ? 0 : $primary;

            $items->each(function (array $item, int $index) use ($contact, $relation, $primary) {
                $contact->{$relation}()->create([...$item, 'is_primary' => $index === $primary]);
            });
        }
    }
}
