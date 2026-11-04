<?php

namespace App\Http\Requests;

use App\Services\Dungeon\DungeonFloorConfig;
use Illuminate\Foundation\Http\FormRequest;

class AdminDungeonClearRequest extends FormRequest
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
            'floor' => ['required', 'integer', 'min:1', 'max:'.DungeonFloorConfig::maxFloor()],
        ];
    }
}
