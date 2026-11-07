<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdminEquipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 武器・防具はどちらか片方だけでも変えられる（送らなかった側は今のまま）。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slot_id' => ['required', 'string', Rule::in(array_keys(config('game.party_slots', [])))],
            'weapon_code' => ['required_without:armor_code', 'nullable', 'string', Rule::exists('weapon_masters', 'code')],
            'armor_code' => ['required_without:weapon_code', 'nullable', 'string', Rule::exists('armor_masters', 'code')],
        ];
    }
}
