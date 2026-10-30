import './bootstrap';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import router from './router';
import App from './App.vue';
import { useAuthStore } from './stores/auth';

const app = createApp(App);
const pinia = createPinia();

app.use(pinia);
app.use(router);

const authStore = useAuthStore(pinia);
app.config.globalProperties.$can = (permission) => authStore.can(permission);
app.config.globalProperties.$hasRole = (role) => authStore.hasRole(role);

app.mount('#app');
