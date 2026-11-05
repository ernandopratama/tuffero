<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    /** @param array{name: string, email: string, password: string, role: string} $data */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $user = User::create($data);
            $user->assignRole($data['role']);

            AuditLog::record('user.created', $user, [
                'name' => $data['name'],
                'email' => $data['email'],
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

            foreach (['name', 'email', 'is_active', 'mfa_required'] as $field) {
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
