import {accountRequest, accountState} from './account-session.js';
import {api} from './ui.js';

export async function readFeature(kind) {
    return api('/api/player/' + kind, {}, {cache: 'no-store'});
}

export async function writeFeature(kind, previous, data) {
    const state = await accountState();
    return accountRequest('/api/player/' + kind, {}, 'PUT', state.token, {data, version: previous.version});
}

export async function editFeature(kind, edit) {
    const previous = await readFeature(kind);
    return writeFeature(kind, previous, edit(previous.data));
}

export async function readWishlist() {
    return (await accountState()).accountId ? (await readFeature('wishlist')).data : [];
}

export function toggleWishlist(card) {
    return editFeature('wishlist', items => items.some(item => item.id === card.id)
        ? items.filter(item => item.id !== card.id)
        : [...items, card]);
}
