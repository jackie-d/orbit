<?php

namespace App\Http\Requests;

use App\Enums\LinkType;
use App\Enums\Mood;
use App\Enums\Outcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Validates interaction create (POST) and update (PUT/PATCH) payloads.
 */
class InteractionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'occurred_at' => [$required, 'date'],
            'title' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:20000'],
            'mood' => ['nullable', Rule::enum(Mood::class)],
            'thoughts' => ['nullable', 'string', 'max:20000'],
            'outcome' => ['nullable', Rule::enum(Outcome::class)],
            'issues' => ['nullable', 'string', 'max:20000'],
            'location_name' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],

            // Either a plain list of ids, or objects carrying a role.
            'contact_ids' => ['sometimes', 'array', 'max:100'],
            'contact_ids.*' => ['integer', 'distinct', $this->ownContact()],
            'contacts' => ['sometimes', 'array', 'max:100', 'prohibits:contact_ids'],
            'contacts.*.id' => ['required', 'integer', 'distinct', $this->ownContact()],
            'contacts.*.role' => ['nullable', 'string', 'max:50'],

            'links' => ['sometimes', 'array', 'max:50'],
            'links.*.id' => ['sometimes', 'nullable', 'integer'],
            'links.*.type' => ['required', Rule::enum(LinkType::class)],
            'links.*.label' => ['nullable', 'required_without:links.*.linked_contact_id', 'string', 'max:255'],
            'links.*.url' => ['nullable', 'url', 'max:2048'],
            'links.*.note' => ['nullable', 'string', 'max:1000'],
            'links.*.linked_contact_id' => ['nullable', 'integer', $this->ownContact()],
        ];
    }

    private function ownContact(): Exists
    {
        return Rule::exists('contacts', 'id')
            ->where('user_id', $this->user()->id)
            ->whereNull('deleted_at');
    }
}
