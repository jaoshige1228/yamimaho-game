<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BattleActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(['punch', 'kick', 'spell', 'flee'])],
            'spell_id' => ['nullable', 'string', 'required_if:action,spell'],
            'target_id' => ['nullable', 'string'],
        ];
    }
}
