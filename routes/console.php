<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\MfaService;
use App\Services\OrderExpiryService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:expire', function (OrderExpiryService $expiry) {
    $this->info($expiry->expireDue().' pesanan ditutup.');
})->purpose('Close unpaid orders past their deadline and release their stock');

Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();

Artisan::command('admin:create-super-admin {email} {name}', function (string $email, string $name) {
    $password = $this->secret('Password (minimal 12 karakter, huruf besar, huruf kecil, angka)');

    $validator = Validator::make(compact('email', 'name', 'password'), [
        'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        'name' => ['required', 'string', 'max:255'],
        'password' => ['required', 'string', Password::min(12)->mixedCase()->numbers()],
    ]);

    if ($validator->fails()) {
        foreach ($validator->errors()->all() as $error) {
            $this->error($error);
        }

        return 1;
    }

    DB::transaction(function () use ($email, $name, $password) {
        $user = User::create(compact('email', 'name', 'password'));
        $user->assignRole('super-admin');

        AuditLog::create([
            'action' => 'user.created',
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'changes' => ['role' => 'super-admin', 'source' => 'artisan'],
        ]);
    });

    $this->info('Akun Super Admin dibuat.');

    return 0;
})->purpose('Create a Super Admin account (run db:seed first)');

Artisan::command('admin:reset-mfa {email}', function (string $email, MfaService $mfa) {
    $user = User::where('email', $email)->first();

    if (! $user) {
        $this->error('Akun tidak ditemukan.');

        return 1;
    }

    if (! $this->confirm("Hapus MFA {$user->email}? Login berikutnya wajib mendaftar ulang.")) {
        return 1;
    }

    $mfa->reset($user, null);
    $this->info('MFA dihapus.');

    return 0;
})->purpose('Reset MFA for a user who lost their device and recovery codes');
