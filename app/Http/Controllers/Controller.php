<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

abstract class Controller
{
    use AuthorizesRequests;

    /** Pilihan jumlah baris per halaman di tabel admin. */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 75, 100];

    /** Nilai di luar pilihan diabaikan, jadi klien tidak bisa meminta halaman raksasa. */
    protected function perPage(Request $request, int $default = 25): int
    {
        $requested = $request->integer('per_page');

        return in_array($requested, self::PER_PAGE_OPTIONS, true) ? $requested : $default;
    }
}
