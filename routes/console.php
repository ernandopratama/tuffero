<?php

use App\Models\AuditLog;
use App\Models\User;
use App\Services\CancellationService;
use App\Services\MfaService;
use App\Services\OrderExpiryService;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Inspiring;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

// `composer dev` di Windows: `php artisan serve` hanya satu proses, sehingga preconnect browser menahan semua request.
// Bila skrip Nginx lokal ada (%USERPROFILE%\tuffero-nginx\serve.ps1), proses "server" memakai Nginx + PHP-CGI.
// Tanpa skrip itu (komputer lain, Linux, CI), `php artisan serve` bawaan tetap dipakai.
$nginxServe = app()->environment('local') && getenv('USERPROFILE') ? getenv('USERPROFILE').'\tuffero-nginx\serve.ps1' : null;
if ($nginxServe && is_file($nginxServe)) {
    DevCommands::register('powershell -NoProfile -ExecutionPolicy Bypass -File "'.$nginxServe.'"', 'server');
}

// Quick tunnel Cloudflare untuk menerima notifikasi Midtrans sandbox di laptop. Hanya lokal; alamat berganti tiap dijalankan.
$cloudflared = app()->environment('local')
    ? (new ExecutableFinder)->find('cloudflared', null, ['C:\Program Files (x86)\cloudflared', 'C:\Program Files\cloudflared'])
    : null;

Artisan::command('dev:tunnel {--port=8000}', function () use ($cloudflared) {
    if (! $cloudflared) {
        $this->error('cloudflared tidak ditemukan atau APP_ENV bukan local.');

        return 1;
    }

    $shown = false;
    $process = new Process([$cloudflared, 'tunnel', '--no-autoupdate', '--url', 'http://127.0.0.1:'.$this->option('port')]);
    $process->setTimeout(null);

    $process->run(function (string $type, string $output) use (&$shown) {
        if (! $shown && preg_match('~https://[a-z0-9-]+\.trycloudflare\.com~', $output, $match)) {
            $shown = true;
            $this->newLine();
            $this->info('Tunnel aktif. Isi Payment notification URL di dashboard Midtrans Sandbox dengan:');
            $this->line('  <options=bold>'.$match[0].'/api/v1/webhooks/midtrans</>');
            $this->newLine();
            $this->info('Webhook Biteship (dashboard Biteship, isi rahasia sama dengan BITESHIP_WEBHOOK_SECRET di header X-Biteship-Secret):');
            $this->line('  <options=bold>'.$match[0].'/api/v1/webhooks/biteship</>');
            $this->newLine();
        }

        // Selain alamat, hanya galat yang ditampilkan supaya tab tidak penuh log.
        foreach (preg_split('/\R/', $output) as $line) {
            if (str_contains($line, ' ERR ')) {
                $this->line($line);
            }
        }
    });

    return $process->getExitCode();
})->purpose('Run a Cloudflare quick tunnel and print the Midtrans notification URL (local only)');

if ($cloudflared) {
    DevCommands::artisan('dev:tunnel', 'tunnel');
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('orders:expire', function (OrderExpiryService $expiry) {
    $this->info($expiry->expireDue().' pesanan ditutup.');
})->purpose('Close unpaid orders past their deadline and release their stock');

Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();

Artisan::command('cancellations:remind', function (CancellationService $cancellations) {
    $this->info($cancellations->remindOverdue().' pengingat pembatalan dikirim.');
})->purpose('Notify staff about cancellation requests past their process deadline (once per request)');

Schedule::command('cancellations:remind')->hourly()->withoutOverlapping();

Artisan::command('notifications:prune', function () {
    // Sudah dibaca disimpan 30 hari; belum dibaca 90 hari supaya tidak terlewat.
    $read = DatabaseNotification::whereNotNull('read_at')->where('created_at', '<', now()->subDays(30))->delete();
    $unread = DatabaseNotification::whereNull('read_at')->where('created_at', '<', now()->subDays(90))->delete();
    $this->info('Notifikasi dihapus: '.($read + $unread).'.');
})->purpose('Delete old notifications (read after 30 days, unread after 90 days)');

Schedule::command('notifications:prune')->daily();

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
