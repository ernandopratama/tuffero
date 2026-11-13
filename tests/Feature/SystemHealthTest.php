<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Models\User;
use App\Services\SystemHealthService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        // Log uji terpisah supaya hasil tidak bergantung pada storage/logs/laravel.log yang asli.
        $this->logDir = sys_get_temp_dir().'/tuffero-log-'.bin2hex(random_bytes(4));
        mkdir($this->logDir);
        config(['logging.channels.single.path' => $this->logDir.'/laravel.log']);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->logDir.'/*') ?: []);
        @rmdir($this->logDir);

        parent::tearDown();
    }

    private string $logDir;

    /** @param list<string> $lines */
    private function writeLog(array $lines, string $name = 'laravel.log'): void
    {
        file_put_contents($this->logDir.'/'.$name, implode("\n", $lines)."\n");
    }

    private function logLine(string $level, ?string $at = null, string $message = 'sesuatu terjadi'): string
    {
        return '['.($at ?? now()->format('Y-m-d H:i:s')).'] testing.'.$level.': '.$message.' {"order":"ORD-1"}';
    }

    private function staff(string $role): User
    {
        $user = User::factory()->create(['mfa_totp_confirmed_at' => now()]);
        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array<string, mixed>> */
    private function checks(): array
    {
        return collect($this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/system-health')->assertOk()->json('data.checks'))->keyBy('key')->all();
    }

    private function beat(int $schedulerAgo = 30, int $queueAgo = 60): void
    {
        Cache::put(SystemHealthService::SCHEDULER_KEY, time() - $schedulerAgo);
        Cache::put(SystemHealthService::QUEUE_KEY, time() - $queueAgo);
    }

    private function pendingJob(int $availableAgo): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time() - $availableAgo, 'created_at' => time() - $availableAgo]);
    }

    public function test_basic_checks_pass_on_a_healthy_install(): void
    {
        $this->beat();

        $checks = $this->checks();

        $this->assertSame(['database', 'cache', 'storage', 'disk', 'config', 'scheduler', 'queue', 'log_errors'], array_keys($checks));
        foreach (['database', 'cache', 'storage', 'scheduler', 'queue', 'log_errors'] as $key) {
            $this->assertSame('ok', $checks[$key]['status'], $key);
        }
    }

    public function test_missing_heartbeats_are_a_problem_outside_local_and_a_warning_in_local(): void
    {
        $checks = $this->checks();
        $this->assertSame('bad', $checks['scheduler']['status']);
        $this->assertSame('bad', $checks['queue']['status']);
        $this->assertStringContainsString('schedule:run', $checks['scheduler']['detail']);
        $this->assertStringContainsString('queue:work', $checks['queue']['detail']);

        $this->app->detectEnvironment(fn () => 'local');
        $checks = $this->checks();
        $this->assertSame('warn', $checks['scheduler']['status']);
        $this->assertSame('warn', $checks['queue']['status']);
    }

    public function test_stale_heartbeats_mark_scheduler_and_queue_as_stopped(): void
    {
        $this->beat(schedulerAgo: 20 * 60, queueAgo: 30 * 60);

        $checks = $this->checks();

        $this->assertSame('bad', $checks['scheduler']['status']);
        $this->assertStringContainsString('Penjadwal berhenti', $checks['scheduler']['detail']);
        $this->assertSame('bad', $checks['queue']['status']);
        $this->assertStringContainsString('Worker antrean berhenti', $checks['queue']['detail']);
    }

    public function test_queue_reports_failed_jobs_and_a_stuck_oldest_job(): void
    {
        config(['queue.default' => 'database']);
        $this->beat();

        DB::table('failed_jobs')->insert(['uuid' => (string) str()->uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $this->pendingJob(30);

        $queue = $this->checks()['queue'];
        $this->assertSame('warn', $queue['status']);
        $this->assertStringContainsString('1 job gagal', $queue['detail']);
        $this->assertStringContainsString('Menunggu: 1, gagal: 1', $queue['detail']);

        $this->pendingJob(20 * 60);
        $queue = $this->checks()['queue'];
        $this->assertSame('bad', $queue['status']);
        $this->assertStringContainsString('Job tertua menunggu', $queue['detail']);
    }

    public function test_jobs_scheduled_in_the_future_are_not_counted_as_waiting(): void
    {
        config(['queue.default' => 'database']);
        $this->beat();
        $this->pendingJob(-3600);

        $this->assertStringContainsString('Menunggu: 0', $this->checks()['queue']['detail']);
    }

    public function test_log_errors_are_counted_without_exposing_their_content(): void
    {
        $this->writeLog([
            $this->logLine('WARNING'),
            $this->logLine('ERROR', null, 'Gagal kirim ke budi.rahasia@example.com'),
            $this->logLine('CRITICAL'),
            $this->logLine('ERROR', now()->subDays(3)->format('Y-m-d H:i:s')),
            str_replace('testing.', 'production.', $this->logLine('ERROR')),
            'Stack trace: #0 baris lanjutan yang bukan entri ERROR baru',
            $this->logLine('INFO'),
        ]);

        $response = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/system-health')->assertOk();
        $check = collect($response->json('data.checks'))->firstWhere('key', 'log_errors');

        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('2 galat', $check['detail']);
        $this->assertStringNotContainsString('budi.rahasia', $response->getContent());
        $this->assertStringNotContainsString('ORD-1', $response->getContent());
    }

    public function test_many_log_errors_are_a_problem_and_a_clean_or_missing_log_is_fine(): void
    {
        $this->assertSame('ok', $this->checks()['log_errors']['status']);

        $this->writeLog(array_fill(0, 12, $this->logLine('ERROR')));
        $this->assertSame('bad', $this->checks()['log_errors']['status']);

        $this->writeLog([$this->logLine('WARNING'), $this->logLine('INFO')]);
        $this->assertSame('ok', $this->checks()['log_errors']['status']);
    }

    public function test_log_errors_include_daily_log_files_and_only_read_the_tail_of_big_files(): void
    {
        $this->writeLog([$this->logLine('ERROR')], 'laravel-'.now()->format('Y-m-d').'.log');
        $this->assertStringContainsString('1 galat', $this->checks()['log_errors']['detail']);

        $filler = str_repeat('x', 1000);
        $this->writeLog([$this->logLine('ERROR', null, 'lama'), ...array_fill(0, 700, $this->logLine('INFO', null, $filler)), $this->logLine('ERROR', null, 'baru')]);
        $detail = $this->checks()['log_errors']['detail'];
        $this->assertStringContainsString('512 KB terakhir', $detail);
    }

    public function test_log_errors_can_be_marked_handled_and_only_new_ones_count_afterwards(): void
    {
        $this->writeLog([$this->logLine('ERROR', now()->subHour()->format('Y-m-d H:i:s')), $this->logLine('ERROR', now()->subMinutes(5)->format('Y-m-d H:i:s'))]);
        $this->assertSame('warn', $this->checks()['log_errors']['status']);

        $this->actingAs($this->staff('super-admin'))->postJson('/api/v1/admin/system-health/log-ack')->assertOk();

        $check = $this->checks()['log_errors'];
        $this->assertSame('ok', $check['status']);
        $this->assertStringContainsString('sejak ditandai ditangani', $check['detail']);

        // Galat sesudah penandaan dihitung lagi; berkas log tidak berubah.
        $before = file_get_contents($this->logDir.'/laravel.log');
        $this->writeLog([...explode("\n", trim($before)), $this->logLine('ERROR', now()->addMinute()->format('Y-m-d H:i:s'))]);
        $check = $this->checks()['log_errors'];
        $this->assertSame('warn', $check['status']);
        $this->assertStringContainsString('1 galat', $check['detail']);
        $this->assertStringContainsString('sejak ditandai ditangani', $check['detail']);
    }

    public function test_only_super_admin_can_mark_log_errors_handled(): void
    {
        $this->postJson('/api/v1/admin/system-health/log-ack')->assertUnauthorized();

        foreach (['owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->postJson('/api/v1/admin/system-health/log-ack')->assertForbidden();
        }

        $this->assertNull(Cache::get(SystemHealthService::LOG_ACK_KEY));
    }

    public function test_debug_mode_in_production_is_a_problem(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true]);

        $config = $this->checks()['config'];

        $this->assertSame('bad', $config['status']);
        $this->assertStringContainsString('APP_DEBUG', $config['detail']);
    }

    public function test_heartbeat_job_writes_the_queue_heartbeat_and_both_are_scheduled(): void
    {
        $this->assertNull(Cache::get(SystemHealthService::QUEUE_KEY));

        (new QueueHeartbeat)->handle();

        $this->assertEqualsWithDelta(time(), Cache::get(SystemHealthService::QUEUE_KEY), 5);

        $events = collect(app(Schedule::class)->events())->map(fn ($event) => $event->description ?: $event->command ?: '');
        $this->assertTrue($events->contains('system:scheduler-heartbeat'));
        $this->assertTrue($events->contains(fn (string $name) => str_contains($name, QueueHeartbeat::class)));
    }

    public function test_dashboard_flags_failing_system_checks_to_super_admin_only(): void
    {
        $attention = fn (string $role) => collect($this->actingAs($this->staff($role))->getJson('/api/v1/admin/dashboard')->assertOk()->json('data.attention'))->pluck('key');

        $this->assertContains('system_issue', $attention('super-admin'));
        $this->assertNotContains('system_issue', $attention('owner'));

        $this->beat();
        $this->assertNotContains('system_issue', $attention('super-admin'));
    }

    public function test_system_health_is_only_for_super_admin_and_leaks_no_secrets(): void
    {
        $this->getJson('/api/v1/admin/system-health')->assertUnauthorized();

        foreach (['owner', 'store-admin', 'customer-service', 'warehouse', 'marketing'] as $role) {
            $this->actingAs($this->staff($role))->getJson('/api/v1/admin/system-health')->assertForbidden();
        }

        $json = $this->actingAs($this->staff('super-admin'))->getJson('/api/v1/admin/system-health')->getContent();
        $this->assertStringNotContainsString((string) config('app.key'), $json);
        $this->assertStringNotContainsStringIgnoringCase('password', $json);
    }
}
