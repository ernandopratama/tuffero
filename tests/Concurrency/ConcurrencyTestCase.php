<?php

namespace Tests\Concurrency;

use App\Models\InventoryStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Dasar tes konkurensi stok. Tanpa RefreshDatabase dan tanpa transaksi pembungkus: data harus COMMIT supaya proses lain
 * melihatnya. Tiap putaran mengosongkan tabel pesanan dan stok, membuat fixture, melombakan N proses PHP yang dilepas
 * serentak lewat penghalang berkas, lalu memeriksa invarian stok (lihat assertInvariants).
 */
#[Group('concurrency')]
abstract class ConcurrencyTestCase extends TestCase
{
    /** Tabel yang dikosongkan tiap putaran (urutan anak dulu). Pemeriksaan FK dimatikan sementara, hanya di database tes. */
    private const RESET_TABLES = [
        'stock_movements', 'stock_reservations', 'voucher_redemptions', 'order_status_histories', 'order_items', 'orders',
        'inventory_stocks', 'audit_logs', 'notifications', 'product_images', 'product_variants', 'products', 'vouchers',
    ];

    private static bool $migrated = false;

    protected Warehouse $warehouse;

    protected User $actor;

    /** @var array<int, int> stok id => jumlah fisik awal, untuk memeriksa catatan pergerakan. */
    private array $initialQuantity = [];

    /** @var list<Process> */
    private array $workers = [];

    private string $dir = '';

    private int $round = 0;

    private int $phoneSeed = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (Boot::settings() === null) {
            $this->markTestSkipped('Tes konkurensi butuh MySQL 8.4 atau MariaDB 10.6. Atur CONCURRENCY_DB_DATABASE (lihat docs/TESTING_CONCURRENCY.md).');
        }

        Boot::app();

        if (! self::$migrated) {
            // Aman: Boot::app() sudah memastikan koneksi hanya ke database tuffero_concurrency_test di mesin lokal.
            Artisan::call('migrate:fresh', ['--force' => true]);
            // Peran staf harus ada: notifikasi stok menipis memanggil toRoles() dan melempar bila perannya tidak ada.
            Artisan::call('db:seed', ['--class' => RolePermissionSeeder::class, '--force' => true]);
            self::$migrated = true;
        }

        foreach (self::RESET_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Tabel {$table} tidak ada; daftar RESET_TABLES perlu disesuaikan.");
        }
    }

    protected function tearDown(): void
    {
        $this->stopWorkers();

        parent::tearDown();
    }

    protected function rounds(): int
    {
        return max(1, (int) (getenv('CONCURRENCY_ROUNDS') ?: 20));
    }

    /**
     * Menjalankan satu skenario berulang kali.
     *
     * @param  int  $workers  Jumlah proses pembalap.
     * @param  callable(int): array<int, array{action: string, args: array<string, mixed>}>  $setup  Membuat fixture dan mengembalikan aksi tiap worker (indeks = nomor worker).
     * @param  callable(list<array<string, mixed>>, int): void  $verify  Menerima hasil tiap worker dan nomor putaran.
     */
    protected function scenario(int $workers, callable $setup, callable $verify): void
    {
        $this->startWorkers($workers);

        for ($round = 1; $round <= $this->rounds(); $round++) {
            $this->resetDatabase();
            $actions = $setup($round);
            $results = $this->race($actions);

            $this->assertInvariants("putaran {$round}");
            $this->assertNoUnexpectedFailures($results, $round);
            $verify($results, $round);
        }

        $this->stopWorkers();
    }

    /** @return array{0: ProductVariant, 1: InventoryStock} */
    protected function fixtureVariant(string $sku, int $quantity): array
    {
        $product = Product::create(['name' => "Produk {$sku}", 'slug' => strtolower($sku), 'is_published' => true]);
        $variant = $product->variants()->create(['sku' => $sku, 'name' => 'Standar', 'price' => 50000, 'weight_gram' => 500]);
        $stock = InventoryStock::create(['warehouse_id' => $this->warehouse->id, 'product_variant_id' => $variant->id, 'quantity' => $quantity]);
        $this->initialQuantity[$stock->id] = $quantity;

        return [$variant, $stock];
    }

    /** Nomor HP unik per panggilan, supaya kunci per-nomor di checkout tidak menyerialkan pembeli yang berbeda. */
    protected function phone(): string
    {
        return '0812'.str_pad((string) (++$this->phoneSeed), 8, '0', STR_PAD_LEFT);
    }

    /**
     * Membuat pesanan (satu proses, lewat CheckoutService asli) sebagai kondisi awal skenario.
     *
     * @param  list<array{0: string, 1: int}>  $items
     */
    protected function placeOrder(array $items, ?string $phone = null, bool $expired = false, ?string $voucher = null): Order
    {
        $phone ??= $this->phone();
        $result = Actions::run(Actions::prepare('checkout', ['phone' => $phone, 'key' => 'seed-'.bin2hex(random_bytes(8)), 'items' => $items, 'voucher_code' => $voucher]));
        $this->assertSame('ok', $result['type'], 'Pesanan awal gagal dibuat: '.json_encode($result));

        $order = Order::findOrFail($result['value']['id']);

        if ($expired) {
            $order->forceFill(['expires_at' => now()->subMinute()])->save();
        }

        return $order;
    }

    protected function stockOf(ProductVariant $variant): InventoryStock
    {
        return InventoryStock::where('product_variant_id', $variant->id)->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $results
     * @return list<array<string, mixed>>
     */
    protected function ofType(array $results, string $type): array
    {
        return array_values(array_filter($results, fn (array $result) => $result['type'] === $type));
    }

    /**
     * Penolakan stok habis yang sah dari checkout: pesan stok pada kunci cart (cart_changed, stock_split, stock_gone).
     *
     * @param  array<string, mixed>  $result
     */
    protected function isStockRejection(array $result): bool
    {
        return $result['type'] === 'validation'
            && isset($result['errors']['cart'])
            && array_diff($result['errors']['cart'], ['cart_changed', 'stock_split', 'stock_gone']) === [];
    }

    /**
     * Invarian yang harus benar setelah setiap putaran, apa pun urutan eksekusinya:
     * 1. 0 <= reserved <= quantity (tidak minus, tidak menahan lebih dari fisik).
     * 2. reserved = jumlah reservasi berstatus active, dan = jumlah item pesanan yang masih menunggu (pending_payment/awaiting_approval).
     * 3. Catatan pergerakan cocok: quantity = awal + sum(quantity_change), reserved = sum(reserved_change), dan baris terakhir
     *    mencatat nilai akhir.
     * 4. Per reservasi: pergerakan reserved bersih = jumlahnya bila active, 0 bila sudah consumed/released; satu reservasi
     *    tidak pernah dilepas atau dijual dua kali.
     */
    protected function assertInvariants(string $context): void
    {
        foreach (DB::table('inventory_stocks')->get() as $stock) {
            $label = "{$context}, stok #{$stock->id}";

            $this->assertGreaterThanOrEqual(0, $stock->quantity, "{$label}: stok fisik minus");
            $this->assertGreaterThanOrEqual(0, $stock->reserved, "{$label}: reserved minus");
            $this->assertLessThanOrEqual($stock->quantity, $stock->reserved, "{$label}: reserved melebihi stok fisik (overselling)");

            $active = (int) DB::table('stock_reservations')->where(['warehouse_id' => $stock->warehouse_id, 'product_variant_id' => $stock->product_variant_id, 'status' => 'active'])->sum('quantity');
            $this->assertSame($active, (int) $stock->reserved, "{$label}: reserved tidak sama dengan reservasi aktif");

            $waiting = (int) DB::table('order_items')
                ->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->where('orders.warehouse_id', $stock->warehouse_id)
                ->where('order_items.product_variant_id', $stock->product_variant_id)
                ->whereIn('orders.status', [Order::PENDING_PAYMENT, Order::AWAITING_APPROVAL])
                ->sum('order_items.quantity');
            $this->assertSame($waiting, (int) $stock->reserved, "{$label}: reserved tidak sama dengan item pesanan yang menunggu");

            $movements = DB::table('stock_movements')->where('inventory_stock_id', $stock->id);
            $this->assertSame(($this->initialQuantity[$stock->id] ?? 0) + (int) (clone $movements)->sum('quantity_change'), (int) $stock->quantity, "{$label}: stok fisik tidak cocok dengan pergerakan");
            $this->assertSame((int) (clone $movements)->sum('reserved_change'), (int) $stock->reserved, "{$label}: reserved tidak cocok dengan pergerakan");

            $last = (clone $movements)->orderByDesc('id')->first();
            if ($last) {
                $this->assertSame([(int) $stock->quantity, (int) $stock->reserved], [(int) $last->quantity_after, (int) $last->reserved_after], "{$label}: baris pergerakan terakhir tidak sama dengan stok");
            }

            foreach (DB::table('stock_reservations')->where(['warehouse_id' => $stock->warehouse_id, 'product_variant_id' => $stock->product_variant_id])->get() as $reservation) {
                $net = (int) DB::table('stock_movements')->where(['inventory_stock_id' => $stock->id, 'order_id' => $reservation->order_id])->sum('reserved_change');
                $this->assertSame($reservation->status === 'active' ? (int) $reservation->quantity : 0, $net, "{$label}: reservasi #{$reservation->id} ({$reservation->status}) dilepas atau dijual lebih dari sekali");

                $closing = DB::table('stock_movements')->where(['inventory_stock_id' => $stock->id, 'order_id' => $reservation->order_id])->whereIn('type', ['sale', 'release'])->count();
                $this->assertLessThanOrEqual(1, $closing, "{$label}: reservasi #{$reservation->id} ditutup {$closing} kali");
            }
        }
    }

    /** Hasil yang bukan sukses dan bukan penolakan bisnis (deadlock yang lolos, error tak terduga) adalah temuan, bukan "tidak apa-apa". */
    private function assertNoUnexpectedFailures(array $results, int $round): void
    {
        $bad = array_filter($results, fn (array $r) => in_array($r['type'], ['db', 'error'], true));

        $this->assertSame([], array_values($bad), "Putaran {$round}: ada kegagalan tak terduga (deadlock atau error): ".json_encode(array_values($bad)));
    }

    /**
     * @param  array<int, array{action: string, args: array<string, mixed>}>  $actions
     * @return list<array<string, mixed>>
     */
    private function race(array $actions): array
    {
        $round = ++$this->round;
        $count = count($this->workers);
        $this->assertLessThanOrEqual($count, count($actions));

        // Tulis lalu rename: worker mengecek keberadaan berkas, jadi berkas setengah jadi akan terbaca sebagai JSON rusak.
        file_put_contents("{$this->dir}/plan_{$round}.tmp", json_encode($actions, JSON_THROW_ON_ERROR));
        rename("{$this->dir}/plan_{$round}.tmp", "{$this->dir}/plan_{$round}");
        $this->waitFor(fn () => $this->allExist('armed', $round, $count), "worker siap (putaran {$round})");

        // Penghalang: semua worker sudah menyiapkan aksi dan menunggu berkas ini.
        touch("{$this->dir}/go_{$round}");
        $this->waitFor(fn () => $this->allExist('result', $round, $count), "hasil worker (putaran {$round})");

        $results = [];
        for ($i = 0; $i < $count; $i++) {
            $results[] = json_decode(file_get_contents("{$this->dir}/result_{$round}_{$i}"), true, flags: JSON_THROW_ON_ERROR);
        }

        return $results;
    }

    private function allExist(string $kind, int $round, int $count): bool
    {
        clearstatcache(true);
        for ($i = 0; $i < $count; $i++) {
            if (! file_exists($kind === 'armed' ? "{$this->dir}/armed_{$round}_{$i}" : "{$this->dir}/result_{$round}_{$i}")) {
                return false;
            }
        }

        return true;
    }

    private function waitFor(callable $condition, string $what, float $timeout = 120.0): void
    {
        $deadline = microtime(true) + $timeout;

        while (! $condition()) {
            foreach ($this->workers as $i => $process) {
                if (! $process->isRunning()) {
                    throw new RuntimeException("Worker {$i} berhenti saat menunggu {$what}: ".trim($process->getErrorOutput().$process->getOutput()));
                }
            }
            if (microtime(true) > $deadline) {
                throw new RuntimeException("Batas waktu menunggu {$what}.");
            }
            usleep(500);
        }
    }

    private function startWorkers(int $count): void
    {
        $this->dir = sys_get_temp_dir().'/tuffero-cc-'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->round = 0;

        for ($i = 0; $i < $count; $i++) {
            $process = new Process([PHP_BINARY, __DIR__.'/worker.php', $this->dir, (string) $i], dirname(__DIR__, 2), Boot::environment(), null, 300);
            $process->start();
            $this->workers[] = $process;
        }

        $this->waitFor(function () use ($count) {
            clearstatcache(true);
            for ($i = 0; $i < $count; $i++) {
                if (! file_exists("{$this->dir}/up_{$i}")) {
                    return false;
                }
            }

            return true;
        }, 'worker menyala', 180.0);
    }

    private function stopWorkers(): void
    {
        if ($this->dir !== '' && is_dir($this->dir)) {
            touch("{$this->dir}/stop");
        }
        foreach ($this->workers as $process) {
            $process->stop(5);
        }
        $this->workers = [];

        if ($this->dir !== '' && is_dir($this->dir)) {
            array_map('unlink', glob("{$this->dir}/*") ?: []);
            @rmdir($this->dir);
        }
        $this->dir = '';
    }

    private function resetDatabase(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (self::RESET_TABLES as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        $this->initialQuantity = [];
        $this->warehouse = Warehouse::firstOrCreate(['code' => 'MLG'], ['name' => 'Gudang Malang', 'city' => 'Malang', 'is_active' => true]);
        $this->actor = User::firstOrCreate(['email' => 'konkurensi@example.test'], ['name' => 'Penguji Konkurensi', 'password' => 'tidak-dipakai']);
    }
}
