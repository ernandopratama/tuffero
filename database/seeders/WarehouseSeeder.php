<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    /**
     * Gudang Malang (D-008). Alamat dan kode pos diisi setelah ditetapkan Ko Rifan.
     */
    public function run(): void
    {
        Warehouse::firstOrCreate(['code' => 'MLG'], ['name' => 'Gudang Malang', 'city' => 'Malang', 'is_active' => true]);
    }
}
