<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AuditLogFilterRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_.]+$/'],
            'actor' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'entity_id' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{action: string|null, actor: string|null, entity_type: string|null, entity_id: string|null, from: string|null, to: string|null}
     */
    public function filters(): array
    {
        $value = fn (string $key): ?string => $this->validated($key) === null ? null : (string) $this->validated($key);

        return [
            'action' => $value('action'),
            'actor' => $value('actor'),
            'entity_type' => $value('entity_type'),
            'entity_id' => $value('entity_id'),
            'from' => $value('from'),
            'to' => $value('to'),
        ];
    }
}
