<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Refund;
use App\Models\RefundItem;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\StaffEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refund barang bermasalah (D-043, D-044) dan retur produk utuh (D-097). Sistem tidak pernah menyetujui apa pun otomatis dan
 * tidak memindahkan uang: transfer manual di luar sistem (D-031), nomor rekening tidak disimpan, video unboxing dikirim ke
 * WhatsApp atau email admin (hanya status penerimaannya yang dicatat di sini).
 *
 * Alur: submitted (CS menghubungi pelanggan) -> awaiting_evidence (CS memeriksa video) -> under_review (Pemilik memutuskan)
 * -> approved (CS mencatat transfer) -> refunded. Ditolak atau dibatalkan dari tahap mana pun sebelum disetujui.
 */
class RefundService
{
    /** Bawaan bila setting belum ada: retur 3 hari kalender (D-097), klaim 168 jam (D-043), proses sampai dana diterima 7 hari (D-043). */
    private const DEFAULT_RETURN_DAYS = 3;

    private const DEFAULT_CLAIM_HOURS = 168;

    private const DEFAULT_PAYOUT_DAYS = 7;

    public function __construct(private StaffNotifier $notifier) {}

    // ---------- pelanggan ----------

    /**
     * Verifikasi kepemilikan lewat nomor pesanan dan email atau HP, lalu kembalikan yang boleh diajukan. Pesan galat sama untuk
     * pesanan yang tidak ada dan data yang tidak cocok (anti-enumerasi).
     *
     * @return array<string, mixed>
     */
    public function lookup(string $orderNumber, string $contact): array
    {
        $order = Order::findForContact($orderNumber, $contact);

        if (! $order) {
            throw ValidationException::withMessages(['order' => 'not_found']);
        }

        return $this->summary($order);
    }

    /**
     * @param  array{order_number: string, contact: string, reason_type: string, description: string, contact_preference: string, items: list<array{id: int, quantity: int}>}  $data
     * @return array{refund: Refund, created: bool}
     */
    public function submit(array $data, string $idempotencyKey, ?string $ip, ?string $userAgent): array
    {
        return DB::transaction(function () use ($data, $idempotencyKey, $ip, $userAgent) {
            $found = Order::findForContact($data['order_number'], $data['contact']);

            if (! $found) {
                throw ValidationException::withMessages(['order' => 'not_found']);
            }

            // Kunci pesanan: dua pengajuan bersamaan untuk barang yang sama diproses bergiliran, jadi jumlah tidak melebihi pesanan.
            $order = Order::whereKey($found->id)->lockForUpdate()->firstOrFail();

            if ($existing = Refund::where('idempotency_key', $idempotencyKey)->first()) {
                if ($existing->order_id !== $order->id) {
                    throw ValidationException::withMessages(['order' => 'not_found']);
                }

                return ['refund' => $existing, 'created' => false];
            }

            if ($problem = $this->problem($order, $data['reason_type'])) {
                throw ValidationException::withMessages(['order' => $problem]);
            }

            $lines = $this->lines($order, $data['items']);

            $refund = Refund::create([
                'public_ref' => (string) str()->uuid(),
                'refund_number' => $this->number(),
                'order_id' => $order->id,
                'idempotency_key' => $idempotencyKey,
                'requester_email' => $order->customer_email,
                'requester_phone' => $order->customer_phone,
                'contact_preference' => $data['contact_preference'],
                'reason_type' => $data['reason_type'],
                'description' => $data['description'],
                'requested_amount' => array_sum(array_column($lines, 'line_amount')),
                'evidence_status' => 'awaiting',
                'terms_version' => (string) (Setting::where('key', 'refund.terms_version')->value('value') ?: 'draf'),
                'terms_accepted_at' => now(),
                'status' => Refund::SUBMITTED,
                'due_at' => now()->addDays((int) (Setting::where('key', 'refund.payout_days_max')->value('value') ?: self::DEFAULT_PAYOUT_DAYS)),
                'ip_address' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            ]);

            $refund->items()->createMany($lines);
            $this->history($refund, null, Refund::SUBMITTED, 'customer', null, null);
            $this->notify(['customer-service'], 'refund_submitted', $refund, "Pengajuan refund {$refund->refund_number} menunggu diproses", $this->reasonLabel($refund->reason_type));

            return ['refund' => $refund, 'created' => true];
        }, 3);
    }

    // ---------- admin ----------

    /** CS sudah menghubungi pelanggan (untuk retur ini adalah konfirmasi yang diwajibkan D-097) dan menunggu video. */
    public function contacted(Refund $refund, ?string $note, User $actor): Refund
    {
        return $this->step($refund, [Refund::SUBMITTED], function (Refund $locked) use ($note, $actor) {
            $locked->forceFill(['status' => Refund::AWAITING_EVIDENCE, 'contacted_at' => now(), 'admin_note' => $note ?: $locked->admin_note])->save();
            $this->history($locked, Refund::SUBMITTED, Refund::AWAITING_EVIDENCE, 'admin', $actor->id, $note);
        });
    }

    /**
     * Hasil pemeriksaan video unboxing. Lengkap: lanjut ke Pemilik. Kurang lengkap: tetap menunggu, catatan menjelaskan kekurangannya.
     * Videonya sendiri tidak disimpan di sistem.
     */
    public function recordEvidence(Refund $refund, string $result, string $channel, string $note, User $actor): Refund
    {
        return $this->step($refund, [Refund::SUBMITTED, Refund::AWAITING_EVIDENCE], function (Refund $locked) use ($result, $channel, $note, $actor) {
            $complete = $result === 'received';
            $from = $locked->status;
            $to = $complete ? Refund::UNDER_REVIEW : Refund::AWAITING_EVIDENCE;

            $locked->forceFill([
                'status' => $to,
                'evidence_status' => $complete ? 'received' : 'insufficient',
                'evidence_channel' => $channel,
                'evidence_received_at' => $complete ? now() : $locked->evidence_received_at,
                'evidence_note' => $note,
                'contacted_at' => $locked->contacted_at ?? now(),
            ])->save();

            $this->history($locked, $from, $to, 'admin', $actor->id, ($complete ? 'Video lengkap. ' : 'Video kurang lengkap. ').$note);

            if ($complete) {
                $this->notify(['owner'], 'refund_review', $locked, "Refund {$locked->refund_number} menunggu keputusan Pemilik", $this->reasonLabel($locked->reason_type));
            }
        });
    }

    /**
     * Pemilik menyetujui dengan nilai barang yang boleh lebih kecil dari yang diminta, tidak pernah lebih besar. Ongkir yang dibayar pelanggan
     * ikut dikembalikan hanya bila Pemilik memilihnya, kesalahan ada di Tuffero (salah kirim atau barang kurang), dan ongkir pesanan itu
     * belum dikembalikan di pengajuan lain.
     */
    public function approve(Refund $refund, int $amount, bool $includeShipping, ?string $note, User $actor): Refund
    {
        return $this->step($refund, [Refund::UNDER_REVIEW], function (Refund $locked) use ($amount, $includeShipping, $note, $actor) {
            if ($locked->evidence_status !== 'received') {
                throw ValidationException::withMessages(['refund' => 'Video unboxing belum dinyatakan lengkap.']);
            }

            if ($amount < 1 || $amount > $locked->requested_amount) {
                throw ValidationException::withMessages(['approved_amount' => 'Nilai disetujui harus antara Rp1 dan jumlah yang diminta.']);
            }

            $shipping = 0;

            if ($includeShipping) {
                $problem = $this->shippingProblem($locked);

                if ($problem) {
                    throw ValidationException::withMessages(['include_shipping' => $problem]);
                }

                $shipping = (int) $locked->order->shipping_fee;
            }

            $locked->forceFill(['status' => Refund::APPROVED, 'approved_amount' => $amount, 'shipping_amount' => $shipping, 'decided_by_user_id' => $actor->id, 'decided_at' => now(), 'admin_note' => $note ?: $locked->admin_note])->save();
            $this->history($locked, Refund::UNDER_REVIEW, Refund::APPROVED, 'admin', $actor->id, trim(($shipping ? 'Termasuk ongkir. ' : '').($note ?? '')));
            $this->notify(['customer-service'], 'refund_approved', $locked, "Refund {$locked->refund_number} disetujui, dana menunggu dikembalikan", 'Hubungi pelanggan untuk data rekening, lalu transfer manual');
        });
    }

    /**
     * CS mencatat pilihan pelanggan setelah video dinyatakan lengkap: dana kembali, kirim ulang, atau voucher pengganti. Pelanggan yang
     * memilih; Pemilik tetap memutuskan. Ongkir hanya untuk pengembalian dana, jadi tidak bisa diganti bila ongkir sudah ikut disetujui.
     */
    public function choose(Refund $refund, string $choice, ?string $note, User $actor): Refund
    {
        return $this->step($refund, [Refund::UNDER_REVIEW, Refund::APPROVED], function (Refund $locked) use ($choice, $note, $actor) {
            if ($choice !== 'refund' && $locked->shipping_amount > 0) {
                throw ValidationException::withMessages(['choice' => 'Ongkir sudah ikut disetujui; ongkir hanya untuk pengembalian dana.']);
            }

            $locked->forceFill(['customer_choice' => $choice, 'admin_note' => $note ?: $locked->admin_note])->save();
            $this->history($locked, $locked->status, $locked->status, 'admin', $actor->id, trim('Pilihan pelanggan: '.['refund' => 'dana dikembalikan', 'resend' => 'kirim ulang', 'voucher' => 'voucher'][$choice].'. '.($note ?? '')));
        });
    }

    public function reject(Refund $refund, string $reason, User $actor): Refund
    {
        return $this->step($refund, Refund::OPEN, function (Refund $locked) use ($reason, $actor) {
            $from = $locked->status;
            $locked->forceFill(['status' => Refund::REJECTED, 'rejection_reason' => $reason, 'decided_by_user_id' => $actor->id, 'decided_at' => now()])->save();
            $this->history($locked, $from, Refund::REJECTED, 'admin', $actor->id, $reason);
        });
    }

    /** Dibatalkan oleh CS, misalnya pelanggan menarik pengajuan. Barang yang tadinya diklaim bebas lagi. */
    public function cancel(Refund $refund, string $note, User $actor): Refund
    {
        return $this->step($refund, Refund::OPEN, function (Refund $locked) use ($note, $actor) {
            $from = $locked->status;
            $locked->forceFill(['status' => Refund::CANCELLED, 'admin_note' => $note])->save();
            $this->history($locked, $from, Refund::CANCELLED, 'admin', $actor->id, $note);
        });
    }

    /**
     * Dana sudah dikembalikan lewat transfer manual (D-031). Yang dicatat hanya referensi transfer, bukan nomor rekening.
     * Status pesanan tidak diubah: refund ini per barang dan bisa sebagian, berbeda dari pembatalan pesanan.
     */
    public function markRefunded(Refund $refund, string $reference, ?string $note, User $actor): Refund
    {
        return $this->step($refund, [Refund::APPROVED], function (Refund $locked) use ($reference, $note, $actor) {
            $locked->forceFill([
                'status' => Refund::REFUNDED, 'refund_method' => ['refund' => 'manual_transfer', 'resend' => 'resend', 'voucher' => 'voucher'][$locked->customer_choice] ?? 'manual_transfer', 'refund_reference' => $reference,
                'refunded_at' => now(), 'refunded_by_user_id' => $actor->id, 'admin_note' => $note ?: $locked->admin_note,
            ])->save();
            $this->history($locked, Refund::APPROVED, Refund::REFUNDED, 'admin', $actor->id, trim("Referensi: {$reference}. ".($note ?? '')));
        });
    }

    // ---------- aturan ----------

    /**
     * Kapan barang diterima: dari tracking Biteship (delivered_at), atau saat pesanan ditandai selesai bila tidak ada.
     */
    public function receivedAt(Order $order): ?CarbonImmutable
    {
        if ($order->status !== Order::COMPLETED) {
            return null;
        }

        $at = data_get($order, 'shipment.delivered_at')
            ?? $order->histories()->where('status_type', 'order')->where('to_status', Order::COMPLETED)->latest('id')->value('created_at')
            ?? $order->updated_at;

        return $at ? CarbonImmutable::parse($at) : null;
    }

    /** Batas akhir pengajuan untuk jenis masalah ini: retur produk utuh 3 hari kalender (D-097), klaim lainnya 168 jam (D-043). */
    public function deadline(CarbonImmutable $receivedAt, string $reason): CarbonImmutable
    {
        return $reason === Refund::RETURN_REASON
            ? $receivedAt->addDays((int) (Setting::where('key', 'refund.return_window_days')->value('value') ?: self::DEFAULT_RETURN_DAYS))
            : $receivedAt->addHours((int) (Setting::where('key', 'refund.claim_window_hours')->value('value') ?: self::DEFAULT_CLAIM_HOURS));
    }

    /**
     * Alasan ongkir tidak bisa ikut dikembalikan, atau null bila boleh. Mengunci baris pesanan supaya dua persetujuan bersamaan untuk
     * pesanan yang sama tidak sama-sama mengembalikan ongkirnya.
     */
    public function shippingProblem(Refund $refund): ?string
    {
        $order = Order::whereKey($refund->order_id)->lockForUpdate()->firstOrFail();

        return match (true) {
            $refund->customer_choice !== 'refund' => 'Ongkir hanya untuk pengembalian dana, bukan kirim ulang atau voucher.',
            ! in_array($refund->reason_type, Refund::SHIPPING_REASONS, true) => 'Ongkir hanya dikembalikan bila kesalahan ada di Tuffero (salah kirim atau barang kurang).',
            $order->shipping_fee < 1 => 'Pesanan ini tidak punya ongkir.',
            Refund::where('order_id', $order->id)->whereKeyNot($refund->id)->whereIn('status', Refund::CLAIMING)->where('shipping_amount', '>', 0)->exists() => 'Ongkir pesanan ini sudah dikembalikan di pengajuan lain.',
            default => null,
        };
    }

    /** Kode alasan pengajuan tidak bisa dibuat, atau null bila boleh. */
    public function problem(Order $order, string $reason): ?string
    {
        $received = $this->receivedAt($order);

        if (! $received) {
            return 'not_received';
        }

        return $this->deadline($received, $reason)->isPast() ? 'window_closed' : null;
    }

    /** @return array<string, mixed> */
    private function summary(Order $order): array
    {
        $order->loadMissing('items', 'shipment');
        $received = $this->receivedAt($order);
        $claimed = $this->claimed($order);
        $discounts = $this->allocatedDiscounts($order);

        return [
            'order_number' => $order->order_number,
            'has_discount' => $order->discount_total > 0,
            'received_at' => $received?->toIso8601String(),
            'windows' => $received ? [
                'claim' => $this->window($received, 'damaged'),
                'return' => $this->window($received, Refund::RETURN_REASON),
            ] : null,
            'items' => $order->items->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->product_name_snapshot,
                'variant' => $item->variant_name_snapshot,
                'unit_price' => $item->unit_price,
                // Yang dibayar untuk seluruh baris ini setelah bagian potongan voucher; nilai refund = lantai(paid_total x jumlah / dipesan).
                'paid_total' => $item->line_total - ($discounts[$item->id] ?? 0),
                'ordered' => $item->quantity,
                'available' => max(0, $item->quantity - ($claimed[$item->id] ?? 0)),
            ])->values()->all(),
        ];
    }

    /** @return array{open: bool, closes_at: string} */
    private function window(CarbonImmutable $received, string $reason): array
    {
        $closes = $this->deadline($received, $reason);

        return ['open' => $closes->isFuture(), 'closes_at' => $closes->toIso8601String()];
    }

    /**
     * Jumlah barang yang sedang atau sudah diklaim per item pesanan (yang ditolak atau dibatalkan tidak dihitung).
     *
     * @return array<int, int>
     */
    private function claimed(Order $order): array
    {
        return RefundItem::query()
            ->join('refunds', 'refunds.id', '=', 'refund_items.refund_id')
            ->where('refunds.order_id', $order->id)
            ->whereIn('refunds.status', Refund::CLAIMING)
            ->groupBy('refund_items.order_item_id')
            ->selectRaw('refund_items.order_item_id as item_id, SUM(refund_items.quantity) as total')
            ->pluck('total', 'item_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * Baris refund dari pesanan: harga dan jumlah diambil dari database, bukan dari browser.
     *
     * @param  list<array{id: int, quantity: int}>  $requested
     * @return list<array{order_item_id: int, quantity: int, unit_price_snapshot: int, line_amount: int}>
     */
    private function lines(Order $order, array $requested): array
    {
        $items = $order->items()->get()->keyBy('id');
        $claimed = $this->claimed($order);
        $discounts = $this->allocatedDiscounts($order);
        $lines = [];

        foreach ($requested as $row) {
            $item = $items->get((int) $row['id']);
            $quantity = (int) $row['quantity'];

            if (! $item || isset($lines[$item->id])) {
                throw ValidationException::withMessages(['items' => 'items_invalid']);
            }

            if ($quantity < 1 || $quantity > $item->quantity - ($claimed[$item->id] ?? 0)) {
                throw ValidationException::withMessages(['items' => 'quantity_exceeded']);
            }

            $lines[$item->id] = [
                'order_item_id' => $item->id,
                'quantity' => $quantity,
                'unit_price_snapshot' => $item->unit_price,
                'line_amount' => $this->paidFor($item, $quantity, $discounts[$item->id] ?? 0),
            ];
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'items_invalid']);
        }

        return array_values($lines);
    }

    /**
     * Yang dibayar pelanggan untuk `$quantity` unit sebuah baris: harga baris dikurangi bagian potongan voucher, dibagi rata per unit
     * (dibulatkan ke bawah). Seluruh unit memberi persis yang dibayar; tidak pernah kurang dari Rp1 dan tidak lebih dari harga normal.
     */
    private function paidFor(OrderItem $item, int $quantity, int $discount): int
    {
        return max(1, intdiv(($item->line_total - $discount) * $quantity, $item->quantity));
    }

    /**
     * Potongan voucher pesanan (discount_total) dibagi ke tiap baris menurut besar barisnya; sisa pembulatan ke baris terakhir supaya
     * jumlahnya persis. Potongan produk dan kampanye sudah ada di harga satuan pesanan, jadi tidak dibagi lagi.
     *
     * @return array<int, int> id baris pesanan => potongan
     */
    private function allocatedDiscounts(Order $order): array
    {
        $items = $order->items()->orderBy('id')->get();
        $discount = (int) $order->discount_total;
        $base = (int) $items->sum('line_total');

        if ($discount <= 0 || $base <= 0) {
            return [];
        }

        $allocated = [];
        $left = $discount;

        foreach ($items as $i => $item) {
            $share = $i === $items->count() - 1 ? $left : intdiv($discount * $item->line_total, $base);
            $allocated[$item->id] = $share;
            $left -= $share;
        }

        return $allocated;
    }

    /**
     * @param  list<string>  $expected
     * @param  callable(Refund): void  $action
     */
    private function step(Refund $refund, array $expected, callable $action): Refund
    {
        return DB::transaction(function () use ($refund, $expected, $action) {
            $locked = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $expected, true)) {
                throw ValidationException::withMessages(['refund' => 'Status pengajuan sudah berubah. Muat ulang halaman.']);
            }

            $action($locked);

            return $locked;
        }, 3);
    }

    private function history(Refund $refund, ?string $from, string $to, string $source, ?int $actorId, ?string $reason): void
    {
        // Lewat relasi pesanan supaya order_id terisi; refund_id menandai riwayat ini milik pengajuan, bukan pesanan.
        $refund->order->histories()->create([
            'refund_id' => $refund->id,
            'status_type' => 'refund',
            'from_status' => $from,
            'to_status' => $to,
            'source' => $source,
            'actor_user_id' => $actorId,
            'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
        ]);
    }

    /** @param list<string> $roles */
    private function notify(array $roles, string $event, Refund $refund, string $title, string $excerpt): void
    {
        $this->notifier->toRoles($roles, new StaffEvent($event, 'refund', $refund->refund_number, $title, $excerpt));
    }

    private function reasonLabel(string $reason): string
    {
        return [
            'damaged' => 'Barang rusak', 'wrong_item' => 'Salah kirim', 'missing_item' => 'Barang kurang',
            'not_fit_for_consumption' => 'Tidak layak konsumsi', 'return_unopened' => 'Retur produk utuh', 'other' => 'Lainnya',
        ][$reason] ?? $reason;
    }

    private function number(): string
    {
        do {
            $number = 'RF'.now()->format('ym').'-'.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Refund::where('refund_number', $number)->exists());

        return $number;
    }
}
