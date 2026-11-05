import { ROUTES } from '../utils/site';

// Tujuan tautan per jawaban; sama untuk semua bahasa. Label ada di `links` tiap bahasa.
export const ANSWER_LINKS = {
    order: [{ key: 'products', href: ROUTES.products }],
    info: [{ key: 'products', href: ROUTES.products }],
    hampers: [{ key: 'hampers', href: `${ROUTES.services}#hampers` }, { key: 'whatsapp', external: true }],
    shipping: [{ key: 'track', href: ROUTES.track }],
    human: [{ key: 'whatsapp', external: true }],
    unsent: [{ key: 'whatsapp', external: true }],
};

export const QUICK_REPLIES = [
    { key: 'order', icon: 'bag' },
    { key: 'info', icon: 'info' },
    { key: 'hampers', icon: 'gift' },
    { key: 'shipping', icon: 'truck' },
    { key: 'human', icon: 'headset' },
];

export const customerService = {
    id: {
        title: 'Tuffero Customer Service',
        statusOpen: 'Buka sekarang',
        statusClosed: 'Tutup · Sen–Sab 09.00–16.00 WIB',
        minimize: 'Perkecil chat',
        close: 'Tutup chat dan hapus percakapan',
        log: 'Percakapan',
        typing: 'Tuffero sedang mengetik',
        quickReplies: 'Pilihan cepat',
        inputLabel: 'Tulis pesan',
        placeholder: 'Ketik pesan...',
        send: 'Kirim pesan',
        notice: 'Balasan otomatis. Untuk berbicara dengan tim kami, gunakan WhatsApp.',
        greeting: 'Halo! 👋 Selamat datang di Tuffero. Ada yang bisa kami bantu hari ini?',
        labels: { order: 'Pesan Produk', info: 'Informasi Produk', hampers: 'Hampers & Paket Hadiah', shipping: 'Pengiriman', human: 'Hubungi Customer Service' },
        answers: {
            order: 'Pilih produk di halaman Produk, masukkan ke keranjang, lalu selesaikan di checkout. Anda bisa memesan tanpa membuat akun.',
            info: 'Deskripsi, pilihan varian, dan harga ada di halaman setiap produk.',
            hampers: 'Lihat layanan hampers kami. Untuk kebutuhan khusus, tim kami bisa membantu lewat WhatsApp.',
            shipping: 'Pesanan dikirim dari Malang. Ongkir dihitung saat checkout setelah alamat diisi, dan statusnya bisa dipantau di Cek Pesanan.',
            human: 'Tim kami dapat dihubungi lewat WhatsApp. Jam operasional kami Senin–Sabtu, 09.00–16.00 WIB.',
            unsent: 'Chat langsung belum tersedia di website, jadi pesan Anda belum terkirim ke tim kami. Lanjutkan lewat WhatsApp agar pesan ini sampai.',
        },
        links: { products: 'Lihat Produk', hampers: 'Lihat Layanan Hampers', track: 'Buka Cek Pesanan', whatsapp: 'Lanjutkan ke WhatsApp' },
    },
    en: {
        title: 'Tuffero Customer Service',
        statusOpen: 'Open now',
        statusClosed: 'Closed · Mon–Sat 09.00–16.00 WIB',
        minimize: 'Minimize chat',
        close: 'Close chat and clear conversation',
        log: 'Conversation',
        typing: 'Tuffero is typing',
        quickReplies: 'Quick replies',
        inputLabel: 'Write a message',
        placeholder: 'Type a message...',
        send: 'Send message',
        notice: 'Automated replies. To talk to our team, use WhatsApp.',
        greeting: 'Hello! 👋 Welcome to Tuffero. How can we help you today?',
        labels: { order: 'Order Products', info: 'Product Information', hampers: 'Hampers & Gift Sets', shipping: 'Shipping', human: 'Contact Customer Service' },
        answers: {
            order: 'Choose a product on the Products page, add it to your cart, then complete checkout. No account is needed.',
            info: 'Descriptions, variants, and prices are on each product page.',
            hampers: 'See our hampers service. For special requests, our team can help on WhatsApp.',
            shipping: 'Orders ship from Malang. Shipping is calculated at checkout once your address is filled in, and you can follow the status on Track Order.',
            human: 'You can reach our team on WhatsApp. Our opening hours are Monday–Saturday, 09.00–16.00 WIB.',
            unsent: 'Live chat is not available on the website yet, so your message has not reached our team. Continue on WhatsApp so it gets through.',
        },
        links: { products: 'View Products', hampers: 'View Hampers Service', track: 'Open Track Order', whatsapp: 'Continue on WhatsApp' },
    },
};
