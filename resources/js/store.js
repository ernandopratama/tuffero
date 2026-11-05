// Entri halaman toko (Blade + Alpine, D-053). Area admin tetap memakai app.js.
// Build CSP: logika ada di modul ini, bukan di atribut HTML, supaya CSP kelak tidak perlu unsafe-eval (WEB-10).
import Alpine from '@alpinejs/csp';
import { loadLegacyScripts } from './legacy';
import { accountOrdersPage, accountPage, addressesPage } from './store/account';
import { authPage } from './store/auth';
import { cartStore, initAddButtons } from './store/cart';
import { catalog, product } from './store/catalog';
import { chatWidget } from './store/chat';
import header from './store/header';
import { initHome } from './store/home';
import { contactPage, gallery, pinnedMap } from './store/pages';
import { cartPage, checkoutPage, lookupPage, orderPage } from './store/shop';
import { initSite, pageData, scrollToHash } from './store/site';

const shared = pageData('tf-store-data');
Alpine.store('cart', cartStore(shared.cartCount, shared.cart));
Object.entries({
    siteHeader: header, pinnedMap, contactPage, gallery, catalog, product, cartPage, checkoutPage, orderPage, lookupPage, authPage, accountPage, accountOrdersPage, addressesPage, chatWidget,
}).forEach(([name, component]) => Alpine.data(name, component));

window.Alpine = Alpine;
Alpine.start();

initSite();
initAddButtons(Alpine.store('cart'));
initHome();
// Skrip template mengubah tinggi bagian (SplitText, Swiper), jadi jangkar dihitung ulang setelahnya.
loadLegacyScripts().then(() => setTimeout(scrollToHash, 200));
