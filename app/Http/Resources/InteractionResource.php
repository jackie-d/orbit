<?php

namespace App\Http\Resources;

use App\Models\Interaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Interaction
 */
class InteractionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'occurred_at' => $this->occurred_at->toIso8601String(),
            'title' => $this->title,
            'note' => $this->note,
            'mood' => $this->mood?->value,
            'thoughts' => $this->thoughts,
            'outcome' => $this->outcome?->value,
            'issues' => $this->issues,
            'location' => [
                'name' => $this->location_name,
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ],
            'contacts' => $this->whenLoaded('contacts', fn () => $this->contacts->map(fn ($contact) => [
                'id' => $contact->id,
                'full_name' => $contact->full_name,
                'photo_thumb_url' => $contact->photoUrl(thumbnail: true),
                'role' => $contact->pivot->role,
            ])),
            'links' => InteractionLinkResource::collection($this->whenLoaded('links')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
