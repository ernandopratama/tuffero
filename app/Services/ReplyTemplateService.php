<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\OrderCancellationRequest;
use App\Models\Refund;
use App\Models\ReplyTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Template balasan CS. Tidak ada hapus: yang tak dipakai dinonaktifkan. Setiap perubahan dicatat di audit log.
 * Variabel diisi dari data milik server (bukan kiriman browser), dan teksnya hanya dikembalikan, tidak dikirim ke pelanggan.
 */
class ReplyTemplateService
{
    private const ORDER_STATUS = [
        'pending_payment' => 'menunggu pembayaran', 'paid' => 'sudah dibayar', 'processing' => 'sedang diproses', 'shipped' => 'sedang dikirim',
        'completed' => 'selesai', 'expired' => 'kedaluwarsa', 'cancelled' => 'dibatalkan', 'refunded' => 'dana sudah dikembalikan',
    ];

    /** @param array<string, mixed> $data */
    public function create(array $data, User $actor): ReplyTemplate
    {
        return DB::transaction(function () use ($data, $actor) {
            $template = ReplyTemplate::create([
                'title' => $data['title'],
                'body' => $data['body'],
                'scope' => $data['scope'] ?? ReplyTemplate::ANY,
                'sort_order' => $data['sort_order'] ?? (int) ReplyTemplate::max('sort_order') + 1,
                'is_active' => $data['is_active'] ?? true,
            ])->refresh();

            AuditLog::record('reply_template.created', $template, ['title' => $template->title], $actor->id);

            return $template;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(ReplyTemplate $template, array $data, User $actor): ReplyTemplate
    {
        return DB::transaction(function () use ($template, $data, $actor) {
            AuditLog::saveWithAudit($template, $data, 'reply_template.updated', $actor);

            return $template->refresh();
        });
    }

    /**
     * Template aktif untuk satu jenis data, dengan variabel sudah terisi.
     *
     * @return list<array{id: int, title: string, text: string}>
     */
    public function renderFor(Order|Refund|OrderCancellationRequest $target): array
    {
        $values = $this->values($target);
        $scope = match (true) {
            $target instanceof Refund => 'refund',
            $target instanceof OrderCancellationRequest => 'cancellation',
            default => 'order',
        };

        /** @var Collection<int, ReplyTemplate> $templates */
        $templates = ReplyTemplate::where('is_active', true)->whereIn('scope', [ReplyTemplate::ANY, $scope])->orderBy('sort_order')->orderBy('id')->get();

        return $templates->map(fn (ReplyTemplate $template) => [
            'id' => $template->id,
            'title' => $template->title,
            'text' => $this->fill($template->body, $values),
        ])->all();
    }

    /** Nomor WhatsApp pemesan untuk tautan wa.me (hanya angka, awalan 0 diganti 62); null bila tidak ada. */
    public function whatsappNumber(Order|Refund|OrderCancellationRequest $target): ?string
    {
        $order = $target instanceof Order ? $target : $target->order;
        $digits = preg_replace('/\D/', '', (string) $order->customer_phone) ?? '';
        $digits = preg_replace('/^0/', '62', $digits);

        return $digits !== '' ? $digits : null;
    }

    /**
     * Ganti {variabel} yang dikenal; variabel yang tidak dikenal dibiarkan apa adanya supaya salah ketik terlihat.
     *
     * @param  array<string, string>  $values
     */
    public function fill(string $body, array $values): string
    {
        $pairs = [];
        foreach ($values as $key => $value) {
            $pairs['{'.$key.'}'] = $value;
        }

        return strtr($body, $pairs);
    }

    /** @return array<string, string> */
    private function values(Order|Refund|OrderCancellationRequest $target): array
    {
        $order = $target instanceof Order ? $target : $target->order;
        $order->loadMissing('shipment');

        return [
            'nama' => (string) $order->customer_name,
            'no_pesanan' => $order->order_number,
            'resi' => $order->shipment?->waybill_id ?: '-',
            'status' => self::ORDER_STATUS[$order->status] ?? $order->status,
            'no_refund' => $target instanceof Refund ? $target->refund_number : '-',
            'no_pengajuan' => $target instanceof OrderCancellationRequest ? $target->request_number : '-',
        ];
    }
}
