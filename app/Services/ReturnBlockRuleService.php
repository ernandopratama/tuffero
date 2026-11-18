<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ReturnBlockRuleRequest;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\StaffEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyalakan blokir otomatis retur (D-133): Super Admin atau Admin Toko mengajukan dengan catatan wajib, hanya Pemilik yang menyetujui.
 * Saklar `antifraud.return_block_enabled` tidak bisa dinyalakan lewat Pengaturan (lihat SettingController), hanya lewat persetujuan di sini.
 * Mematikan boleh langsung dengan catatan, karena arahnya memperkecil dampak ke pelanggan. Semua langkah tercatat di audit log.
 */
class ReturnBlockRuleService
{
    public const KEY = 'antifraud.return_block_enabled';

    public function __construct(private StaffNotifier $notifier) {}

    /** @return array<string, mixed> */
    public function state(): array
    {
        $pending = ReturnBlockRuleRequest::with('requester:id,name')->where('status', ReturnBlockRuleRequest::PENDING)->first();
        $last = ReturnBlockRuleRequest::with(['requester:id,name', 'decider:id,name'])->where('status', '!=', ReturnBlockRuleRequest::PENDING)->latest('id')->first();

        return [
            'enabled' => CustomerBlockService::enabled(),
            'pending' => $pending ? $this->present($pending) : null,
            'last_decision' => $last ? $this->present($last) : null,
        ];
    }

    public function requestEnable(string $note, User $actor): ReturnBlockRuleRequest
    {
        if (CustomerBlockService::enabled()) {
            throw ValidationException::withMessages(['rule' => 'Blokir otomatis sudah menyala.']);
        }

        try {
            $request = DB::transaction(function () use ($note, $actor) {
                $request = ReturnBlockRuleRequest::create(['requested_by_user_id' => $actor->id, 'note' => $note]);
                AuditLog::record('customer_block_rule.requested', $request, ['note' => $note], $actor->id);

                return $request;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['rule' => 'Sudah ada pengajuan yang menunggu keputusan Pemilik.']);
        }

        $this->notifier->toRoles(['owner'], new StaffEvent('block_rule_requested', 'customer_block', 'Aturan blokir', 'Pengajuan menyalakan blokir otomatis retur', "Oleh {$actor->name}: {$note}"));

        return $request;
    }

    public function decide(ReturnBlockRuleRequest $ruleRequest, bool $approve, ?string $note, User $actor): ReturnBlockRuleRequest
    {
        // Super Admin memegang semua izin, jadi peran Pemilik diperiksa di sini, bukan hanya izinnya.
        if (! $actor->hasRole('owner')) {
            throw new AuthorizationException('Hanya Pemilik yang memutuskan pengajuan ini.');
        }

        $decided = DB::transaction(function () use ($ruleRequest, $approve, $note, $actor) {
            $locked = ReturnBlockRuleRequest::whereKey($ruleRequest->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== ReturnBlockRuleRequest::PENDING) {
                throw ValidationException::withMessages(['rule' => 'Pengajuan ini sudah diputuskan.']);
            }
            if ($locked->requested_by_user_id === $actor->id) {
                throw ValidationException::withMessages(['rule' => 'Pengajuan sendiri tidak bisa disetujui sendiri.']);
            }

            $locked->forceFill([
                'status' => $approve ? ReturnBlockRuleRequest::APPROVED : ReturnBlockRuleRequest::REJECTED,
                'pending_flag' => null,
                'decided_by_user_id' => $actor->id,
                'decision_note' => $note,
                'decided_at' => now(),
            ])->save();

            if ($approve) {
                $this->setEnabled(true, $actor);
            }
            AuditLog::record($approve ? 'customer_block_rule.approved' : 'customer_block_rule.rejected', $locked, ['request_note' => $locked->note, 'decision_note' => $note], $actor->id);

            return $locked;
        });

        $this->notifier->toRoles(['store-admin', 'super-admin'], new StaffEvent('block_rule_decided', 'customer_block', 'Aturan blokir', $approve ? 'Blokir otomatis retur disetujui Pemilik dan menyala' : 'Pengajuan blokir otomatis retur ditolak Pemilik', "Oleh {$actor->name}".($note ? ": {$note}" : '')), $actor);

        return $decided;
    }

    public function disable(string $note, User $actor): void
    {
        if (! CustomerBlockService::enabled()) {
            throw ValidationException::withMessages(['rule' => 'Blokir otomatis sudah mati.']);
        }

        DB::transaction(function () use ($note, $actor) {
            $this->setEnabled(false, $actor);
            AuditLog::record('customer_block_rule.disabled', Setting::where('key', self::KEY)->firstOrFail(), ['note' => $note], $actor->id);
        });

        $this->notifier->toRoles(['owner'], new StaffEvent('block_rule_disabled', 'customer_block', 'Aturan blokir', 'Blokir otomatis retur dimatikan', "Oleh {$actor->name}: {$note}"), $actor);
    }

    private function setEnabled(bool $enabled, User $actor): void
    {
        $setting = Setting::where('key', self::KEY)->lockForUpdate()->firstOrFail();
        $old = $setting->value;
        $setting->update(['value' => $enabled ? '1' : '0', 'updated_by_user_id' => $actor->id]);
        AuditLog::record('setting.updated', $setting, ['key' => self::KEY, 'old' => $old, 'new' => $setting->value], $actor->id);
    }

    /** @return array<string, mixed> */
    private function present(ReturnBlockRuleRequest $request): array
    {
        return [
            'id' => $request->public_id,
            'status' => $request->status,
            'note' => $request->note,
            'requested_by' => $request->requester?->name,
            'requested_at' => $request->created_at,
            'decided_by' => $request->decider?->name,
            'decision_note' => $request->decision_note,
            'decided_at' => $request->decided_at,
        ];
    }
}
