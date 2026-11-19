<?php

namespace Database\Seeders;

use App\Models\CustomerBlock;
use Database\Seeders\Concerns\SampleData;
use Illuminate\Database\Seeder;

/**
 * Pelanggan diblokir contoh (D-122): satu blokir otomatis aktif (nomor CustomerSampleSeeder::BLOCKED_PHONE), satu blokir manual
 * aktif dengan catatan, dan satu blokir yang sudah dicabut. Nomor 0811999xxxx hanyalah nomor uji. Aman diulang: blokir dikenali
 * dari nomor dan sumbernya. Ditolak di production. Jalankan dengan php artisan db:seed --class=CustomerBlockSampleSeeder.
 */
class CustomerBlockSampleSeeder extends Seeder
{
    use SampleData;

    public function run(): void
    {
        if ($this->refuseInProduction()) {
            return;
        }

        $rows = [
            ['phone' => CustomerSampleSeeder::BLOCKED_PHONE, 'name' => 'Uji Pelanggan Diblokir', 'city' => 'Malang 65111', 'source' => 'auto', 'note' => null, 'active' => true],
            ['phone' => '+628119990098', 'name' => 'Uji Pembeli Manual', 'city' => 'Surabaya 60111', 'source' => 'manual', 'note' => 'Contoh blokir manual: pembeli berulang menolak paket tanpa alasan.', 'active' => true],
            ['phone' => '+628119990097', 'name' => 'Uji Pembeli Dicabut', 'city' => 'Jakarta 10110', 'source' => 'auto', 'note' => null, 'active' => false],
        ];

        $made = 0;
        foreach ($rows as $row) {
            $hash = CustomerBlock::phoneHash($row['phone']);

            if (CustomerBlock::where('phone_hash', $hash)->where('source', $row['source'])->exists()) {
                continue;
            }

            CustomerBlock::create([
                'phone_hash' => $hash,
                'phone_masked' => CustomerBlock::maskPhone($row['phone']),
                'customer_name' => $row['name'],
                'address_summary' => $row['city'],
                'source' => $row['source'],
                'note' => $row['note'],
                'order_ids' => [],
                'refund_ids' => [],
                'active_flag' => $row['active'] ? true : null,
                'released_at' => $row['active'] ? null : now()->subDays(2),
                'release_note' => $row['active'] ? null : 'Contoh: dicabut setelah pelanggan menghubungi CS.',
            ]);
            $made++;
        }

        $this->command?->info("{$made} blokir pelanggan contoh dibuat.");
    }
}
