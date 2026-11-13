<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\StaffEvent;
use Illuminate\Support\Facades\Notification;

/**
 * Mengirim peristiwa operasional ke staf menurut peran. Penerima: akun aktif yang memegang salah satu peran
 * (Super Admin tidak otomatis ikut, supaya tidak kebanjiran). Langsung terkirim tanpa antrean, dan karena tersimpan
 * di database yang sama, ikut dibatalkan bila transaksi pemanggil dibatalkan.
 * Staf boleh mematikan jenis peristiwa tertentu di Profil (D-090); peringatan keamanan dan sebutan (@) tidak bisa dimatikan.
 */
class StaffNotifier
{
    /**
     * Jenis peristiwa yang bisa dimatikan: kunci => [label, peran penerima].
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const EVENTS = [
        'order_paid' => ['Pesanan baru dibayar', ['store-admin', 'warehouse']],
        'shipment_problem' => ['Masalah pengiriman', ['store-admin', 'warehouse']],
        'payment_late' => ['Pembayaran masuk untuk pesanan yang sudah ditutup', ['store-admin']],
        'low_stock' => ['Stok menipis', ['store-admin', 'warehouse']],
        'cancellation_submitted' => ['Pengajuan pembatalan baru', ['customer-service']],
        'cancellation_validated' => ['Pembatalan menunggu ACC Admin Toko', ['store-admin']],
        'cancellation_store_approved' => ['Pembatalan menunggu ACC Pemilik', ['owner']],
        'refund_pending' => ['Dana perlu dikembalikan', ['store-admin']],
        'refund_submitted' => ['Pengajuan refund baru', ['customer-service']],
        'refund_review' => ['Refund menunggu keputusan Pemilik', ['owner']],
        'refund_approved' => ['Refund disetujui, dana menunggu dikembalikan', ['customer-service']],
        'cancellation_overdue' => ['Pembatalan lewat batas proses', ['customer-service', 'store-admin']],
        'draft_submitted' => ['Draf konten menunggu keputusan Pemilik', ['owner']],
        'draft_decided' => ['Draf konten disetujui atau ditolak', ['marketing']],
        'draft_due' => ['Draf konten siap diposting (jadwal besok atau lewat)', ['marketing']],
        'draft_waiting' => ['Draf konten menunggu keputusan lebih dari 2 hari', ['owner']],
    ];

    /** @param  list<string>  $roles */
    public function toRoles(array $roles, StaffEvent $event, ?User $except = null): void
    {
        $key = $event->event();

        $users = User::role($roles)
            ->where('is_active', true)
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->get()
            ->reject(fn (User $user) => in_array($key, $user->notification_muted ?? [], true));

        if ($users->isNotEmpty()) {
            Notification::sendNow($users, $event);
        }
    }

    /**
     * Jenis peristiwa yang relevan untuk akun ini (menurut perannya), lengkap dengan status dimatikan.
     *
     * @return list<array{key: string, label: string, muted: bool}>
     */
    public function preferencesFor(User $user): array
    {
        $roles = $user->getRoleNames()->all();
        $muted = $user->notification_muted ?? [];

        return collect(self::EVENTS)
            ->filter(fn (array $event) => array_intersect($event[1], $roles) !== [])
            ->map(fn (array $event, string $key) => ['key' => $key, 'label' => $event[0], 'muted' => in_array($key, $muted, true)])
            ->values()
            ->all();
    }
}
