import { onBeforeUnmount, onMounted, unref } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';

/**
 * Peringatan bila halaman dengan isian yang belum disimpan ditinggalkan: pindah menu lewat router (konfirmasi) atau menutup
 * dan memuat ulang tab (dialog bawaan browser). `isDirty` boleh ref, computed, atau fungsi.
 * shortcut: konfirmasi router memakai window.confirm, bukan modal sendiri; ganti bila ada kebutuhan tampilan khusus.
 */
export function useUnsavedGuard(isDirty, message = 'Ada perubahan yang belum disimpan. Tinggalkan halaman ini?') {
    const dirty = () => (typeof isDirty === 'function' ? isDirty() : !!unref(isDirty));
    const warn = (event) => {
        if (!dirty()) return;
        event.preventDefault();
        event.returnValue = '';
    };

    onBeforeRouteLeave(() => (dirty() ? window.confirm(message) : true));
    onMounted(() => window.addEventListener('beforeunload', warn));
    onBeforeUnmount(() => window.removeEventListener('beforeunload', warn));
}
