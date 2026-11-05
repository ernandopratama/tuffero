<?php

// Data tampilan per SKU yang belum ada di database: kategori, jenis, nama Inggris (OQ-16). Ulasan Google Maps diambil 2026-11-03.
return [
    'categories' => ['nastar', 'macaron', 'pia', 'snack', 'collab'],
    'meta' => [
        'NAS-TOP-01' => [
            'cat' => 'nastar',
            'type' => 'sultan',
            'en' => 'Sultan Premium Pineapple Nastar',
        ],
        'NAS-CLAS-01' => [
            'cat' => 'nastar',
            'type' => 'classic',
            'en' => 'Classic Homemade Pineapple Nastar',
        ],
        'NAS-APEL-01' => [
            'cat' => 'nastar',
            'type' => 'apel',
            'en' => 'Premium Apple Nastar',
        ],
        'NAS-COO-01' => [
            'cat' => 'nastar',
            'type' => 'cookies',
            'en' => 'Nastar Cookies (Egg-Free)',
        ],
        'MAC-MIN-31' => [
            'cat' => 'macaron',
            'type' => 'mini',
            'en' => 'Mini Macarons, 30 pcs (6 Flavors)',
        ],
        'MAC-MIN-30' => [
            'cat' => 'macaron',
            'type' => 'mini',
            'en' => 'Mini Almond Macarons, 30 pcs',
        ],
        'MAC-MIN-50' => [
            'cat' => 'macaron',
            'type' => 'mini',
            'en' => 'Premium Mini Macarons, 50 pcs',
        ],
        'MAC-REG-06' => [
            'cat' => 'macaron',
            'type' => 'regular',
            'en' => 'Large Regular Macarons, 6 pcs',
        ],
        'PIA-OLH-02' => [
            'cat' => 'pia',
            'type' => 'pia',
            'en' => 'Premium Chocolate Pia',
        ],
        'CHO-DUB-01' => [
            'cat' => 'snack',
            'type' => 'dubai',
            'en' => 'Dubai Chocolate Kunafa Pistachio Brownies',
        ],
        'BRW-BOX-03' => [
            'cat' => 'snack',
            'type' => 'brownies',
            'en' => 'Marble Brownies Box',
        ],
        'MER-TOP-01' => [
            'cat' => 'snack',
            'type' => 'cookies',
            'en' => 'Meringue Cookies',
        ],
        'BUNDLING-NAS-02' => [
            'cat' => 'nastar',
            'type' => 'paket',
            'en' => 'Wisman + Classic Nastar Set',
        ],
        'SELAI-NANAS-01' => [
            'cat' => 'nastar',
            'type' => 'selai',
            'en' => 'Sultan Nastar Pineapple Jam',
        ],
        'MAC-PAK-01' => [
            'cat' => 'macaron',
            'type' => 'paket',
            'en' => '2 Mini + 1 Regular Macaron Boxes',
        ],
        'MAC-REM-01' => [
            'cat' => 'macaron',
            'type' => 'crumbs',
            'en' => 'Macaron Crumbs',
        ],
        'MAC-RICE-01' => [
            'cat' => 'macaron',
            'type' => 'rice',
            'en' => 'Chocolate Macaron Rice Crispy',
        ],
        'MAC-RICE-02' => [
            'cat' => 'macaron',
            'type' => 'rice',
            'en' => 'Strawberry Macaron Rice Crispy',
        ],
        'CHO-DUB-02' => [
            'cat' => 'snack',
            'type' => 'dubai',
            'en' => 'Dubai Chocolate 2-Pack',
        ],
        'COO-BIT-02' => [
            'cat' => 'snack',
            'type' => 'cookies',
            'en' => 'Choco Chips Cookies',
        ],
        'COO-LUM-01' => [
            'cat' => 'snack',
            'type' => 'cookies',
            'en' => 'Melting Chocopia',
        ],
        'TERASI-KING-01' => [
            'cat' => 'collab',
            'type' => 'terasi',
            'en' => 'Terasi Oven King, 1 Jar',
        ],
        'TERASI-KING-02' => [
            'cat' => 'collab',
            'type' => 'terasi',
            'en' => 'Terasi Oven King, 2 Jars',
        ],
        'TERASI-KING-03' => [
            'cat' => 'collab',
            'type' => 'terasi',
            'en' => 'Terasi Oven King, 3 Jars',
        ],
        'TERASI-SACHET-12' => [
            'cat' => 'collab',
            'type' => 'terasi',
            'en' => 'Terasi Oven King, 12 Sachets',
        ],
        'BUMBU-HTM-01' => [
            'cat' => 'collab',
            'type' => 'sambal',
            'en' => 'Black Spice Duck Sambal, 1 Pouch',
        ],
        'BUMBU-HTM-03' => [
            'cat' => 'collab',
            'type' => 'sambal',
            'en' => 'Black Spice Duck Sambal, 3 Pouches',
        ],
        'BUMBU-HTM-05' => [
            'cat' => 'collab',
            'type' => 'sambal',
            'en' => 'Black Spice Duck Sambal, 5 Pouches',
        ],
        'BUMBU-HTM-02' => [
            'cat' => 'collab',
            'type' => 'paket',
            'en' => '2 Sambal + 1 Terasi Bundle',
        ],
    ],
    'families' => [[
        'label' => [
            'id' => 'Jenis nastar',
            'en' => 'Nastar type',
        ],
        'items' => [['NAS-CLAS-01', 'Classic', 'Classic'], ['NAS-TOP-01', 'Sultan', 'Sultan'], ['NAS-APEL-01', 'Apel', 'Apple'], ['NAS-COO-01', 'Cookies', 'Cookies']],
    ], [
        'label' => [
            'id' => 'Varian',
            'en' => 'Option',
        ],
        'items' => [['MAC-MIN-30', 'Isi 30 Almond', '30 pcs Almond'], ['MAC-MIN-31', 'Isi 30 (6 Rasa)', '30 pcs (6 Flavors)'], ['MAC-MIN-50', 'Isi 50 Premium', '50 pcs Premium']],
    ], [
        'label' => [
            'id' => 'Jumlah toples',
            'en' => 'Jars',
        ],
        'items' => [['TERASI-KING-01', '1 Toples', '1 Jar'], ['TERASI-KING-02', '2 Toples', '2 Jars'], ['TERASI-KING-03', '3 Toples', '3 Jars']],
    ], [
        'label' => [
            'id' => 'Jumlah pouch',
            'en' => 'Pouches',
        ],
        'items' => [['BUMBU-HTM-01', '1 Pouch', '1 Pouch'], ['BUMBU-HTM-03', '3 Pouch', '3 Pouches'], ['BUMBU-HTM-05', '5 Pouch', '5 Pouches']],
    ], [
        'label' => [
            'id' => 'Paket',
            'en' => 'Set',
        ],
        'items' => [['CHO-DUB-01', 'Satuan', 'Single'], ['CHO-DUB-02', 'Paket 2', '2-Pack']],
    ]],
    'google_rating' => 4.6,
    'google_review_count' => 28,
    'google_reviews' => [[
        'name' => 'iis isnawati',
        'text' => 'Enak bangettt… kualitasnya sesuai sama harganya. Browniesnya juga ga eneg, manisnya pas, lembutnya pas',
    ], [
        'name' => 'Resiani Resi',
        'text' => 'Baby macaronnya enak,anakku suka bgtt..klo aku paling suka nastarnya enak bgttt pecah dimulut lembut n isiannya full',
    ], [
        'name' => 'Tsabita Zacky',
        'text' => 'Mantab!! Kualitas rasanya super banget, packing juga aman, cocok banget buat stock di rumah, di kantor juga buat anak2 ku',
    ], [
        'name' => 'Luluatul Hamidatu Ulya',
        'text' => 'Macaron terfavorit bangeeettt... Manisnya pas, crunchynya pas, harganya pas, bisa nemu jajanan-jajanan lucu nan enak lainnya juga di sini, ada rocher, cookies, dll. Dijamin ndak bakalan kecewa dehhh...',
    ], [
        'name' => 'Heny Arn',
        'text' => 'Baru pertama kali nyobain macaron eh langsung jatuh cinta sama nih macaron tuffero. Karena manisnya yang pas, ga bikin eneg, lembut juga pas dimakan. ada berbagai macam varian rasa yang bisa dicoba nih, favorit aku yang rasa lemon.',
    ]],
    'answer_links' => [
        'order' => [[
            'key' => 'products',
            'href' => '/produk',
        ]],
        'info' => [[
            'key' => 'products',
            'href' => '/produk',
        ]],
        'hampers' => [[
            'key' => 'hampers',
            'href' => '/layanan#hampers',
        ], [
            'key' => 'whatsapp',
            'external' => true,
        ]],
        'shipping' => [[
            'key' => 'track',
            'href' => '/cek-pesanan',
        ]],
        'human' => [[
            'key' => 'whatsapp',
            'external' => true,
        ]],
        'unsent' => [[
            'key' => 'whatsapp',
            'external' => true,
        ]],
    ],
    'quick_replies' => [[
        'key' => 'order',
        'icon' => 'bag',
    ], [
        'key' => 'info',
        'icon' => 'info',
    ], [
        'key' => 'hampers',
        'icon' => 'gift',
    ], [
        'key' => 'shipping',
        'icon' => 'truck',
    ], [
        'key' => 'human',
        'icon' => 'headset',
    ]],
];
