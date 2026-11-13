<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Template balasan CS. Variabel hanya nama dan nomor (tanpa alamat, email, atau nominal), diisi di ReplyTemplateService.
 */
#[Fillable(['title', 'body', 'scope', 'sort_order', 'is_active'])]
class ReplyTemplate extends Model
{
    public const ANY = 'any';

    public const SCOPES = [self::ANY, 'order', 'refund', 'cancellation'];

    /** Variabel yang boleh dipakai di teks template. */
    public const VARIABLES = ['nama', 'no_pesanan', 'resi', 'status', 'no_refund', 'no_pengajuan'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
