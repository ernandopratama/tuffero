// Entri halaman toko (Blade + Alpine, D-053). Area admin tetap memakai app.js.
// Build CSP: logika ada di modul ini, bukan di atribut HTML, supaya CSP kelak tidak perlu unsafe-eval (WEB-10).
import Alpine from '@alpinejs/csp';
import { loadLegacyScripts } from './legacy';
import { accountOrdersPage, accountPage, addressesPage } from './store/account';
import { authPage } from './store/auth';
import { cartButton, cartStore, initAddButtons } from './store/cart';
import { catalog, product } from './store/catalog';
import { chatWidget } from './store/chat';
import header from './store/header';
import bottomNav from './store/bottomNav';
import { initHome } from './store/home';
import { initNewsPopup } from './store/newsPopup';
import { contactPage, gallery, pinnedMap, reels } from './store/pages';
import { refundPage } from './store/refund';
import { cartPage, checkoutPage, lookupPage, orderPage } from './store/shop';
import { initSite, pageData, scrollToHash } from './store/site';
import { accountVouchersPage, vouchersPage } from './store/vouchers';
import { countdown } from './store/promo';
import { toastStore } from './store/toast';

const shared = pageData('tf-store-data');
Alpine.store('toast', toastStore(shared.toast));
Alpine.store('cart', cartStore(shared.cartCount, shared.cart, Alpine.store('toast')));
Object.entries({
    siteHeader: header, bottomNav, pinnedMap, contactPage, gallery, reels, catalog, product, cartPage, checkoutPage, orderPage, lookupPage, refundPage, authPage, accountPage, accountOrdersPage, addressesPage, chatWidget, vouchersPage, accountVouchersPage, countdown, cartButton,
}).forEach(([name, component]) => Alpine.data(name, component));

window.Alpine = Alpine;
Alpine.start();

initSite();
initAddButtons(Alpine.store('cart'));
initHome();
initNewsPopup();
// Skrip template mengubah tinggi bagian (SplitText, Swiper), jadi jangkar dihitung ulang setelahnya.
loadLegacyScripts().then(() => setTimeout(scrollToHash, 200));
