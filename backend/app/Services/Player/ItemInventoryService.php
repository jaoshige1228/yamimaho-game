<?php

namespace App\Services\Player;

use App\Models\ItemMaster;
use App\Models\User;
use App\Models\UserItem;
use Illuminate\Support\Collection;

class ItemInventoryService
{
    /** @var array<string, int> */
    private const STARTER_ITEMS = [
        'potion_hp_s' => 3,
        'potion_mp_s' => 3,
        'potion_hp_m' => 1,
        'potion_mp_m' => 1,
        'world_tree_leaf' => 1,
    ];

    public function grantStarterItems(User $user): void
    {
        foreach (self::STARTER_ITEMS as $code => $quantity) {
            $item = ItemMaster::query()->where('code', $code)->first();
            if ($item === null) {
                continue;
            }

            $record = UserItem::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'item_master_id' => $item->id,
                ],
                ['quantity' => 0],
            );

            $record->quantity = $quantity;
            $record->save();
        }
    }

    public function resetItems(User $user): void
    {
        UserItem::query()->where('user_id', $user->id)->delete();
        $this->grantStarterItems($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inventoryPayload(User $user): array
    {
        $items = UserItem::query()
            ->where('user_id', $user->id)
            ->where('quantity', '>', 0)
            ->with('itemMaster')
            ->get()
            ->sortBy(fn (UserItem $row) => $row->itemMaster->code);

        return $items->map(fn (UserItem $row) => $this->itemPayload($row))->values()->all();
    }

    public function consume(User $user, string $itemCode, int $amount = 1): void
    {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('数量が不正です。');
        }

        $item = ItemMaster::query()->where('code', $itemCode)->first();
        if ($item === null) {
            throw new \InvalidArgumentException('アイテムが見つかりません。');
        }

        $record = UserItem::query()
            ->where('user_id', $user->id)
            ->where('item_master_id', $item->id)
            ->first();

        if ($record === null || $record->quantity < $amount) {
            throw new \InvalidArgumentException('アイテムが足りません。');
        }

        $record->quantity -= $amount;
        if ($record->quantity <= 0) {
            $record->delete();
        } else {
            $record->save();
        }
    }

    public function findItem(string $code): ItemMaster
    {
        $item = ItemMaster::query()->where('code', $code)->first();
        if ($item === null) {
            throw new \InvalidArgumentException('アイテムが見つかりません。');
        }

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemPayload(UserItem $row): array
    {
        $item = $row->itemMaster;

        return [
            'code' => $item->code,
            'name' => $item->name,
            'description' => $item->description,
            'effect' => $item->effect,
            'power' => $item->power,
            'quantity' => $row->quantity,
        ];
    }
}
