// HTTP halaman toko ke /api/v1. Token CSRF dibaca dari cookie XSRF-TOKEN, sama seperti axios di area admin.
// Galat dilempar dengan bentuk { response: { status, data } } supaya pemeriksaan status di tiap halaman tetap sederhana.
const token = () => {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
};

const request = async (method, url, body, headers = {}) => {
    const response = await fetch(`/api/v1${url}`, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(body !== undefined ? { 'Content-Type': 'application/json' } : {}),
            ...(method !== 'GET' ? { 'X-XSRF-TOKEN': token() } : {}),
            ...headers,
        },
        body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const data = await response.json().catch(() => null);
    if (!response.ok) throw Object.assign(new Error(`HTTP ${response.status}`), { response: { status: response.status, data } });
    return { data };
};

export default {
    get: (url, headers) => request('GET', url, undefined, headers),
    post: (url, body = {}, headers) => request('POST', url, body, headers),
    patch: (url, body = {}) => request('PATCH', url, body),
    put: (url, body = {}) => request('PUT', url, body),
    delete: (url) => request('DELETE', url),
};
