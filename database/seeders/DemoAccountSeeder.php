<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Akun uji lokal untuk semua role. Tidak dipanggil DatabaseSeeder; jalankan dengan
 * php artisan db:seed --class=DemoAccountSeeder. Password sederhana ditulis ke berkas yang diabaikan Git.
 */
class DemoAccountSeeder extends Seeder
{
    private const ROLES = [
        'super-admin' => 'Super Admin',
        'owner' => 'Pemilik',
        'store-admin' => 'Admin Toko',
        'customer-service' => 'Customer Service',
        'warehouse' => 'Gudang',
        'marketing' => 'Marketing',
    ];

    public function run(?string $path = null): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoAccountSeeder hanya boleh dijalankan di environment local.');
        }

        $this->call(RolePermissionSeeder::class);

        $rows = [];
        foreach (self::ROLES as $role => $label) {
            $email = "{$role}@tuffero.test";
            $password = "{$role}-123";

            $user = User::updateOrCreate(['email' => $email], ['name' => "Uji {$label}", 'password' => $password]);
            // shortcut: MFA dimatikan supaya enam role mudah dicek di lokal; akun nyata tetap wajib MFA (D-030).
            $user->forceFill(['username' => $role, 'is_active' => true, 'mfa_required' => false, 'email_verified_at' => now()])->save();
            $user->syncRoles([$role]);

            $rows[] = "| {$label} | `{$role}` | `{$email}` | `{$role}` | `{$password}` |";
        }

        $customerRows = [];
        foreach ([1, 2] as $number) {
            $email = "pelanggan{$number}@tuffero.test";
            $password = "pelanggan{$number}-123";

            $user = User::updateOrCreate(['email' => $email], ['name' => "Uji Pelanggan {$number}", 'password' => $password]);
            // shortcut: verifikasi email dan WhatsApp ditandai langsung karena provider belum tersambung, hanya untuk lokal; hapus saat OTP sungguhan aktif (D-051).
            $user->forceFill(['username' => "pelanggan{$number}", 'is_active' => true, 'mfa_required' => false, 'email_verified_at' => now(), 'phone' => "+62811000000{$number}", 'phone_verified_at' => now()])->save();
            $user->syncRoles([]);

            $customerRows[] = "| Uji Pelanggan {$number} | `{$email}` | `pelanggan{$number}` | `{$password}` | `+62811000000{$number}` |";
        }

        $target = $path ?? base_path('docs/DEMO_ACCOUNTS.local.md');
        // docs/ tidak ikut Git, jadi folder ini belum ada di salinan baru.
        File::ensureDirectoryExists(dirname($target));
        file_put_contents($target, implode("\n", [
            '# Akun Uji Lokal',
            '',
            'Dibuat oleh `DemoAccountSeeder`. Hanya untuk MySQL lokal. Berkas ini diabaikan Git; jangan dibagikan atau di-commit.',
            'Password sengaja sederhana (slug role + -123) karena hanya untuk lokal; seeder menolak jalan di luar environment local. Akun ini tanpa MFA, jadi MFA diuji dengan `admin:create-super-admin`.',
            '',
            'Masuk lewat `/login` dengan **email atau username** (kolom pertama form), lalu password.',
            '',
            '| Role | Slug | Email | Username | Password |',
            '|---|---|---|---|---|',
            ...$rows,
            '',
            '## Pelanggan',
            '',
            'Tanpa role. Email dan WhatsApp sudah ditandai terverifikasi, tanpa OTP sungguhan (D-051). Masuk lewat `/masuk` hanya di build dev, dengan email (username hanya berlaku di `/login`). Di `/login` hasilnya halaman akses ditolak.',
            '',
            '| Nama | Email | Username | Password | WhatsApp |',
            '|---|---|---|---|---|',
            ...$customerRows,
            '',
        ]));
    }
}
