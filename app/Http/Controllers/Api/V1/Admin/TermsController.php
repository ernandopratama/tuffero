<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\TermsSection;
use App\Services\TermsService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CRUD Syarat dan Ketentuan toko (izin terms.manage: Admin Toko dan Super Admin). Isi hanya teks polos; tampil di toko lewat Blade
 * yang meng-escape semuanya, jadi tidak ada HTML yang diterima atau dirender.
 */
class TermsController extends Controller
{
    use ApiResponse;

    public function __construct(private TermsService $terms) {}

    public function index(): JsonResponse
    {
        return $this->success('Terms retrieved.', [
            'version' => $this->terms->version(),
            'updated_at' => Setting::where('key', TermsService::VERSION_KEY)->value('updated_at'),
            'sections' => TermsSection::orderBy('sort_order')->orderBy('id')->get()->map(fn (TermsSection $s) => $this->row($s))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $section = $this->terms->create($this->validated($request), $request->user());

        return $this->success('Terms section created.', $this->row($section), 201);
    }

    public function update(Request $request, TermsSection $section): JsonResponse
    {
        $this->terms->update($section, $this->validated($request), $request->user());

        return $this->success('Terms section updated.', $this->row($section->refresh()));
    }

    public function destroy(Request $request, TermsSection $section): JsonResponse
    {
        $this->terms->delete($section, $request->user());

        return $this->success('Terms section deleted.');
    }

    public function move(Request $request, TermsSection $section): JsonResponse
    {
        $validated = $request->validate(['direction' => ['required', 'in:up,down']]);

        $this->terms->move($section, $validated['direction'], $request->user());

        return $this->success('Terms section moved.');
    }

    /** @return array{title_id: string, title_en: string, body_id: string, body_en: string, is_published: bool} */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title_id' => ['required', 'string', 'max:150'],
            'title_en' => ['required', 'string', 'max:150'],
            'body_id' => ['required', 'string', 'max:4000'],
            'body_en' => ['required', 'string', 'max:4000'],
            'is_published' => ['required', 'boolean'],
        ], [
            'title_id.required' => 'Judul Indonesia wajib diisi.', 'title_en.required' => 'Judul Inggris wajib diisi.',
            'body_id.required' => 'Isi Indonesia wajib diisi.', 'body_en.required' => 'Isi Inggris wajib diisi.',
            'title_id.max' => 'Judul maksimal 150 karakter.', 'title_en.max' => 'Judul maksimal 150 karakter.',
            'body_id.max' => 'Isi maksimal 4000 karakter.', 'body_en.max' => 'Isi maksimal 4000 karakter.',
        ]);

        // Teks polos: tag dibuang dan spasi ujung dirapikan; baris kosong tetap membentuk paragraf.
        foreach (['title_id', 'title_en', 'body_id', 'body_en'] as $field) {
            $data[$field] = trim(strip_tags($data[$field]));

            if ($data[$field] === '') {
                abort(422, 'Isian tidak boleh kosong.');
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function row(TermsSection $section): array
    {
        return $section->only(['id', 'sort_order', 'title_id', 'title_en', 'body_id', 'body_en', 'is_published', 'updated_at']);
    }
}
