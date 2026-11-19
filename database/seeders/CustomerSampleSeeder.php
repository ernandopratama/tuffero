<?php

namespace Database\Seeders;

use App\Models\User;
use Database\Seeders\Concerns\SampleData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Akun pelanggan contoh untuk dev (DemoAccountSeeder hanya jalan di lokal). Tanpa role. Empat keadaan: aktif dan terverifikasi,
 * nonaktif, belum verifikasi apa pun, dan pemilik nomor yang diblokir (lihat CustomerBlockSampleSeeder).
 * Password acak dan hanya ditampilkan sekali di konsol; akun yang sudah ada tidak diubah. Ditolak di production.
 * Jalankan dengan php artisan db:seed --class=CustomerSampleSeeder.
 */
class CustomerSampleSeeder extends Seeder
{
    use SampleData;

    public const BLOCKED_PHONE = '+628119990099';

    /** @var list<array<string, mixed>> */
    private const CUSTOMERS = [
        ['name' => 'Uji Pelanggan 3', 'email' => 'pelanggan3@tuffero.test', 'phone' => '+628119990003', 'verified' => true, 'is_active' => true],
        ['name' => 'Uji Pelanggan Nonaktif', 'email' => 'pelanggan-nonaktif@tuffero.test', 'phone' => '+628119990004', 'verified' => true, 'is_active' => false],
        ['name' => 'Uji Pelanggan Baru', 'email' => 'pelanggan-baru@tuffero.test', 'phone' => null, 'verified' => false, 'is_active' => true],
        ['name' => 'Uji Pelanggan Diblokir', 'email' => 'pelanggan-blokir@tuffero.test', 'phone' => self::BLOCKED_PHONE, 'verified' => true, 'is_active' => true],
    ];

    public function run(): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $created = [];
        foreach (self::CUSTOMERS as $row) {
            $user = User::firstOrNew(['email' => $row['email']]);

            if ($user->exists) {
                continue;
            }

            $password = Str::password(14, symbols: false);
            $user->forceFill([
                'name' => $row['name'],
                'password' => $password,
                'is_active' => $row['is_active'],
                'mfa_required' => false,
                'email_verified_at' => $row['verified'] ? now() : null,
                'phone' => $row['phone'],
                'phone_verified_at' => $row['verified'] && $row['phone'] ? now() : null,
            ])->save();
            $created[] = "{$row['email']}  password: {$password}";
        }

        $this->command?->info(count($created).' pelanggan contoh dibuat'.($created ? ' (password hanya tampil sekali):' : '.'));
        foreach ($created as $line) {
            $this->command?->line("  {$line}");
        }
    }
}
