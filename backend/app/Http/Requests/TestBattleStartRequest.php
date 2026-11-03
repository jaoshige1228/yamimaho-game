<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TestBattleStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset' => ['nullable', 'string', 'in:floor1_boss,floor2_boss,floor2_mid_boss'],
            'floor' => ['nullable', 'integer', 'min:1'],
            'code' => ['nullable', 'string', 'max:64'],
            'count' => ['nullable', 'integer', 'min:1', 'max:3'],
            // 自由装備モード: スロット ID => この戦闘だけ使う装備（キーなし=所持装備のまま、null=装備なし）
            'equipment' => ['nullable', 'array'],
            'equipment.*' => ['array'],
            'equipment.*.weapon' => ['nullable', 'string', Rule::exists('weapon_masters', 'code')],
            'equipment.*.armor' => ['nullable', 'string', Rule::exists('armor_masters', 'code')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $slots = array_keys(config('game.party_slots', []));
            foreach (array_keys((array) $this->input('equipment', [])) as $slotId) {
                if (! in_array($slotId, $slots, true)) {
                    $validator->errors()->add('equipment', "不明な味方スロットです: {$slotId}");
                }
            }

            $preset = $this->input('preset');
            $hasRegular = $this->filled('floor') || $this->filled('code') || $this->filled('count');

            if ($preset !== null && $hasRegular) {
                $validator->errors()->add('preset', 'ボス戦と通常敵の指定は同時にできません。');

                return;
            }

            if ($preset === null) {
                if (! $this->filled('floor') || ! $this->filled('code') || ! $this->filled('count')) {
                    $validator->errors()->add('floor', '敵・出現数を指定するか、ボス戦プリセットを選んでください。');
                }
            }
        });
    }
}
