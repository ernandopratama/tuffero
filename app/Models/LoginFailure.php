<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email_hash', 'ip_address'])]
class LoginFailure extends Model
{
    public const UPDATED_AT = null;

    /** Hari penyimpanan; baris lebih lama dihapus oleh jadwal harian. */
    public const KEEP_DAYS = 14;

    /** Email disimpan sebagai hash supaya akun tidak terbaca dari tabel ini, tetapi percobaan ke akun yang sama tetap bisa dihitung. */
    public static function record(string $email): void
    {
        self::create(['email_hash' => hash('sha256', mb_strtolower(trim($email))), 'ip_address' => request()->ip()]);
    }
}
