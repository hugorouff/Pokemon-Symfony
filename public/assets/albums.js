import {getCollection} from './collection.js';
import {api, button, clean, collator, fmt, node} from './ui.js';

const PAGE_SIZE = 30;

export function startAlbums(showCards) {
    const $ = id => document.getElementById(id);
    const form = $('album-filters');
    const list = $('album-list');
    const filterButtons = $('album-views');
    const pager = $('album-pages');
    const panel = $('album-detail');
    const indices = new Map();
    let cards = [];
    let albums = [];
    let owned = new Set();
    let choice;
    let listRequest;
    let detailRequest;
    let mode = 'all';
    let page = 1;
    let albumOpener;

    function refreshOwned() {
        owned = new Set(cards.filter(row => row.lang === form.elements.lang.value).map(row => row.cardId));
    }

    function renderAlbums() {
        const match = clean(form.elements.q.value.trim());
        const view = form.elements.view.value;
        const visible = albums.map(album => ({
            ...album, count: album.ids.filter(id => owned.has(id)).length,
        })).filter(album => (!match || clean(album.name + ' ' + album.id).includes(match))
            && (view !== 'started' || album.count > 0)
            && (view !== 'complete' || album.count === album.ids.length));
        const frag = document.createDocumentFragment();
        for (const album of visible) {
            const box = node('article', 'panel album-tile');
            const title = node('h2', '', album.name);
            const progress = node('progress');
            progress.max = album.ids.length;
            progress.value = album.count;
            progress.setAttribute('aria-label', 'Progression de ' + album.name);
            const open = button('Ouvrir la série', () => {
                albumOpener = open;
                openAlbum(album);
            });
            open.setAttribute('aria-label', 'Ouvrir ' + album.name);
            open.dataset.album = album.id;
            box.append(title, progress, node('p', '', `${fmt.format(album.count)} / ${fmt.format(album.ids.length)} cartes`), open);
            frag.append(box);
        }
        list.replaceChildren(frag);
        $('status').classList.remove('error');
        $('status').textContent = visible.length ? `${fmt.format(visible.length)} série(s)` : 'Aucune série pour ce filtre.';
    }

    async function loadAlbums() {
        closeAlbum();
        listRequest?.abort();
        const request = new AbortController();
        listRequest = request;
        albums = [];
        list.replaceChildren();
        $('status').classList.remove('error');
        $('status').textContent = 'Chargement des séries…';
        try {
            const lang = form.elements.lang.value;
            if (!indices.has(lang)) {
                const {data} = await api('/api/albums', {lang}, {signal: request.signal});
                indices.set(lang, data.sort((a, b) => collator.compare(a.name, b.name)));
            }
            cards = await getCollection();
            if (request.signal.aborted) return;
            albums = indices.get(lang);
            refreshOwned();
            renderAlbums();
        } catch (error) {
            if (request.signal.aborted) return;
            $('status').classList.add('error');
            $('status').textContent = error.message;
        }
    }

    function renderDetail() {
        const {data, album} = choice;
        const ownedCount = album.ids.filter(id => owned.has(id)).length;
        const selected = data.filter(card => mode === 'all' || owned.has(card.id) === (mode === 'owned'));
        const last = Math.max(1, Math.ceil(selected.length / PAGE_SIZE));
        page = Math.min(page, last);
        filterButtons.replaceChildren(...[
            ['all', 'Toutes'], ['missing', 'Manquantes'], ['owned', 'Possédées'],
        ].map(([key, label]) => {
            const control = button(label, () => {
                mode = key;
                page = 1;
                renderDetail();
                filterButtons.querySelector('[aria-pressed="true"]')?.focus();
            }, key === mode ? 'primary' : 'secondary');
            control.setAttribute('aria-pressed', String(key === mode));
            return control;
        }));
        $('album-summary').textContent = `${fmt.format(ownedCount)} / ${fmt.format(album.ids.length)} possédées · ${fmt.format(selected.length)} affichables`;
        showCards(selected.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE), form.elements.lang.value, owned);
        const prev = button('← Précédente', () => { page--; renderDetail(); });
        const next = button('Suivante →', () => { page++; renderDetail(); });
        prev.disabled = page === 1;
        next.disabled = page === last;
        pager.replaceChildren(...(last > 1 ? [prev, node('span', '', `Page ${page} / ${last}`), next] : []));
    }

    async function openAlbum(album) {
        detailRequest?.abort();
        const request = new AbortController();
        detailRequest = request;
        choice = null;
        mode = 'all';
        page = 1;
        panel.hidden = false;
        $('album-name').textContent = album.name;
        $('album-summary').textContent = 'Chargement des cartes…';
        $('results').replaceChildren();
        pager.replaceChildren();
        filterButtons.replaceChildren();
        $('close-album').focus();
        try {
            const query = {lang: form.elements.lang.value, set: album.id, pageSize: 100};
            const first = await api('/api/cards', query, {signal: request.signal});
            const pages = await Promise.all(Array.from({length: Math.max(0, first.totalPages - 1)}, (_, i) =>
                api('/api/cards', {...query, page: i + 2}, {signal: request.signal})));
            if (request.signal.aborted) return;
            choice = {album, data: [first, ...pages].flatMap(result => result.data)};
            renderDetail();
        } catch (error) {
            if (!request.signal.aborted) $('album-summary').textContent = error.message;
        }
    }

    function closeAlbum() {
        detailRequest?.abort();
        panel.hidden = true;
        choice = null;
        albumOpener = null;
    }

    form.addEventListener('submit', event => event.preventDefault());
    form.elements.lang.addEventListener('change', loadAlbums);
    form.elements.q.addEventListener('input', renderAlbums);
    form.elements.view.addEventListener('change', renderAlbums);
    $('close-album').addEventListener('click', () => {
        albumOpener?.focus();
        closeAlbum();
    });
    document.addEventListener('collectionchange', async () => {
        try {
            cards = await getCollection();
            refreshOwned();
            const activeId = choice?.album.id;
            renderAlbums();
            if (activeId) {
                albumOpener = [...list.querySelectorAll('button')].find(control => control.dataset.album === activeId);
                renderDetail();
            }
        } catch (error) {
            $('status').classList.add('error');
            $('status').textContent = error.message;
        }
    });
    loadAlbums();
}
