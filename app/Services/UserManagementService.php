<?php

namespace App\Services;

use App\Models\AdminComment;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    /** @param array{name: string, email: string, password: string, role: string, username?: string|null} $data */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create($data);
            $user->forceFill(['username' => $data['username'] ?? null])->save();
            $user->assignRole($data['role']);

            AuditLog::record('user.created', $user, [
                'name' => $data['name'],
                'email' => $data['email'],
                'username' => $data['username'] ?? null,
                'role' => $data['role'],
            ], $actor->id);

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data, User $actor): User
    {
        // Super Admin tidak bisa melemahkan akunnya sendiri. Karena hanya Super Admin yang sampai ke sini, minimal satu Super Admin aktif selalu tersisa.
        if ($user->is($actor)) {
            $this->rejectSelfChange($user, $data);
        }

        return DB::transaction(function () use ($user, $data, $actor) {
            $changes = [];

            foreach (['name', 'email', 'username', 'is_active', 'mfa_required'] as $field) {
                if (array_key_exists($field, $data) && $user->{$field} !== $data[$field]) {
                    $changes[$field] = ['old' => $user->{$field}, 'new' => $data[$field]];
                    $user->forceFill([$field => $data[$field]]);
                }
            }

            if (isset($data['role']) && $user->getRoleNames()->first() !== $data['role']) {
                $changes['role'] = ['old' => $user->getRoleNames()->first(), 'new' => $data['role']];
                $user->syncRoles([$data['role']]);
            }

            if (! empty($data['password'])) {
                $changes['password_changed'] = true;
                $user->forceFill(['password' => $data['password']]);
            }

            $user->save();

            if ($changes) {
                AuditLog::record('user.updated', $user, $changes, $actor->id);
            }

            if (isset($changes['password_changed']) || ($data['is_active'] ?? true) === false) {
                $this->dropSessions($user);
            }

            return $user;
        });
    }

    /**
     * Menghapus akun staf secara permanen. Jejak di audit log, riwayat pesanan, dan stok tetap ada (kolom pelakunya menjadi kosong),
     * jadi data identitas akun dicatat di audit log sebelum dihapus. Akun yang pernah menulis catatan internal tidak bisa dihapus
     * (kunci asing `restrict`); menonaktifkannya menjaga riwayat tetap utuh.
     */
    public function delete(User $user, User $actor): void
    {
        $role = $user->getRoleNames()->first();

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'Anda tidak bisa menghapus akun sendiri.']);
        }

        if ($role === null) {
            throw ValidationException::withMessages(['user' => 'Hanya akun staf yang bisa dihapus dari halaman ini.']);
        }

        if (AdminComment::where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages(['user' => 'Akun ini pernah menulis catatan internal, jadi tidak bisa dihapus. Nonaktifkan saja akunnya.']);
        }

        DB::transaction(function () use ($user, $actor, $role) {
            AuditLog::record('user.deleted', $user, ['name' => $user->name, 'email' => $user->email, 'role' => $role], $actor->id);

            $this->dropSessions($user);
            $user->tokens()->delete();
            $user->notifications()->delete();
            $user->delete();
        });
    }

    /**
     * Menghapus akun pelanggan (tanpa role) secara permanen. Alamat, klaim voucher, dan perangkat ikut terhapus.
     * Pelanggan yang masih punya pesanan tidak boleh dihapus (riwayat transaksi tetap tertaut); nonaktifkan saja akunnya.
     */
    public function deleteCustomer(User $customer, User $actor): void
    {
        DB::transaction(function () use ($customer, $actor) {
            // Dikunci dulu agar pesanan baru yang masuk bersamaan tidak lolos dari pemeriksaan.
            User::whereKey($customer->id)->lockForUpdate()->first();

            if ($customer->orders()->exists()) {
                throw ValidationException::withMessages(['user' => 'Pelanggan ini masih punya pesanan, jadi akunnya tidak bisa dihapus. Nonaktifkan saja akunnya.']);
            }

            AuditLog::record('customer.deleted', $customer, [
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ], $actor->id);

            $this->dropSessions($customer);
            $customer->tokens()->delete();
            $customer->notifications()->delete();
            $customer->delete();
        });
    }

    /** @param array<string, mixed> $data */
    private function rejectSelfChange(User $user, array $data): void
    {
        $errors = [];

        if (isset($data['role']) && $data['role'] !== $user->getRoleNames()->first()) {
            $errors['role'] = 'Anda tidak bisa mengubah role akun sendiri.';
        }

        if (($data['is_active'] ?? true) === false) {
            $errors['is_active'] = 'Anda tidak bisa menonaktifkan akun sendiri.';
        }

        if (($data['mfa_required'] ?? true) === false) {
            $errors['mfa_required'] = 'Anda tidak bisa mematikan MFA akun sendiri.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function dropSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
    }
}
