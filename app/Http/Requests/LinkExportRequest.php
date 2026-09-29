<?php

namespace App\Http\Requests;

use App\Enums\LinkType;
use App\Services\RecurrenceAnalyzer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LinkExportRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        // Allow ?type=shop,service as well as ?type[]=shop&type[]=service.
        if (is_string($this->query('type'))) {
            $this->merge(['type' => array_filter(explode(',', $this->query('type')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'type' => ['nullable', 'array'],
            'type.*' => [Rule::enum(LinkType::class)],
            'contact_id' => ['nullable', 'integer'],
            'linked_contact_id' => ['nullable', 'integer'],
            'host' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:255'],
            'format' => ['nullable', Rule::in(['json', 'ndjson', 'csv'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('orbit.exports.max_per_page')],
            'cursor' => ['nullable', 'string'],
            'group_by' => ['nullable', Rule::in(RecurrenceAnalyzer::GROUP_BY)],
            'min_occurrences' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
