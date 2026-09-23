<?php

namespace App\Http\Requests\Admin;

use App\Rules\AssignableRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InviteAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->can('menu.admins') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160', Rule::unique('admins', 'email')],
            'role_id' => ['required', Rule::exists('roles', 'id'), new AssignableRole],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.unique' => 'An admin with this email already exists.'];
    }
}
