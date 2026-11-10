<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ChangePasswordRequest;
use App\Http\Requests\Admin\UpdateProfileRequest;
use App\Models\AuditLog;
use App\Services\ImageService;
use App\Services\MfaService;
use App\Services\ProfileService;
use App\Services\SessionService;
use App\Services\StaffNotifier;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    use ApiResponse;

    public function __construct(private ProfileService $profiles, private SessionService $sessions, private MfaService $mfa, private StaffNotifier $notifier) {}

    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        return $this->success('Profile retrieved.', $this->profiles->show($request->user()));
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $this->profiles->update($request->user(), $request->safe()->only(['name', 'email', 'phone']));

        return $this->success('Profile updated.', [...$this->profiles->show($user), 'user' => $user->accessProfile()]);
    }

    public function avatar(Request $request): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        $validated = $request->validate([
            'image' => ['required', 'file', ImageService::MIMES, 'max:2048', 'dimensions:max_width=5000,max_height=5000'],
        ], [
            'image.required' => 'Pilih foto dulu.',
            'image.mimes' => ImageService::MIME_MESSAGE,
            'image.max' => 'Ukuran foto maksimal 2 MB.',
            'image.dimensions' => 'Dimensi foto maksimal 5000 × 5000 piksel.',
        ]);

        $user = $this->profiles->setAvatar($request->user(), $validated['image']);

        return $this->success('Avatar saved.', [...$this->profiles->show($user), 'user' => $user->accessProfile()]);
    }

    public function removeAvatar(Request $request): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        $user = $this->profiles->removeAvatar($request->user());

        return $this->success('Avatar removed.', [...$this->profiles->show($user), 'user' => $user->accessProfile()]);
    }

    public function sessions(Request $request): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        return $this->success('Sessions retrieved.', [
            'supported' => $this->sessions->supported(),
            'items' => $this->sessions->list($request->user(), $request->session()->getId()),
        ]);
    }

    public function revokeSession(Request $request, string $token): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);
        abort_unless($this->sessions->revoke($request->user(), $token, $request->session()->getId()), 404);

        return $this->success('Session revoked.');
    }

    public function revokeOtherSessions(Request $request): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        return $this->success('Sessions revoked.', ['revoked' => $this->sessions->revokeOthers($request->user(), $request->session()->getId())]);
    }

    /**
     * Langkah 1 mengganti authenticator sendiri: buktikan password dan faktor yang sekarang (kode atau kode pemulihan).
     * Secret baru hanya disimpan di sesi server (terenkripsi) sampai dikonfirmasi, jadi authenticator lama tetap berlaku bila dibatalkan.
     */
    public function mfaStart(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->getRoleNames()->isNotEmpty() && $user->mfa_totp_confirmed_at, 403);

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'code' => ['nullable', 'string', 'size:6'],
            'recovery_code' => ['nullable', 'string', 'max:32'],
        ]);

        if (! Hash::check($validated['current_password'], $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Password saat ini salah.']);
        }

        $proven = match (true) {
            filled($validated['code'] ?? null) => $this->mfa->verifyCode($user, $validated['code']),
            filled($validated['recovery_code'] ?? null) => $this->mfa->verifyRecoveryCode($user, $validated['recovery_code']),
            default => throw ValidationException::withMessages(['code' => 'Masukkan kode autentikator atau kode pemulihan.']),
        };

        if (! $proven) {
            throw ValidationException::withMessages(['code' => 'Kode salah atau sudah dipakai.']);
        }

        $secret = $this->mfa->newSecret();
        $request->session()->put('profile.mfa_reset', ['secret' => Crypt::encryptString($secret), 'expires_at' => now()->addMinutes(10)->getTimestamp()]);

        return $this->success('MFA replacement started.', $this->mfa->provisioning($secret, $user->email));
    }

    /** Langkah 2: kode dari autentikator BARU benar, maka secret diganti, kode pemulihan dibuat ulang, dan perangkat lain dikeluarkan. */
    public function mfaConfirm(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->getRoleNames()->isNotEmpty() && $user->mfa_totp_confirmed_at, 403);

        $validated = $request->validate(['code' => ['required', 'string', 'size:6']]);
        $pending = $request->session()->get('profile.mfa_reset');

        if (! is_array($pending) || now()->getTimestamp() > (int) ($pending['expires_at'] ?? 0)) {
            $request->session()->forget('profile.mfa_reset');
            throw ValidationException::withMessages(['code' => 'Waktu habis. Mulai lagi dari awal.']);
        }

        $codes = $this->mfa->replaceSecret($user, Crypt::decryptString($pending['secret']), $validated['code']);

        if ($codes === null) {
            throw ValidationException::withMessages(['code' => 'Kode dari autentikator baru salah.']);
        }

        $request->session()->forget('profile.mfa_reset');
        $this->sessions->revokeOthers($user, $request->session()->getId());

        return $this->success('MFA replaced.', [...$this->profiles->show($user->fresh()), 'recovery_codes' => $codes]);
    }

    /** Aktivitas akun ini yang tercatat di Audit Log, terbaru di atas. */
    public function activity(Request $request): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        $page = AuditLog::where('actor_user_id', $request->user()->id)->latest('id')->paginate($this->perPage($request, 15));

        return $this->success('Activity retrieved.', [
            'items' => $page->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'target' => $log->auditable_type ? class_basename($log->auditable_type) : null,
                'ip' => $log->ip_address,
                'at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        abort_unless($request->user()->getRoleNames()->isNotEmpty(), 403);

        return $this->success('Notification preferences retrieved.', ['events' => $this->notifier->preferencesFor($request->user())]);
    }

    /** Hanya jenis yang relevan untuk peran akun ini yang boleh dimatikan; kiriman lain diabaikan. */
    public function updateNotifications(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->getRoleNames()->isNotEmpty(), 403);

        $allowed = collect($this->notifier->preferencesFor($user))->pluck('key')->all();
        $validated = $request->validate(['muted' => ['present', 'array'], 'muted.*' => ['string', Rule::in($allowed)]]);

        $user->forceFill(['notification_muted' => array_values(array_unique($validated['muted']))])->save();

        return $this->success('Notification preferences saved.', ['events' => $this->notifier->preferencesFor($user->fresh())]);
    }

    public function password(ChangePasswordRequest $request): JsonResponse
    {
        $this->profiles->changePassword($request->user(), $request->validated('password'), $request->session()->getId());

        return $this->success('Password changed.');
    }
}
