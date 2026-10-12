<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.concurrency');
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$database = DB::connection()->getDatabaseName();

if (
    config('database.default') !== 'mysql' ||
    $database !== 'smartstock_concurrency_test'
) {
    fwrite(STDERR, "REFUSED: unsafe database {$database}\n");
    exit(2);
}

$mode = $argv[1] ?? '';
$fixturePath = dirname(__DIR__) . '/storage/app/pos_concurrency_fixture.json';

if ($mode === 'seed') {
    $fixture = DB::transaction(function () {
        $shopId = DB::table('shops')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'name' => 'Concurrency Test Shop',
            'code' => 'CT-' . Str::random(14),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cashierIds = [];

        foreach ([1, 2] as $number) {
            $userId = DB::table('users')->insertGetId([
                'name' => "Concurrency Cashier {$number}",
                'email' => 'ct-' . Str::uuid() . '@example.test',
                'password' => bcrypt((string) Str::uuid()),
                'platform_role' => 'customer',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('shop_memberships')->insert([
                'shop_id' => $shopId,
                'user_id' => $userId,
                'role' => 'cashier',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $cashierIds[] = $userId;
        }

        $productId = DB::table('products')->insertGetId([
            'public_id' => (string) Str::uuid(),
            'shop_id' => $shopId,
            'name' => 'Last Available Item',
            'sku' => 'CT-' . Str::random(14),
            'selling_price' => '1500.00',
            'unit' => 'piece',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventories')->insert([
            'shop_id' => $shopId,
            'product_id' => $productId,
            'quantity_on_hand' => '1.000',
            'low_stock_threshold' => '0.000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'shop_id' => $shopId,
            'product_id' => $productId,
            'cashier_ids' => $cashierIds,
            'event_ids' => [
                (string) Str::uuid(),
                (string) Str::uuid(),
            ],
        ];
    });

    file_put_contents(
        $fixturePath,
        json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
    );

    echo "Created isolated test fixture.\n";
    echo "Shop: {$fixture['shop_id']}\n";
    echo "Product: {$fixture['product_id']}\n";
    echo "Starting stock: 1.000\n";
    exit(0);
}

if (!is_file($fixturePath)) {
    throw new RuntimeException('Run seed first.');
}

$fixture = json_decode(
    file_get_contents($fixturePath),
    true,
    512,
    JSON_THROW_ON_ERROR
);

if ($mode === 'worker') {
    $index = (int) ($argv[2] ?? -1);
    $targetMs = (int) ($argv[3] ?? 0);

    if (!in_array($index, [0, 1], true) || $targetMs <= 0) {
        throw new RuntimeException('Invalid worker arguments.');
    }

    $user = User::findOrFail($fixture['cashier_ids'][$index]);

    while (microtime(true) * 1000 < $targetMs) {
        usleep(10000);
    }

    $started = microtime(true);

    try {
        $result = app(SaleService::class)->create(
            $user,
            $fixture['shop_id'],
            [
                'event_id' => $fixture['event_ids'][$index],
                'payment_method' => 'cash',
                'items' => [
                    [
                        'product_id' => $fixture['product_id'],
                        'quantity' => '1',
                    ],
                ],
            ]
        );

        echo json_encode([
            'worker' => $index + 1,
            'result' => 'success',
            'replayed' => $result['replayed'],
            'elapsed_ms' => round((microtime(true) - $started) * 1000),
        ], JSON_PRETTY_PRINT) . PHP_EOL;

    } catch (\Throwable $e) {
        $status = $e instanceof HttpExceptionInterface
            ? $e->getStatusCode()
            : 500;

        echo json_encode([
            'worker' => $index + 1,
            'result' => 'rejected',
            'http_status' => $status,
            'error' => $e->getMessage(),
            'elapsed_ms' => round((microtime(true) - $started) * 1000),
        ], JSON_PRETTY_PRINT) . PHP_EOL;

        if ($status !== 422) {
            exit(1);
        }
    }

    exit(0);
}

if ($mode === 'verify') {
    $shopId = $fixture['shop_id'];
    $productId = $fixture['product_id'];

    $stock = DB::table('inventories')
        ->where('shop_id', $shopId)
        ->where('product_id', $productId)
        ->value('quantity_on_hand');

    $sales = DB::table('sales')
        ->where('shop_id', $shopId)
        ->count();

    $items = DB::table('sale_items')
        ->where('product_id', $productId)
        ->count();

    $movements = DB::table('stock_movements')
        ->where('shop_id', $shopId)
        ->where('product_id', $productId)
        ->where('movement_type', 'sale')
        ->count();

    echo json_encode([
        'database' => $database,
        'final_stock' => $stock,
        'sales' => $sales,
        'sale_items' => $items,
        'sale_movements' => $movements,
    ], JSON_PRETTY_PRINT) . PHP_EOL;

    if (
        (float) $stock !== 0.0 ||
        $sales !== 1 ||
        $items !== 1 ||
        $movements !== 1
    ) {
        fwrite(STDERR, "FAILED: concurrency invariants violated.\n");
        exit(1);
    }

    echo "PASS: final inventory and sale counts are consistent.\n";
    exit(0);
}

throw new RuntimeException('Unknown mode.');