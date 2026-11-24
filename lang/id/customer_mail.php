<?php

// Email otomatis pembatalan dan refund untuk pelanggan (CustomerOrderUpdate). Jangan memuat alamat, data kartu, atau token.
return [
    'greeting' => 'Halo,',
    'order' => 'Nomor pesanan',
    'reference' => 'Nomor pengajuan',
    'status' => 'Status',
    'next' => 'Langkah berikutnya',
    'contact' => 'Ada pertanyaan? Hubungi CS Tuffero lewat WhatsApp :phone.',
    'safety' => 'Tuffero tidak pernah meminta kode OTP, PIN, atau kata sandi Anda.',
    'check' => 'Cek status pesanan',
    'footer' => 'Email otomatis dari Tuffero, mohon tidak dibalas.',

    'messages' => [
        'cancelled_unpaid' => [
            'subject' => 'Pesanan :order dibatalkan',
            'status' => 'Pesanan :order dibatalkan sebelum dibayar.',
            'next' => 'Tidak ada yang perlu dibayar. Bila Anda sempat membayar, hubungi CS agar dana dikembalikan.',
        ],
        'cancellation_submitted' => [
            'subject' => 'Pengajuan pembatalan pesanan :order diterima',
            'status' => 'Pengajuan pembatalan :ref sedang menunggu pemeriksaan CS.',
            'next' => 'CS memeriksa pengajuan Anda dan kami akan mengabari hasilnya. Anda tidak perlu mengirim ulang pengajuan.',
        ],
        'cancellation_approved' => [
            'subject' => 'Pesanan :order dibatalkan',
            'status' => 'Pengajuan pembatalan :ref disetujui. Pesanan dibatalkan.',
            'next' => 'Pesanan ini belum dibayar, jadi tidak ada dana yang perlu dikembalikan.',
        ],
        'cancellation_refund_pending' => [
            'subject' => 'Pesanan :order dibatalkan, dana akan dikembalikan',
            'status' => 'Pengajuan pembatalan :ref disetujui. Pesanan dibatalkan dan dana Anda sedang diproses untuk dikembalikan.',
            'next' => 'Kami akan mengabari Anda saat dana sudah dikembalikan. Bila data rekening diperlukan, CS menghubungi Anda lewat kontak resmi Tuffero.',
        ],
        'cancellation_rejected' => [
            'subject' => 'Pengajuan pembatalan pesanan :order tidak disetujui',
            'status' => 'Pengajuan pembatalan :ref tidak disetujui. Pesanan tidak dibatalkan.',
            'next' => 'Hubungi CS bila ada pertanyaan tentang keputusan ini.',
        ],
        'cancellation_refunded' => [
            'subject' => 'Dana pesanan :order sudah dikembalikan',
            'status' => 'Dana untuk pembatalan :ref sudah dikembalikan.',
            'next' => 'Waktu dana tiba bergantung pada bank atau metode pembayaran Anda. Hubungi CS bila dana belum Anda terima.',
        ],
        'late_payment_refunded' => [
            'subject' => 'Dana pesanan :order sudah dikembalikan',
            'status' => 'Pembayaran Anda untuk pesanan :order masuk setelah pesanan ditutup, dan dananya sudah dikembalikan.',
            'next' => 'Waktu dana tiba bergantung pada bank atau metode pembayaran Anda. Hubungi CS bila dana belum Anda terima.',
        ],
        'refund_submitted' => [
            'subject' => 'Pengajuan refund :ref diterima',
            'status' => 'Pengajuan refund :ref untuk pesanan :order sudah kami terima.',
            'next' => 'Kirim video unboxing ke WhatsApp atau email admin Tuffero dengan menyebut nomor :ref. CS akan menghubungi Anda.',
        ],
        'refund_approved' => [
            'subject' => 'Refund :ref disetujui',
            'status' => 'Pengajuan refund :ref untuk pesanan :order disetujui.',
            'next' => 'CS menghubungi Anda lewat kontak resmi Tuffero untuk menyelesaikan pengembalian.',
        ],
        'refund_rejected' => [
            'subject' => 'Refund :ref tidak disetujui',
            'status' => 'Pengajuan refund :ref untuk pesanan :order tidak disetujui.',
            'next' => 'Hubungi CS bila ada pertanyaan tentang keputusan ini.',
        ],
        'refund_evidence_insufficient' => [
            'subject' => 'Video unboxing refund :ref perlu dilengkapi',
            'status' => 'Video unboxing untuk refund :ref belum lengkap, jadi pengajuan belum bisa dilanjutkan.',
            'next' => 'Kirim ulang video yang jelas ke WhatsApp atau email admin Tuffero dengan menyebut nomor :ref: mulai dari nomor resi, paket masih tersegel, sampai produk di dalamnya.',
        ],
        'refund_paid_manual_transfer' => [
            'subject' => 'Dana refund :ref sudah ditransfer',
            'status' => 'Dana refund :ref untuk pesanan :order sudah ditransfer.',
            'next' => 'Waktu dana tiba bergantung pada bank Anda. Hubungi CS bila dana belum Anda terima.',
        ],
        'refund_paid_resend' => [
            'subject' => 'Barang pengganti refund :ref sudah diproses',
            'status' => 'Refund :ref untuk pesanan :order diselesaikan dengan kirim ulang barang.',
            'next' => 'Hubungi CS untuk informasi pengiriman barang pengganti.',
        ],
        'refund_paid_voucher' => [
            'subject' => 'Voucher pengganti refund :ref sudah diberikan',
            'status' => 'Refund :ref untuk pesanan :order diselesaikan dengan voucher pengganti.',
            'next' => 'Detail voucher disampaikan oleh CS. Hubungi CS bila Anda belum menerimanya.',
        ],
    ],
];
