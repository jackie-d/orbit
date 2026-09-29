<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Interaction;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates and updates interactions with their contacts and outbound links.
 */
class InteractionWriter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data): Interaction
    {
        return DB::transaction(function () use ($user, $data) {
            $interaction = $user->interactions()->create(Arr::except($data, ['contacts', 'contact_ids', 'links']));

            $this->syncContacts($interaction, $data);
            $this->syncLinks($interaction, $data);

            return $interaction;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Interaction $interaction, array $data): Interaction
    {
        return DB::transaction(function () use ($interaction, $data) {
            $interaction->update(Arr::except($data, ['contacts', 'contact_ids', 'links']));

            $this->syncContacts($interaction, $data);
            $this->syncLinks($interaction, $data);

            return $interaction;
        });
    }

    /**
     * Accepts either "contact_ids": [1, 2] or "contacts": [{"id": 1, "role": "met"}].
     *
     * @param  array<string, mixed>  $data
     */
    private function syncContacts(Interaction $interaction, array $data): void
    {
        if (array_key_exists('contacts', $data)) {
            $interaction->contacts()->sync(
                collect($data['contacts'] ?? [])->mapWithKeys(fn (array $c) => [$c['id'] => ['role' => $c['role'] ?? null]])
            );
        } elseif (array_key_exists('contact_ids', $data)) {
            $interaction->contacts()->sync(
                collect($data['contact_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => ['role' => null]])
            );
        }
    }

    /**
     * Links with an "id" are updated in place (keeping ids stable for exports),
     * links without one are created, and links missing from the payload are removed.
     *
     * @param  array<string, mixed>  $data
     */
    private function syncLinks(Interaction $interaction, array $data): void
    {
        if (! array_key_exists('links', $data)) {
            return;
        }

        $links = collect($data['links'] ?? []);

        $interaction->links()
            ->whereNotIn('id', $links->pluck('id')->filter()->all())
            ->delete();

        foreach ($links as $link) {
            $attributes = Arr::only($link, ['type', 'label', 'url', 'note', 'linked_contact_id']);

            // A link to a known person may omit the label: default to their name.
            if (empty($attributes['label']) && ! empty($attributes['linked_contact_id'])) {
                $attributes['label'] = Contact::find($attributes['linked_contact_id'])?->full_name;
            }

            if (! empty($link['id'])) {
                $interaction->links()->whereKey($link['id'])->first()?->update($attributes);
            } else {
                $interaction->links()->create($attributes);
            }
        }
    }
}
