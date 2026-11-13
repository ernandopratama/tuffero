<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Mail\CustomerServiceMessage;
use App\Models\AuditLog;
use App\Models\CustomerEmail;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use App\Models\Setting;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Email CS ke pelanggan, dikirim hanya saat CS menekan Kirim (D-108). Alamat tujuan selalu diambil server dari data
 * pesanan atau refund (bukan kiriman browser), isi tidak disimpan, dan setiap kiriman masuk riwayat dan audit log.
 */
class CustomerEmailController extends Controller
{
    use ApiResponse;

    private const SIGNATURE = "\n\n--\nTim Customer Service Tuffero";

    /** Penerima yang disamarkan dan 5 kiriman terakhir untuk data ini. */
    public function index(Request $request): JsonResponse
    {
        $target = $this->target($request);
        $to = $this->recipient($target);

        return $this->success('Emails retrieved.', [
            'to' => $to ? CustomerEmail::mask($to) : null,
            'items' => $target->customerEmails()->with('sender:id,name')->latest('id')->limit(5)->get()->map(fn (CustomerEmail $email) => [
                'id' => $email->id,
                'subject' => $email->subject,
                'status' => $email->status,
                'sent_by' => $email->sender->name,
                'created_at' => $email->created_at,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Tanpa baris baru di subjek supaya header email tidak bisa disisipi.
            'subject' => ['required', 'string', 'max:150', 'regex:/^[^\r\n]+$/'],
            'text' => ['required', 'string', 'max:2000'],
        ], [
            'subject.required' => 'Subjek wajib diisi.',
            'subject.max' => 'Subjek maksimal 150 karakter.',
            'subject.regex' => 'Subjek harus satu baris.',
            'text.required' => 'Isi email wajib diisi.',
            'text.max' => 'Isi email maksimal 2000 karakter.',
        ]);

        $target = $this->target($request);
        $to = $this->recipient($target);
        if (! $to) {
            return $this->error('Pelanggan tidak punya alamat email.', [], 422);
        }

        // Mencegah klik ganda: subjek yang sama ke data yang sama dalam semenit terakhir ditolak.
        $recent = $target->customerEmails()->where('status', CustomerEmail::SENT)->where('subject', $validated['subject'])->where('created_at', '>=', now()->subMinute())->exists();
        if ($recent) {
            return $this->error('Email dengan subjek yang sama baru saja terkirim.', [], 429);
        }

        $status = CustomerEmail::SENT;
        try {
            Mail::to($to)->send(new CustomerServiceMessage($validated['subject'], $validated['text'].self::SIGNATURE, $this->replyTo()));
        } catch (Throwable $exception) {
            // Isi email dan alamat tidak ditulis ke log.
            Log::warning('Email CS gagal dikirim.', ['target' => $target::class, 'id' => $target->getKey(), 'reason' => class_basename($exception)]);
            $status = CustomerEmail::FAILED;
        }

        $record = $target->customerEmails()->create(['sent_by' => $request->user()->id, 'to_masked' => CustomerEmail::mask($to), 'subject' => $validated['subject'], 'status' => $status]);
        AuditLog::record($status === CustomerEmail::SENT ? 'customer_email.sent' : 'customer_email.failed', $target, ['email_id' => $record->id, 'to' => $record->to_masked, 'subject' => $record->subject], $request->user()->id);

        if ($status === CustomerEmail::FAILED) {
            return $this->error('Email belum terkirim. Periksa pengaturan email server, atau hubungi pelanggan lewat WhatsApp.', [], 502);
        }

        return $this->success('Email sent.', ['to' => $record->to_masked], 201);
    }

    /** Email kontak toko (Pengaturan, `contact.admin_email`) sebagai Reply-To; null bila kosong atau tidak valid. */
    private function replyTo(): ?string
    {
        $email = Setting::where('key', 'contact.admin_email')->value('value');

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? (string) $email : null;
    }

    private function target(Request $request): Order|Refund|OrderCancellationRequest
    {
        return $request->route('order') ?? $request->route('refund') ?? $request->route('cancellation');
    }

    private function recipient(Order|Refund|OrderCancellationRequest $target): ?string
    {
        $email = match (true) {
            $target instanceof Refund => $target->requester_email ?: $target->order->customer_email,
            $target instanceof OrderCancellationRequest => $target->order->customer_email,
            default => $target->customer_email,
        };

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? (string) $email : null;
    }
}
