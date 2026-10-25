<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AdminDungeonSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'skip_battles' => filter_var($this->input('skip_battles', false), FILTER_VALIDATE_BOOLEAN),
            'force_dialogue_events' => filter_var($this->input('force_dialogue_events', false), FILTER_VALIDATE_BOOLEAN),
            'reset_progress' => filter_var($this->input('reset_progress', false), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'skip_battles' => ['required', 'boolean'],
            'force_dialogue_events' => ['required', 'boolean'],
            'reset_progress' => ['required', 'boolean'],
        ];
    }
}
