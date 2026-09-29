<?php

namespace App\Http\Resources;

use App\Models\InteractionLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InteractionLink
 */
class InteractionLinkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'label' => $this->label,
            'url' => $this->url,
            'url_host' => $this->url_host,
            'note' => $this->note,
            'linked_contact_id' => $this->linked_contact_id,
            'linked_contact' => $this->whenLoaded('linkedContact', fn () => $this->linkedContact ? [
                'id' => $this->linkedContact->id,
                'full_name' => $this->linkedContact->full_name,
            ] : null),
        ];
    }
}
