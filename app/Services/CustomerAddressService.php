<?php

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Alamat tersimpan pelanggan. Baris akun dikunci saat menulis supaya batas jumlah dan satu alamat utama tetap benar pada request bersamaan.
 */
class CustomerAddressService
{
    public const MAX_ADDRESSES = 10;

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data, bool $makeDefault): CustomerAddress
    {
        return DB::transaction(function () use ($user, $data, $makeDefault) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $count = $user->addresses()->count();

            if ($count >= self::MAX_ADDRESSES) {
                throw ValidationException::withMessages(['address' => 'limit_reached']);
            }

            $address = $user->addresses()->create($data);

            return $count === 0 || $makeDefault ? $this->setDefault($user, $address) : $address;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, CustomerAddress $address, array $data, bool $makeDefault): CustomerAddress
    {
        return DB::transaction(function () use ($user, $address, $data, $makeDefault) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $address->update($data);

            return $makeDefault ? $this->setDefault($user, $address) : $address;
        });
    }

    public function makeDefault(User $user, CustomerAddress $address): CustomerAddress
    {
        return DB::transaction(function () use ($user, $address) {
            User::whereKey($user->id)->lockForUpdate()->first();

            return $this->setDefault($user, $address);
        });
    }

    /** Alamat utama yang dihapus digantikan alamat tertua yang tersisa. */
    public function delete(User $user, CustomerAddress $address): void
    {
        DB::transaction(function () use ($user, $address) {
            User::whereKey($user->id)->lockForUpdate()->first();
            $address->delete();

            if ($address->is_default && ($next = $user->addresses()->oldest('id')->first())) {
                $this->setDefault($user, $next);
            }
        });
    }

    private function setDefault(User $user, CustomerAddress $address): CustomerAddress
    {
        $user->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
        $address->forceFill(['is_default' => true])->save();

        return $address;
    }
}
