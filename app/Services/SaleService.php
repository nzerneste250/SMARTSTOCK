<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SaleService
{
    private function scaled(string $value, int $digits): int
    {
        $parts = explode('.', $value, 2);
        $factor = 10 ** $digits;
        $whole = $parts[0];
        $fraction = str_pad($parts[1] ?? '', $digits, '0');

        if (!preg_match('/^\d+$/', $whole) ||
            !preg_match('/^\d*$/', $fraction) ||
            strlen($fraction) > $digits) {
            throw new HttpException(422, 'Invalid decimal value.');
        }

        $limit = intdiv(PHP_INT_MAX, $factor);

        if (strlen($whole) > strlen((string) $limit) ||
            (strlen($whole) === strlen((string) $limit)
                && strcmp($whole, (string) $limit) > 0)) {
            throw new HttpException(422, 'Quantity or price exceeds limit.');
        }

        return ((int) $whole * $factor) + (int) $fraction;
    }

    private function decimal(int $value, int $digits): string
    {
        $factor = 10 ** $digits;

        return intdiv($value, $factor) . '.'
            . str_pad((string) ($value % $factor), $digits, '0', STR_PAD_LEFT);
    }

    private function receipt(int $saleId): array
    {
        $sale = DB::table('sales')->where('id', $saleId)->first();

        $items = DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->orderBy('id')
            ->get();

        return [
            'sale' => $sale,
            'items' => $items,
        ];
    }

    public function create(User $user, int $shopId, array $data): array
    {
        return DB::transaction(function () use ($user, $shopId, $data) {
            $shop = DB::table('shops')
                ->where('id', $shopId)
                ->lockForUpdate()
                ->first();

            abort_unless($shop && $shop->status === 'active', 403);

            abort_unless(
                app(AccessService::class)->canProcessSales($user, $shopId),
                403
            );

            $requestedItems = [];

            foreach ($data['items'] as $item) {
                $id = (int) $item['product_id'];

                if (isset($requestedItems[$id])) {
                    throw new HttpException(422, 'Duplicate product.');
                }

                $requestedItems[$id] = $this->scaled(
                    (string) $item['quantity'],
                    3
                );
            }

            ksort($requestedItems, SORT_NUMERIC);

            $existing = DB::table('sales')
                ->where('event_id', $data['event_id'])
                ->first();

            if ($existing) {
                $previousItems = DB::table('sale_items')
                    ->where('sale_id', $existing->id)
                    ->get();

                $previous = [];

                foreach ($previousItems as $item) {
                    $previous[(int) $item->product_id] = $this->scaled(
                        (string) $item->quantity,
                        3
                    );
                }

                ksort($previous, SORT_NUMERIC);

                if ((int) $existing->shop_id !== $shopId ||
                    $existing->payment_method !== $data['payment_method'] ||
                    $previous !== $requestedItems) {
                    throw new ConflictHttpException(
                        'This sale event ID belongs to a different sale.'
                    );
                }

                return [
                    'replayed' => true,
                    'receipt' => $this->receipt((int) $existing->id),
                ];
            }

            $lines = [];
            $totalCents = 0;
            $maxTotalCents = 999999999999999;

            foreach ($requestedItems as $productId => $quantityMilli) {
                $product = DB::table('products')
                    ->where('id', $productId)
                    ->where('shop_id', $shopId)
                    ->where('is_active', true)
                    ->first();

                abort_unless($product, 404, 'Product not found.');

                $inventory = DB::table('inventories')
                    ->where('shop_id', $shopId)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                abort_unless($inventory, 404, 'Inventory not found.');

                $availableMilli = $this->scaled(
                    (string) $inventory->quantity_on_hand,
                    3
                );

                if ($quantityMilli <= 0 ||
                    $availableMilli < $quantityMilli) {
                    throw new HttpException(422, 'Insufficient stock.');
                }

                $priceCents = $this->scaled(
                    (string) $product->selling_price,
                    2
                );

                if ($priceCents <= 0) {
                    throw new HttpException(
                        422,
                        'Product selling price must be positive.'
                    );
                }

                if ($priceCents > intdiv(PHP_INT_MAX - 500, $quantityMilli)) {
                    throw new HttpException(422, 'Sale amount exceeds limit.');
                }

                // Round each line to the nearest cent, half-up.
                $lineCents = intdiv(
                    ($priceCents * $quantityMilli) + 500,
                    1000
                );

                if ($lineCents > $maxTotalCents - $totalCents) {
                    throw new HttpException(422, 'Sale total exceeds limit.');
                }

                $totalCents += $lineCents;

                $lines[] = [
                    'product' => $product,
                    'inventory' => $inventory,
                    'quantity_milli' => $quantityMilli,
                    'before_milli' => $availableMilli,
                    'after_milli' => $availableMilli - $quantityMilli,
                    'price_cents' => $priceCents,
                    'line_cents' => $lineCents,
                ];
            }

            $receiptNumber = 'SS-' . $shopId . '-'
                . strtoupper(str_replace('-', '', $data['event_id']));

            $saleId = DB::table('sales')->insertGetId([
                'event_id' => $data['event_id'],
                'shop_id' => $shopId,
                'cashier_id' => $user->id,
                'receipt_number' => $receiptNumber,
                'subtotal' => $this->decimal($totalCents, 2),
                'total' => $this->decimal($totalCents, 2),
                'payment_method' => $data['payment_method'],
                'status' => 'completed',
                'sold_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($lines as $line) {
                $product = $line['product'];
                $inventory = $line['inventory'];

                DB::table('sale_items')->insert([
                    'sale_id' => $saleId,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'sku' => $product->sku,
                    'quantity' => $this->decimal(
                        $line['quantity_milli'],
                        3
                    ),
                    'unit_price' => $this->decimal(
                        $line['price_cents'],
                        2
                    ),
                    'line_total' => $this->decimal(
                        $line['line_cents'],
                        2
                    ),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('inventories')
                    ->where('id', $inventory->id)
                    ->update([
                        'quantity_on_hand' => $this->decimal(
                            $line['after_milli'],
                            3
                        ),
                        'updated_at' => now(),
                    ]);

                DB::table('stock_movements')->insert([
                    'event_id' => (string) Str::uuid(),
                    'shop_id' => $shopId,
                    'product_id' => $product->id,
                    'performed_by' => $user->id,
                    'movement_type' => 'sale',
                    'quantity_change' => '-'
                        . $this->decimal($line['quantity_milli'], 3),
                    'quantity_before' => $this->decimal(
                        $line['before_milli'],
                        3
                    ),
                    'quantity_after' => $this->decimal(
                        $line['after_milli'],
                        3
                    ),
                    'reference_type' => 'sale',
                    'reference_id' => (string) $saleId,
                    'notes' => 'POS sale ' . $receiptNumber,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return [
                'replayed' => false,
                'receipt' => $this->receipt($saleId),
            ];
        }, 3);
    }
}