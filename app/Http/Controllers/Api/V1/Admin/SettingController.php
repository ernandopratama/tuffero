<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\RegistrationService;
use App\Services\ReturnBlockRuleService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SettingController extends Controller
{
    use ApiResponse;

    /** Nilai yang memang tampil ke pengunjung (is_public); tanpa login. */
    public function publicSettings(): JsonResponse
    {
        $settings = Setting::where('is_public', true)->whereIn('key', array_keys(Setting::RULES))->pluck('value', 'key');

        return $this->success('Settings retrieved.', ['settings' => $settings]);
    }

    public function index(Request $request): JsonResponse
    {
        $query = Setting::whereIn('key', array_keys(Setting::RULES));

        // Admin Toko hanya mengelola kontak (settings.manage_contact); Super Admin semua.
        if (! $request->user()->can('settings.manage')) {
            $query->whereIn('group', Setting::STORE_ADMIN_GROUPS);
        }

        $settings = $query->orderBy('id')->get(['key', 'value', 'type', 'group', 'is_public', 'updated_at']);

        return $this->success('Settings retrieved.', ['settings' => $settings]);
    }

    public function update(UpdateSettingRequest $request, Setting $setting): JsonResponse
    {
        $old = $setting->value;
        $new = $request->validated('value');
        $new = $new === null ? null : (string) $new;

        // D-133: blokir otomatis retur hanya menyala lewat pengajuan yang disetujui Pemilik, bukan lewat Pengaturan.
        if ($setting->key === ReturnBlockRuleService::KEY && $new === '1' && $old !== '1') {
            throw ValidationException::withMessages(['value' => 'Blokir otomatis dinyalakan lewat halaman Pelanggan Diblokir dan butuh persetujuan Pemilik.']);
        }

        // D-146: daftar wajib WhatsApp hanya boleh menyala bila kode WhatsApp dan Turnstile sudah dikonfigurasi di server; kalau tidak, pendaftaran pasti gagal.
        if ($setting->key === RegistrationService::SETTING_KEY && $new === '1' && $old !== '1' && ! RegistrationService::whatsappReady()) {
            throw ValidationException::withMessages(['value' => 'Kunci WhatsApp (WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID, WHATSAPP_OTP_TEMPLATE) dan Turnstile (TURNSTILE_SECRET_KEY) belum diisi di server, jadi pendaftaran akan gagal. Isi dulu di .env, lalu coba lagi.']);
        }

        if ($old !== $new) {
            DB::transaction(function () use ($setting, $old, $new, $request) {
                $setting->update(['value' => $new, 'updated_by_user_id' => $request->user()->id]);

                AuditLog::record('setting.updated', $setting, ['key' => $setting->key, 'old' => $old, 'new' => $new], $request->user()->id);
            });
        }

        return $this->success('Setting updated.', ['key' => $setting->key, 'value' => $setting->value]);
    }
}
