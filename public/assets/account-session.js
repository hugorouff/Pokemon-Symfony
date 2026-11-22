import {api} from './ui.js';

let session;
export function accountState(refresh = false) {
    if (refresh) session = undefined;
    return session ??= api('/api/rom-access', {}, {cache: 'no-store'});
}

export function accountRequest(path, query, method, token, data) {
    const headers = {'X-CSRF-Token': token};
    const options = {method, headers, cache: 'no-store'};
    if (data instanceof FormData) {
        options.body = data;
    } else if (data !== undefined) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(data);
    }
    return api(path, query, options);
}
