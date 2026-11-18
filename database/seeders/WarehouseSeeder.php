<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use App\Support\StoreSite;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Gudang Malang (D-008), rumah produksi Tuffero. Alamat, kode pos, telepon, dan koordinat (titik Maps toko, StoreSite::MAP_LAT/MAP_LNG) hanya mengisi kolom yang masih kosong,
     * jadi perubahan dari dashboard (menu Operasional > Gudang) tidak tertimpa.
     */
    public function run(): void
    {
        $warehouse = Warehouse::firstOrCreate(['code' => 'MLG'], ['name' => 'Gudang Malang', 'city' => 'Malang', 'is_active' => true]);

        foreach ([
            'address' => 'Jl. Villa Bukit Sengkaling Blk. AG No.8, Dusun Klandungan, Landungsari, Kec. Dau, Kabupaten Malang, Jawa Timur',
            'postal_code' => '65151',
            'phone' => '081252507880',
            'latitude' => StoreSite::MAP_LAT,
            'longitude' => StoreSite::MAP_LNG,
        ] as $column => $value) {
            if (blank($warehouse->{$column})) {
                $warehouse->{$column} = $value;
            }
        }
        $warehouse->save();
    }
}
