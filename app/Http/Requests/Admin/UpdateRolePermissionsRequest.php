<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Authorization\Permissions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRolePermissionsRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'permissions' => ['present', 'array', 'max:'.count(Permissions::codes())],
            'permissions.*' => ['string', 'distinct', Rule::in(Permissions::codes())],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(array_map(strval(...), (array) $this->validated('permissions')));
    }
}
