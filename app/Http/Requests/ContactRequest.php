<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates contact create (POST) and update (PUT/PATCH) payloads.
 * On update every field is optional; nested collections, when present,
 * replace the existing ones.
 */
class ContactRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'first_name' => [$required, 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'nickname' => ['nullable', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'birthday' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'is_favorite' => ['sometimes', 'boolean'],

            'phone_numbers' => ['sometimes', 'array', 'max:20'],
            'phone_numbers.*.label' => ['nullable', 'string', 'max:50'],
            'phone_numbers.*.number' => ['required', 'string', 'max:50', 'regex:/^[0-9+().\-\s#*]+$/'],
            'phone_numbers.*.is_primary' => ['sometimes', 'boolean'],

            'emails' => ['sometimes', 'array', 'max:20'],
            'emails.*.label' => ['nullable', 'string', 'max:50'],
            'emails.*.email' => ['required', 'email', 'max:255'],
            'emails.*.is_primary' => ['sometimes', 'boolean'],

            'urls' => ['sometimes', 'array', 'max:20'],
            'urls.*.label' => ['nullable', 'string', 'max:50'],
            'urls.*.url' => ['required', 'url', 'max:2048'],
            'urls.*.is_primary' => ['sometimes', 'boolean'],

            'addresses' => ['sometimes', 'array', 'max:10'],
            'addresses.*.label' => ['nullable', 'string', 'max:50'],
            'addresses.*.street' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['nullable', 'string', 'max:255'],
            'addresses.*.region' => ['nullable', 'string', 'max:255'],
            'addresses.*.postal_code' => ['nullable', 'string', 'max:32'],
            'addresses.*.country' => ['nullable', 'string', 'max:255'],
            'addresses.*.is_primary' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->validated();

        // Drop null labels so the column defaults (mobile/home/...) apply.
        foreach (['phone_numbers', 'emails', 'urls', 'addresses'] as $key) {
            if (isset($data[$key])) {
                $data[$key] = array_map(fn (array $item) => array_filter($item, fn ($v, $k) => ! ($k === 'label' && $v === null), ARRAY_FILTER_USE_BOTH), $data[$key]);
            }
        }

        return $data;
    }
}
