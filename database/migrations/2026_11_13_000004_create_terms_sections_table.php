<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Syarat dan Ketentuan toko yang bisa dikelola dari dashboard (Admin Toko dan Super Admin, izin `terms.manage`), dua bahasa (D-036).
 * Isi awalnya adalah teks S&K yang selama ini ada di berkas bahasa, dengan butir pembatalan, retur, dan refund yang sudah diputuskan
 * (D-043, D-097, D-099). Setiap perubahan menaikkan versi di setting `refund.terms_version`, yang dicatat di tiap pengajuan refund.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('terms_sections', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('sort_order');
            $table->string('title_id', 150);
            $table->string('title_en', 150);
            $table->text('body_id');
            $table->text('body_en');
            $table->boolean('is_published')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });

        $now = now();
        DB::table('terms_sections')->insert(collect($this->defaults())->map(fn (array $row, int $i) => [
            'sort_order' => ($i + 1) * 10,
            'title_id' => $row[0], 'title_en' => $row[1], 'body_id' => $row[2], 'body_en' => $row[3],
            'is_published' => true, 'created_at' => $now, 'updated_at' => $now,
        ])->all());

        // Versi pertama; bernilai kosong sebelumnya (tercatat "draf").
        DB::table('settings')->insertOrIgnore(['key' => 'refund.terms_version', 'value' => null, 'type' => 'string', 'group' => 'refund', 'is_public' => false, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('settings')->where('key', 'refund.terms_version')->where(fn ($q) => $q->whereNull('value')->orWhere('value', ''))->update(['value' => $now->format('Y-m-d').'.1', 'updated_at' => $now]);

        // Izin baru untuk Admin Toko dan Super Admin. Hanya menambah; izin lain yang sudah diatur admin tidak disentuh.
        if (Schema::hasTable('permissions')) {
            $permission = Permission::findOrCreate('terms.manage', 'web');
            Role::whereIn('name', ['store-admin', 'super-admin'])->get()->each(fn (Role $role) => $role->givePermissionTo($permission));
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('terms_sections');

        if (Schema::hasTable('permissions')) {
            Permission::where('name', 'terms.manage')->where('guard_name', 'web')->delete();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /** @return list<array{0: string, 1: string, 2: string, 3: string}> judul id, judul en, isi id, isi en */
    private function defaults(): array
    {
        return [
            ['Ketentuan umum', 'General terms',
                'Syarat ini berlaku untuk pembelian di toko online Tuffero Bakery. Anda boleh berbelanja tanpa akun atau dengan akun.',
                'These terms apply to purchases from the Tuffero Bakery online store. You may shop with or without an account.'],
            ['Verifikasi data', 'Data verification',
                'Tanpa akun: nama, alamat lengkap, dan nomor WhatsApp yang diverifikasi dengan kode OTP. Verifikasi berlaku 1 bulan pada perangkat yang sama; perangkat lain perlu verifikasi baru. Dengan akun: nama, alamat lengkap, email terverifikasi, dan nomor WhatsApp terverifikasi. Nomor yang sudah terdaftar pada akun harus masuk ke akun tersebut untuk checkout.',
                'Without an account: name, full address, and a WhatsApp number verified by OTP code. Verification lasts 1 month on the same device; other devices need a new verification. With an account: name, full address, a verified email, and a verified WhatsApp number. A number already registered to an account must sign in to that account to check out.'],
            ['Voucher, promo, dan diskon', 'Vouchers, promos, and discounts',
                'Pelanggan tanpa akun hanya dapat memakai penawaran untuk pelanggan tanpa akun. Pemilik akun dapat memakai penawaran khusus akun dan penawaran untuk pelanggan tanpa akun. Syarat tiap penawaran mengikuti ketentuan penawaran tersebut.',
                'Customers without an account can only use offers for customers without an account. Account holders can use account-only offers and offers for customers without an account. Each offer follows its own terms.'],
            ['Pemesanan dan pembayaran', 'Orders and payment',
                'Harga, ongkir, dan total dihitung oleh sistem saat checkout. Stok ditahan sampai batas waktu bayar yang ditampilkan. Bila batas waktu lewat, pesanan ditutup dan stok dilepas.',
                'Prices, shipping, and totals are calculated by the system at checkout. Stock is held until the payment deadline shown. After the deadline, the order is closed and the stock released.'],
            ['Pengiriman', 'Shipping',
                'Pesanan dikirim dari Malang lewat kurir yang tersedia saat checkout. Pastikan alamat dan nomor penerima benar sebelum membayar.',
                'Orders ship from Malang with the couriers available at checkout. Make sure the address and recipient number are correct before paying.'],
            ['Pembatalan pesanan', 'Order cancellation',
                'Pesanan hanya bisa dibatalkan sebelum diserahkan ke kurir. Ajukan lewat halaman pesanan Anda. Pembatalan diperiksa dan disetujui oleh tim Tuffero, tidak otomatis. Untuk pesanan yang sudah dibayar, dana dikembalikan lewat transfer manual ke rekening atas nama pemesan, paling lama 7 hari sejak pengajuan.',
                'An order can only be cancelled before it is handed to the courier. Request it from your order page. Cancellations are reviewed and approved by the Tuffero team, never automatically. For an order that is already paid, the money is returned by manual transfer to a bank account in the orderer name, within 7 days of the request at most.'],
            ['Retur dan klaim: batas waktu', 'Returns and claims: deadlines',
                "Retur produk utuh: pelanggan wajib mengajukan paling lambat 3 hari kalender setelah paket diterima, dan lebih dulu mengonfirmasi ke CS.\n\nKlaim barang bermasalah (rusak, salah kirim, kurang, atau tidak layak konsumsi): diajukan paling lambat 7 hari setelah paket diterima.\n\nKeduanya diajukan di halaman Ajukan Refund dengan nomor pesanan dan email atau nomor HP yang dipakai saat memesan.",
                "Return of an intact product: customers must request it within 3 calendar days after the parcel is received, and confirm with CS first.\n\nClaim for a problem item (damaged, wrong, missing, or unfit to eat): requested within 7 days after the parcel is received.\n\nBoth are requested on the Request a Refund page with the order number and the email or phone number used to order."],
            ['Video unboxing wajib', 'Unboxing video required',
                'Setiap retur dan klaim wajib disertai video unboxing yang jelas, mulai dari nomor resi, paket masih tersegel, sampai produk di dalamnya. Video dikirim ke WhatsApp atau email Tuffero dengan menyebut nomor pengajuan, tidak diunggah ke situs. Tanpa video yang jelas dan utuh, pengajuan tidak dapat disetujui.',
                'Every return and claim must come with a clear unboxing video, starting from the tracking number, with the parcel still sealed, through to the product inside. Send the video to the Tuffero WhatsApp or email quoting the request number; it is not uploaded to the site. Without a clear, complete video, a request cannot be approved.'],
            ['Kondisi produk yang diajukan', 'Condition of the product',
                "Retur hanya untuk produk yang utuh: tidak dicoba dan tidak dibuka.\n\nKlaim barang bermasalah mencakup barang rusak atau pecah, salah kirim, barang kurang, dan tidak layak konsumsi (basi atau berjamur). Keterlambatan kurir tanpa kerusakan dan pengajuan tanpa video unboxing yang jelas tidak dapat disetujui.",
                "Returns are only for intact products: not tried and not opened.\n\nClaims cover damaged or broken items, wrong items, missing items, and items unfit to eat (stale or moldy). Courier delays without damage, and requests without a clear unboxing video, cannot be approved."],
            ['Nilai dan pengembalian dana', 'Amount and refund',
                "Nilai yang dikembalikan adalah yang Anda bayar untuk barang yang diajukan, setelah bagian potongan voucher. Ongkir hanya ikut dikembalikan bila kesalahan ada di Tuffero (salah kirim atau barang kurang).\n\nKeputusan ada di Pemilik setelah video diperiksa; bila ditolak, alasannya disampaikan kepada Anda.\n\nDana dikembalikan lewat transfer manual ke rekening atas nama pemesan. Data rekening hanya diminta CS lewat chat, tidak pernah lewat situs ini. Prosesnya paling lama 7 hari sejak pengajuan sampai dana diterima.\n\nSetelah video dinyatakan lengkap, CS dapat menawarkan kirim ulang atau voucher sebagai pengganti, dan Anda yang memilih.",
                "The amount refunded is what you paid for the items you claim, after your share of any voucher discount. Shipping is refunded only when the mistake is on Tuffero (wrong or missing item).\n\nThe owner decides after the video is reviewed; if declined, the reason is given to you.\n\nMoney is returned by manual transfer to a bank account in the orderer name. CS asks for the account details in chat only, never on this site. The process takes 7 days at most from the request until the money is received.\n\nOnce the video is confirmed complete, CS may offer a resend or a voucher instead, and the choice is yours."],
        ];
    }
};
