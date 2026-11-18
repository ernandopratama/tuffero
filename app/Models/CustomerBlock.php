<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Blokir pembelian pelanggan (D-122). Nomor WhatsApp dan alamat dicocokkan lewat hash berkunci (HMAC dengan kunci aplikasi, bukan SHA-256
 * polos: nomor HP mudah ditebak dari hash polos). Nama dan IP hanya bukti. Blokir tidak dihapus, hanya dicabut.
 *
 * @property list<int> $order_ids
 * @property list<int> $refund_ids
 * @property CarbonInterface|null $released_at
 */
#[Fillable(['phone_hash', 'address_hash', 'phone_masked', 'customer_name', 'address_summary', 'ip_address', 'source', 'note', 'order_ids', 'refund_ids', 'active_flag', 'released_at', 'released_by_user_id', 'release_note'])]
class CustomerBlock extends Model
{
    use HasPublicId;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'order_ids' => 'array',
            'refund_ids' => 'array',
            'active_flag' => 'boolean',
            'released_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->released_at === null;
    }

    /** @param  Builder<CustomerBlock>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('released_at');
    }

    /** @return BelongsTo<User, $this> */
    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by_user_id');
    }

    public static function phoneHash(string $phone): string
    {
        return hash_hmac('sha256', 'phone|'.preg_replace('/\D/', '', $phone), (string) config('app.key'));
    }

    /** @param  array<string, mixed>  $address */
    public static function addressHash(array $address): ?string
    {
        // Jalan dan kode pos saja: kecamatan, kota, dan provinsi sering tidak diketik sama oleh staf dan pembeli, jadi tidak ikut dicocokkan.
        $parts = array_map(fn ($key) => preg_replace('/[^a-z0-9]/', '', mb_strtolower((string) ($address[$key] ?? ''))), ['street', 'postal_code']);

        // Tanpa jalan dan kode pos, alamat terlalu umum untuk dicocokkan (mencegah salah blokir).
        return $parts[0] === '' || $parts[1] === '' ? null : hash_hmac('sha256', 'address|'.implode('|', $parts), (string) config('app.key'));
    }

    /** 0812****7880: cukup dikenali staf, tidak membuka nomor penuh. */
    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/^62/', '0', preg_replace('/\D/', '', $phone));

        return strlen($digits) > 6 ? substr($digits, 0, 4).str_repeat('*', strlen($digits) - 8).substr($digits, -4) : '****';
    }
}
