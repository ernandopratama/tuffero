// Palet warna kategori. Kunci harus sama dengan ProductCategory::COLORS di backend.
// Kelas ditulis utuh supaya terbaca oleh Tailwind: soft = latar kepala, ink = teks (kontras tinggi), dot = penanda dan bingkai.
export const CATEGORY_COLORS = {
    gold: { label: 'Emas', soft: 'bg-[#F6EBCB]', ink: 'text-[#5E4308]', dot: 'bg-[#C6A15B]', ring: 'border-[#C6A15B]' },
    rose: { label: 'Mawar', soft: 'bg-[#FADDE0]', ink: 'text-[#7A1F2B]', dot: 'bg-[#D9566A]', ring: 'border-[#D9566A]' },
    orange: { label: 'Jingga', soft: 'bg-[#FCE3CF]', ink: 'text-[#7A3A08]', dot: 'bg-[#E8863A]', ring: 'border-[#E8863A]' },
    green: { label: 'Hijau', soft: 'bg-[#DCEFE0]', ink: 'text-[#1F5232]', dot: 'bg-[#4C9A62]', ring: 'border-[#4C9A62]' },
    teal: { label: 'Tosca', soft: 'bg-[#D3EEEC]', ink: 'text-[#145450]', dot: 'bg-[#2E9C94]', ring: 'border-[#2E9C94]' },
    blue: { label: 'Biru', soft: 'bg-[#DCEBF5]', ink: 'text-[#1B4F78]', dot: 'bg-[#3A84BD]', ring: 'border-[#3A84BD]' },
    indigo: { label: 'Nila', soft: 'bg-[#E0E3F7]', ink: 'text-[#2A3380]', dot: 'bg-[#5560C9]', ring: 'border-[#5560C9]' },
    purple: { label: 'Ungu', soft: 'bg-[#EADDF5]', ink: 'text-[#4B2377]', dot: 'bg-[#8A58C2]', ring: 'border-[#8A58C2]' },
    pink: { label: 'Merah muda', soft: 'bg-[#F9DBEC]', ink: 'text-[#7A1F57]', dot: 'bg-[#D45BA3]', ring: 'border-[#D45BA3]' },
    brown: { label: 'Cokelat', soft: 'bg-[#EADFD6]', ink: 'text-[#4A3226]', dot: 'bg-[#8A6450]', ring: 'border-[#8A6450]' },
};

export const COLOR_KEYS = Object.keys(CATEGORY_COLORS);

// Kategori tanpa warna (belum dipilih) jatuh ke palet berdasarkan urutannya.
export const categoryColor = (key, index = 0) => CATEGORY_COLORS[key] || CATEGORY_COLORS[COLOR_KEYS[index % COLOR_KEYS.length]];
