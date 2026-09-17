<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Actions\ManageUserScopes;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserScopeRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'scope_type' => ['required', 'string', Rule::in(ManageUserScopes::TYPES)],
            'scope_id' => ['exclude_if:scope_type,all', 'required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
