<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\CheckoutRequest;
use App\Models\CustomerBlock;
use App\Models\Order;
use App\Services\CustomerBlockService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Blokir pembelian pelanggan (D-122): otomatis dari retur berturut-turut atau manual oleh staf. Daftar untuk `customer_blocks.view`,
 * tambah, ubah catatan, dan cabut untuk `customer_blocks.manage` (Admin Toko, Super Admin). Tidak ada hapus: blokir hanya dicabut dengan
 * catatan supaya bukti dan audit tetap ada. Nomor ditampilkan tersamar.
 */
class CustomerBlockController extends Controller
{
    use ApiResponse;

    private const NOTE_MESSAGES = ['note.required' => 'Catatan wajib diisi.', 'note.min' => 'Catatan minimal 5 karakter.', 'note.max' => 'Catatan maksimal 255 karakter.'];

    public function __construct(private CustomerBlockService $blocks) {}

    public function index(Request $request): JsonResponse
    {
        $search = $request->string('search')->trim()->toString();
        $status = $request->query('status');

        $page = CustomerBlock::with('releasedBy:id,name')
            ->when($status === 'active', fn ($query) => $query->active())
            ->when($status === 'released', fn ($query) => $query->whereNotNull('released_at'))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('customer_name', 'like', "%{$search}%")
                ->orWhere('phone_masked', 'like', "%{$search}%")))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        $numbers = Order::whereIn('id', $page->getCollection()->flatMap(fn (CustomerBlock $block) => $block->order_ids)->unique())->pluck('order_number', 'id');

        return $this->success('Customer blocks retrieved.', [
            'items' => $page->getCollection()->map(fn (CustomerBlock $block) => $this->present($block, $numbers))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'address.street' => ['nullable', 'string', 'max:255'],
            'address.district' => ['nullable', 'string', 'max:100'],
            'address.city' => ['nullable', 'string', 'max:100'],
            'address.province' => ['nullable', 'string', 'max:100'],
            'address.postal_code' => ['nullable', 'digits:5'],
            'note' => ['required', 'string', 'min:5', 'max:255'],
        ], ['phone.required' => 'Nomor WhatsApp wajib diisi.', ...self::NOTE_MESSAGES]);

        // Nomor dinormalkan seperti di checkout (0812... dan 62812... menjadi +62812...), supaya cocok dengan nomor pesanan.
        $data['phone'] = CheckoutRequest::normalizePhone($request->string('phone')->toString());
        validator(['phone' => $data['phone']], ['phone' => ['regex:/^\+628\d{8,11}$/']], ['phone.regex' => 'Nomor WhatsApp tidak valid.'])->validate();

        return $this->success('Customer block created.', $this->present($this->blocks->createManual($data, $request->user()), collect()), 201);
    }

    public function update(Request $request, CustomerBlock $block): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']], self::NOTE_MESSAGES);

        return $this->success('Customer block updated.', $this->present($this->blocks->updateNote($block, trim($data['note']), $request->user()), collect()));
    }

    public function release(Request $request, CustomerBlock $block): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:255']], ['note.required' => 'Alasan pencabutan wajib diisi.', 'note.min' => 'Alasan minimal 5 karakter.', 'note.max' => 'Alasan maksimal 255 karakter.']);

        return $this->success('Customer block released.', $this->present($this->blocks->release($block, trim($data['note']), $request->user())->load('releasedBy:id,name'), collect()));
    }

    /**
     * @param  Collection<int, string>  $numbers  id pesanan -> nomor pesanan
     * @return array<string, mixed>
     */
    private function present(CustomerBlock $block, $numbers): array
    {
        return [
            'id' => $block->public_id,
            'source' => $block->source,
            'phone' => $block->phone_masked,
            'customer_name' => $block->customer_name,
            'address_summary' => $block->address_summary,
            'ip_address' => $block->ip_address,
            'orders' => collect($block->order_ids)->map(fn (int $id) => $numbers[$id] ?? '-')->values(),
            'note' => $block->note,
            'is_active' => $block->isActive(),
            'blocked_at' => $block->created_at,
            'released_at' => $block->released_at,
            'released_by' => $block->releasedBy?->name,
            'release_note' => $block->release_note,
        ];
    }
}
