<?php

namespace App\Http\Resources;

use App\Models\Contact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin Contact
 */
class ContactResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $detail = fn (string $relation, array $fields) => $this->whenLoaded($relation, fn () => $this->{$relation}->map(
            fn ($item) => ['id' => $item->id, ...$item->only($fields)]
        ));

        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'nickname' => $this->nickname,
            'company' => $this->company,
            'job_title' => $this->job_title,
            'birthday' => $this->birthday?->toDateString(),
            'notes' => $this->notes,
            'is_favorite' => $this->is_favorite,
            'photo_url' => $this->photoUrl(),
            'photo_thumb_url' => $this->photoUrl(thumbnail: true),
            'phone_numbers' => $detail('phoneNumbers', ['label', 'number', 'is_primary']),
            'emails' => $detail('emails', ['label', 'email', 'is_primary']),
            'urls' => $detail('urls', ['label', 'url', 'is_primary']),
            'addresses' => $detail('addresses', ['label', 'street', 'city', 'region', 'postal_code', 'country', 'is_primary']),
            'interactions_count' => $this->whenCounted('interactions'),
            'last_interaction_at' => $this->when(
                array_key_exists('interactions_max_occurred_at', $this->getAttributes()),
                fn () => $this->interactions_max_occurred_at ? Carbon::parse($this->interactions_max_occurred_at)->toIso8601String() : null,
            ),
            'role' => $this->whenPivotLoaded('contact_interaction', fn () => $this->pivot->role),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
