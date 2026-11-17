<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\CustomerBlock;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\StaffEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Blokir otomatis retur berturut-turut (D-122). Definisi (diputuskan Ernando 16 Nov 2026): N pesanan terakhir yang sudah diterima atas
 * nomor WhatsApp yang sama (N = `antifraud.return_block_streak`, bawaan 3) semuanya berujung retur produk utuh (`return_unopened`)
 * yang disetujui. Satu pesanan tanpa retur memutus hitungan. Klaim barang rusak, salah kirim, atau kurang item tidak dihitung.
 * Yang memblokir pembelian baru: nomor dan alamat; nama dan IP hanya bukti. Hanya checkout baru yang ditolak; retur dan pesanan
 * yang berjalan tidak disentuh. Saklar `antifraud.return_block_enabled` (mati bawaan) hanya mengatur pembuatan blokir otomatis;
 * blokir yang sudah aktif, termasuk manual, selalu berlaku.
 */
class CustomerBlockService
{
    public function __construct(private StaffNotifier $notifier) {}

    public static function enabled(): bool
    {
        return Setting::where('key', 'antifraud.return_block_enabled')->value('value') === '1';
    }

    public static function streak(): int
    {
        return max(2, OrderReviewService::setting('antifraud.return_block_streak', 3));
    }

    /** Dipanggil di dalam transaksi persetujuan retur (baris refund sudah dikunci). */
    public function evaluate(Refund $refund): ?CustomerBlock
    {
        if (! self::enabled() || $refund->reason_type !== Refund::RETURN_REASON) {
            return null;
        }

        $phone = (string) $refund->order->customer_phone;
        $n = self::streak();

        $hash = CustomerBlock::phoneHash($phone);

        // Setelah blokir dicabut, hitungan mulai dari awal: pesanan yang sudah menjadi dasar blokir lama tidak dihitung lagi.
        $floor = CustomerBlock::whereNotNull('released_at')->where('phone_hash', $hash)->pluck('order_ids')->flatten()->max() ?? 0;

        // Pesanan diterima terbaru lebih dulu; pesanan yang belum diterima (selesai) tidak ikut dihitung.
        $orders = Order::where('customer_phone', $phone)->where('status', Order::COMPLETED)->where('id', '>', $floor)->orderByDesc('id')->limit($n)->get();

        if ($orders->count() < $n) {
            return null;
        }

        $returns = Refund::whereIn('order_id', $orders->pluck('id'))->where('reason_type', Refund::RETURN_REASON)
            ->whereIn('status', [Refund::APPROVED, Refund::REFUNDED])->get(['id', 'order_id']);

        if ($returns->pluck('order_id')->unique()->count() < $n) {
            return null;
        }

        $latest = $orders->first();

        if ($existing = CustomerBlock::active()->where('phone_hash', $hash)->first()) {
            return $existing;
        }

        try {
            $block = CustomerBlock::create([
                'phone_hash' => $hash,
                'address_hash' => CustomerBlock::addressHash((array) $latest->shipping_address),
                'phone_masked' => CustomerBlock::maskPhone($phone),
                'customer_name' => $latest->customer_name,
                'address_summary' => trim(($latest->shipping_address['city'] ?? '').' '.($latest->shipping_address['postal_code'] ?? '')) ?: null,
                'ip_address' => $latest->ip_address,
                'order_ids' => $orders->pluck('id')->all(),
                'refund_ids' => $returns->pluck('id')->all(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Persetujuan lain atas nomor yang sama menang lebih dulu.
            return CustomerBlock::active()->where('phone_hash', $hash)->first();
        }

        AuditLog::record('customer.auto_blocked', $block, ['phone' => $block->phone_masked, 'orders' => $block->order_ids, 'refunds' => $block->refund_ids]);
        $this->notifier->toRoles(['store-admin', 'owner'], new StaffEvent('customer_blocked', 'customer_block', $block->phone_masked, "Pelanggan {$block->phone_masked} diblokir otomatis", "Retur produk utuh {$n} pesanan berturut-turut (D-122)"));

        return $block;
    }

    /**
     * Blokir manual oleh staf (Admin Toko, Super Admin). Catatan wajib. Nomor sudah dinormalkan ke +62; alamat opsional.
     *
     * @param  array{phone: string, customer_name?: ?string, address?: ?array<string, mixed>, note: string}  $data
     */
    public function createManual(array $data, User $actor): CustomerBlock
    {
        $hash = CustomerBlock::phoneHash($data['phone']);

        if (CustomerBlock::active()->where('phone_hash', $hash)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Nomor ini sudah diblokir.']);
        }

        try {
            $block = CustomerBlock::create([
                'phone_hash' => $hash,
                'address_hash' => CustomerBlock::addressHash($data['address'] ?? []),
                'phone_masked' => CustomerBlock::maskPhone($data['phone']),
                'customer_name' => ($data['customer_name'] ?? null) ?: '-',
                'address_summary' => trim(($data['address']['city'] ?? '').' '.($data['address']['postal_code'] ?? '')) ?: null,
                'source' => 'manual',
                'note' => $data['note'],
                'order_ids' => [],
                'refund_ids' => [],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['phone' => 'Nomor ini sudah diblokir.']);
        }

        AuditLog::record('customer.block_created', $block, ['phone' => $block->phone_masked, 'note' => $block->note], $actor->id);

        return $block;
    }

    public function updateNote(CustomerBlock $block, string $note, User $actor): CustomerBlock
    {
        if (! $block->isActive()) {
            throw ValidationException::withMessages(['block' => 'Blokir yang sudah dicabut tidak bisa diubah.']);
        }

        AuditLog::saveWithAudit($block, ['note' => $note], 'customer.block_note_updated', $actor);

        return $block;
    }

    /** Pembelian baru dari nomor atau alamat ini ditolak. */
    public static function blocks(string $phone, array $address): bool
    {
        // Saklar `return_block_enabled` hanya mengatur pembuatan blokir otomatis. Blokir yang sudah aktif (otomatis atau manual) selalu
        // berlaku; jalan keluarnya hanya mencabut.
        $addressHash = CustomerBlock::addressHash($address);

        return CustomerBlock::active()->where(fn ($query) => $query
            ->where('phone_hash', CustomerBlock::phoneHash($phone))
            ->when($addressHash, fn ($inner) => $inner->orWhere('address_hash', $addressHash)))->exists();
    }

    public function release(CustomerBlock $block, string $note, User $actor): CustomerBlock
    {
        return DB::transaction(function () use ($block, $note, $actor) {
            $locked = CustomerBlock::whereKey($block->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive()) {
                throw ValidationException::withMessages(['block' => 'Blokir ini sudah dicabut.']);
            }

            $locked->forceFill(['released_at' => now(), 'released_by_user_id' => $actor->id, 'release_note' => $note, 'active_flag' => null])->save();
            AuditLog::record('customer.block_released', $locked, ['phone' => $locked->phone_masked, 'note' => $note], $actor->id);

            return $locked;
        });
    }
}
