export function node(tag, className, label) {
    const el = document.createElement(tag);
    if (className) el.className = className;
    if (label !== undefined) el.textContent = label;
    return el;
}

export function button(label, action, style = 'secondary') {
    const el = node('button', style, label);
    el.type = 'button';
    el.addEventListener('click', action);
    return el;
}

export async function api(path, query = {}, options) {
    const response = await fetch(path + '?' + new URLSearchParams(query), options);
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(data.error || 'La requête a échoué. Réessayez.');
        error.code = data.code;
        error.status = response.status;
        throw error;
    }
    return data;
}

export const fmt = new Intl.NumberFormat('fr-FR');
export const collator = new Intl.Collator('fr', {sensitivity: 'base', numeric: true});
export const clean = value => value.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase();
