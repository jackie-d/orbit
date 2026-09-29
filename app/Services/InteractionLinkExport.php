<?php

namespace App\Services;

use App\Models\InteractionLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Builds the filtered query and the flat row format used by the
 * third-party interaction link exports.
 */
class InteractionLinkExport
{
    public const CSV_COLUMNS = [
        'id', 'type', 'label', 'url', 'url_host', 'note',
        'linked_contact_id', 'linked_contact_name',
        'interaction_id', 'occurred_at', 'interaction_title', 'mood', 'mood_score', 'outcome',
        'location_name', 'latitude', 'longitude',
        'contact_ids', 'contact_names', 'created_at',
    ];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<InteractionLink>
     */
    public function query(User $user, array $filters): Builder
    {
        $query = InteractionLink::query()
            ->where('interaction_links.user_id', $user->id)
            ->with([
                'interaction',
                'interaction.contacts:id,first_name,last_name',
                'linkedContact:id,first_name,last_name',
            ])
            // Links of deleted interactions are excluded (and filtered by date).
            ->whereHas('interaction', function (Builder $q) use ($filters) {
                if (! empty($filters['from'])) {
                    $q->where('occurred_at', '>=', Carbon::parse($filters['from']));
                }
                if (! empty($filters['to'])) {
                    $q->where('occurred_at', '<=', Carbon::parse($filters['to']));
                }
            });

        if (! empty($filters['type'])) {
            $query->whereIn('type', (array) $filters['type']);
        }

        if (! empty($filters['contact_id'])) {
            $query->whereHas('interaction.contacts', fn (Builder $q) => $q->whereKey($filters['contact_id']));
        }

        if (! empty($filters['linked_contact_id'])) {
            $query->where('linked_contact_id', $filters['linked_contact_id']);
        }

        if (! empty($filters['host'])) {
            $query->where('url_host', InteractionLink::hostFromUrl('https://'.$filters['host']) ?? $filters['host']);
        }

        if (! empty($filters['q'])) {
            $like = '%'.mb_strtolower($filters['q']).'%';
            $query->where(fn (Builder $q) => $q
                ->whereRaw('LOWER(label) LIKE ?', [$like])
                ->orWhereRaw('LOWER(note) LIKE ?', [$like]));
        }

        return $query->orderBy('interaction_links.id');
    }

    /**
     * Flat, self-contained representation of a link and its interaction.
     *
     * @return array<string, mixed>
     */
    public function row(InteractionLink $link): array
    {
        $interaction = $link->interaction;

        return [
            'id' => $link->id,
            'type' => $link->type->value,
            'label' => $link->label,
            'url' => $link->url,
            'url_host' => $link->url_host,
            'note' => $link->note,
            'linked_contact' => $link->linkedContact ? [
                'id' => $link->linkedContact->id,
                'name' => $link->linkedContact->full_name,
            ] : null,
            'interaction' => [
                'id' => $interaction->id,
                'occurred_at' => $interaction->occurred_at->toIso8601String(),
                'title' => $interaction->title,
                'mood' => $interaction->mood?->value,
                'mood_score' => $interaction->mood?->score(),
                'outcome' => $interaction->outcome?->value,
                'location_name' => $interaction->location_name,
                'latitude' => $interaction->latitude,
                'longitude' => $interaction->longitude,
            ],
            'contacts' => $interaction->contacts->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->full_name,
                'role' => $c->pivot->role,
            ])->values()->all(),
            'created_at' => $link->created_at?->toIso8601String(),
        ];
    }

    /**
     * The same row flattened to scalar CSV cells (lists joined with "|").
     *
     * @return list<mixed>
     */
    public function csvRow(InteractionLink $link): array
    {
        $row = $this->row($link);

        return [
            $row['id'], $row['type'], $row['label'], $row['url'], $row['url_host'], $row['note'],
            $row['linked_contact']['id'] ?? null, $row['linked_contact']['name'] ?? null,
            $row['interaction']['id'], $row['interaction']['occurred_at'], $row['interaction']['title'],
            $row['interaction']['mood'], $row['interaction']['mood_score'], $row['interaction']['outcome'],
            $row['interaction']['location_name'], $row['interaction']['latitude'], $row['interaction']['longitude'],
            implode('|', array_column($row['contacts'], 'id')),
            implode('|', array_column($row['contacts'], 'name')),
            $row['created_at'],
        ];
    }
}
