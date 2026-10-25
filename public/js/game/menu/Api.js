/**
 * Appels JSON vers Laravel (équipe, Sac, PC...).
 * Renvoie { ok, status, data } ; data.message contient le texte d'erreur (422) à afficher.
 */
function csrf() {
    return document.querySelector('meta[name="csrf-token"]').content;
}

async function request(method, url, body) {
    try {
        const response = await fetch(url, {
            method,
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: body ? JSON.stringify(body) : undefined,
        });
        if (response.status === 419 || response.status === 401) {
            document.getElementById('session-expired').hidden = false;
        }
        const data = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, data };
    } catch (error) {
        return { ok: false, status: 0, data: { message: 'Connexion perdue...' } };
    }
}

export const api = {
    get: (url) => request('GET', url),
    post: (url, body) => request('POST', url, body),
};
