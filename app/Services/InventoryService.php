<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class InventoryService
{
    private function toMilli(string $value): int
    {
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');

        return ((int) $whole * 1000)
            + (int) str_pad($fraction, 3, '0');
    }

    private function fromMilli(int $value): string
    {
        return intdiv($value, 1000) . '.'
            . str_pad((string) ($value % 1000), 3, '0', STR_PAD_LEFT);
    }

    public function adjust(
        User $user,
        int $shopId,
        int $productId,
        array $data
    ): array {
        return DB::transaction(function () use (
            $user, $shopId, $productId, $data
        ) {
            $shop = DB::table('shops')
                ->where('id', $shopId)
                ->lockForUpdate()
                ->first();

            abort_unless($shop && $shop->status === 'active', 403);

            abort_unless(
                app(AccessService::class)->canManageShop($user, $shopId),
                403
            );

            $existing = DB::table('stock_movements')
                ->where('event_id', $data['event_id'])
                ->first();

            $change = $this->toMilli((string) $data['quantity']);

            if ($data['direction'] === 'decrease') {
                $change = -$change;
            }

            if (
                ($data['movement_type'] === 'stock_received'
                    && $data['direction'] !== 'increase') ||
                ($data['movement_type'] === 'stock_removed'
                    && $data['direction'] !== 'decrease')
            ) {
                throw new HttpException(
                    422,
                    'Movement type and direction do not match.'
                );
            }

            $fingerprint = hash('sha256', json_encode([
                $shopId,
                $productId,
                $data['movement_type'],
                $change,
                $data['notes'],
            ]));

            if ($existing) {
                if (
                    (int) $existing->shop_id !== $shopId ||
                    (int) $existing->product_id !== $productId ||
                    $existing->reference_id !== $fingerprint
                ) {
                    throw new ConflictHttpException(
                        'Event ID already used for another operation.'
                    );
                }

                return [
                    'event_id' => $existing->event_id,
                    'quantity_after' => $existing->quantity_after,
                    'replayed' => true,
                ];
            }

            $inventory = DB::table('inventories')
                ->where('shop_id', $shopId)
                ->where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            abort_unless($inventory, 404, 'Inventory not found.');

            $productExists = DB::table('products')
                ->where('id', $productId)
                ->where('shop_id', $shopId)
                ->where('is_active', true)
                ->exists();

            abort_unless($productExists, 404, 'Product not found.');

            $before = $this->toMilli(
                (string) $inventory->quantity_on_hand
            );

            $after = $before + $change;

            if ($after < 0) {
                throw new HttpException(422, 'Insufficient stock.');
            }

            // DECIMAL(14,3) allows up to 11 whole-number digits.
            if ($after > 99999999999999) {
                throw new HttpException(
                    422,
                    'Stock quantity exceeds database limit.'
                );
            }

            $beforeValue = $this->fromMilli($before);
            $afterValue = $this->fromMilli($after);
            $changeValue = ($change < 0 ? '-' : '')
                . $this->fromMilli(abs($change));

            DB::table('inventories')
                ->where('id', $inventory->id)
                ->update([
                    'quantity_on_hand' => $afterValue,
                    'updated_at' => now(),
                ]);

            DB::table('stock_movements')->insert([
                'event_id' => $data['event_id'],
                'shop_id' => $shopId,
                'product_id' => $productId,
                'performed_by' => $user->id,
                'movement_type' => $data['movement_type'],
                'quantity_change' => $changeValue,
                'quantity_before' => $beforeValue,
                'quantity_after' => $afterValue,
                'reference_type' => 'manual',
                'reference_id' => $fingerprint,
                'notes' => $data['notes'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return [
                'event_id' => $data['event_id'],
                'quantity_before' => $beforeValue,
                'quantity_after' => $afterValue,
                'replayed' => false,
            ];
        }, 3);
    }
}