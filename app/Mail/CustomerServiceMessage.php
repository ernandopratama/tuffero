<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email CS ke pelanggan (D-108). Teks diketik atau disunting CS, lalu di-escape dan hanya baris barunya dipertahankan,
 * jadi tidak ada HTML dari pengguna yang ikut terkirim.
 */
class CustomerServiceMessage extends Mailable
{
    /** @param  string|null  $replyAddress  email kontak toko yang dipantau tim, supaya balasan pelanggan tidak hilang di alamat noreply */
    public function __construct(private readonly string $subjectLine, public readonly string $body, private readonly ?string $replyAddress = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine, replyTo: $this->replyAddress ? [new Address($this->replyAddress)] : []);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->body)));
    }
}
