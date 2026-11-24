<?php

namespace App\Services;

use App\Jobs\SendCustomerWhatsApp;
use App\Models\CustomerNotification;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;

/**
 * Titik pengiriman WhatsApp ke pelanggan untuk pembatalan dan refund. Bawaan MATI (`notifications.customer_whatsapp_enabled`)
 * sampai akun Meta aktif dan template disetujui Meta. Nama template diisi Super Admin di Pengaturan, tidak di kode.
 * Template yang diharapkan: isi dengan dua variabel, {{1}} nomor pesanan dan {{2}} status; bahasa mengikuti pesanan.
 * Belum pernah diuji ke Meta sungguhan.
 */
class CustomerWhatsApp
{
    public const SWITCH = 'notifications.customer_whatsapp_enabled';

    public const TEMPLATE = 'notifications.customer_whatsapp_template';

    public static function enabled(): bool
    {
        return (string) Setting::where('key', self::SWITCH)->value('value') === '1';
    }

    /** @return string `queued`, atau `skipped` bila template atau kunci Meta belum ada */
    public function send(CustomerNotification $notification): string
    {
        $config = config('services.whatsapp');

        if ($this->template() === '' || blank($config['token'] ?? null) || blank($config['phone_number_id'] ?? null)) {
            return CustomerNotification::SKIPPED;
        }

        // Hanya id yang masuk antrean: token dan nomor dibaca saat job berjalan, tidak tersimpan di tabel jobs.
        SendCustomerWhatsApp::dispatch($notification->id);

        return CustomerNotification::QUEUED;
    }

    /** Dipanggil job di antrean. Galat dilempar supaya antrean mencoba ulang dan akhirnya menandai `failed`. */
    public function deliver(CustomerNotification $notification): void
    {
        $config = config('services.whatsapp');
        $order = $notification->order;
        $locale = CustomerNotifier::locale($order);
        $status = trans("customer_mail.messages.{$notification->message}.status", ['order' => $order->order_number, 'ref' => (string) $notification->reference], $locale);

        Http::withToken($config['token'])
            ->timeout(10)
            ->post("https://graph.facebook.com/{$config['graph_version']}/{$config['phone_number_id']}/messages", [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => ltrim($order->customer_phone, '+'),
                'type' => 'template',
                'template' => ['name' => $this->template(), 'language' => ['code' => $locale], 'components' => [['type' => 'body', 'parameters' => [
                    ['type' => 'text', 'text' => $order->order_number],
                    ['type' => 'text', 'text' => $status],
                ]]]],
            ])
            ->throw();

        CustomerNotification::whereKey($notification->id)->update(['status' => CustomerNotification::SENT, 'error' => null]);
    }

    private function template(): string
    {
        return (string) Setting::where('key', self::TEMPLATE)->value('value');
    }
}
