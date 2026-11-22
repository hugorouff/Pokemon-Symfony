import {accountState} from './account-session.js';
import {changeCard, drawBooster, getCollection, importCollection, importLocalCollection, remainingBoosters, storeCards} from './collection.js';
import {api, button, clean, collator, fmt, node} from './ui.js';

const section = document.body.dataset.section;
const results = document.querySelector('#results');
const status = document.querySelector('#status');
const filters = document.querySelector('#filters');
const dialog = document.querySelector('#detail');
const detail = document.querySelector('#detail-content');
let currentAudio;
let detailRequest;
let listRequest;
let setsRequest;
const params = () => Object.fromEntries(new FormData(filters));

function message(value, error = false) {
    status.textContent = value;
    status.classList.toggle('error', error);
}

function poster(card, large = false) {
    const source = large ? card.images?.large || card.images?.small : card.images?.small;
    const empty = () => node('div', 'card-placeholder', 'Visuel indisponible');
    if (!source) return empty();
    const img = node('img', 'card-visual');
    img.alt = '';
    img.loading = large ? 'eager' : 'lazy';
    img.src = source;
    img.addEventListener('error', () => {
        if (large && card.images.small && img.src !== card.images.small) img.src = card.images.small;
        else img.replaceWith(empty());
    });
    return img;
}

function audioControls(entry) {
    const wrap = node('div');
    const actions = node('div', 'card-buttons');
    const note = node('small', 'audio-error');
    note.setAttribute('role', 'status');
    function play(src, alternate) {
        currentAudio?.pause();
        const audio = new Audio(src);
        currentAudio = audio;
        note.textContent = '';
        audio.play().catch(async error => {
            if (currentAudio !== audio || error.name === 'AbortError') return;
            if (alternate) {
                play(alternate);
            } else {
                note.textContent = 'Audio indisponible. Vérifiez votre connexion.';
            }
        });
    }
    if (entry.cry) actions.append(button('▶ Cri', () => play(entry.cry, entry.legacy)));
    if (entry.legacy) actions.append(button('▶ Classique', () => play(entry.legacy)));
    if (!entry.cry && !entry.legacy) note.textContent = 'Cri indisponible pour cette forme.';
    wrap.append(actions, note);
    return wrap;
}

function sprite(entry) {
    const img = node('img', 'pokemon-sprite');
    img.alt = '';
    img.loading = 'lazy';
    img.src = entry.sprite;
    img.addEventListener('error', () => { img.style.visibility = 'hidden'; });
    return img;
}

function pokemonName(entry) {
    return entry.default ? entry.name : `${entry.name} · ${entry.slug}`;
}

function openDetail(content) {
    detailRequest?.abort();
    const request = new AbortController();
    detailRequest = request;
    detail.replaceChildren(content);
    if (!dialog.open) dialog.showModal();
    return request;
}

async function showPokemon(entry) {
    const copy = node('div', 'detail-copy');
    const layout = node('div', 'detail-grid');
    const metadata = data => data.types?.length ? `${data.types.join(' · ')} · ${fmt.format(data.height)} m · ${fmt.format(data.weight)} kg` : '';
    const extra = node('p', '', metadata(entry));
    const cries = node('div');
    cries.append(audioControls(entry));
    copy.append(node('small', '', 'N° ' + entry.number), node('h2', '', pokemonName(entry)), cries, extra);
    layout.append(sprite(entry), copy);
    const request = openDetail(layout);
    try {
        const data = await api('/api/pokemon/' + entry.id, {}, {signal: request.signal});
        if (data.types?.length) extra.textContent = metadata(data);
        if (data.cry !== entry.cry || data.legacy !== entry.legacy) cries.replaceChildren(audioControls(data));
    } catch (error) {
        if (error.name !== 'AbortError' && !extra.textContent) extra.textContent = 'Détails momentanément indisponibles.';
    }
}

async function showCard(card, lang) {
    const layout = node('div', 'detail-grid');
    const copy = node('div', 'detail-copy');
    const more = node('div', 'detail-copy');
    copy.append(node('small', '', card.set || card.id), node('h2', '', card.name), more);
    const actions = node('div', 'actions');
    if (section === 'cartes' || section === 'albums') {
        const add = button('Ajouter à ma collection', async () => {
            add.disabled = true;
            try {
                await storeCards([{...card, ...loaded}], lang, 'add');
                add.textContent = 'Ajoutée ✓';
                document.dispatchEvent(new Event('collectionchange'));
            } catch {
                add.textContent = 'Réessayer l’ajout';
                add.disabled = false;
            }
        }, 'primary');
        actions.append(add);
    }
    let loaded;
    copy.append(actions);
    layout.append(poster(card, true), copy);
    const request = openDetail(layout);
    async function load() {
        more.replaceChildren(node('p', '', 'Chargement de la fiche…'));
        try {
            loaded = await api('/api/card', {id: card.cardId || card.id, lang}, {signal: request.signal});
            const parts = [loaded.setName, loaded.rarity, (loaded.types || []).join(', '), loaded.hp ? loaded.hp + ' PV' : ''].filter(Boolean);
            more.replaceChildren();
            if (parts.length || loaded.category) more.append(node('p', '', parts.join(' · ') || loaded.category));
            if (loaded.partial) {
                more.append(node('p', '', 'Certains détails de cette carte sont momentanément indisponibles.'),
                    button('Réessayer la fiche', load));
            }
            if (loaded.description) more.append(node('p', '', loaded.description));
            if (loaded.attacks?.length) {
                const list = node('ul');
                for (const attack of loaded.attacks) {
                    list.append(node('li', '', [attack.name, attack.damage, attack.effect].filter(Boolean).join(' · ')));
                }
                more.append(node('h3', '', 'Attaques'), list);
            }
            for (const entry of loaded.pokemon || []) {
                const wrap = node('div');
                wrap.append(node('strong', '', entry.name), audioControls(entry));
                more.append(wrap);
            }
            if ((section === 'collection' || section === 'boosters') && (loaded.types?.length || loaded.rarity)) {
                const key = section === 'collection' ? card.id : lang + ':' + card.id;
                await changeCard(key, old => ({...old, types: loaded.types, rarity: loaded.rarity}));
            }
        } catch (error) {
            if (error.name !== 'AbortError') {
                more.replaceChildren(node('p', '', error.message), button('Réessayer la fiche', load));
            }
        }
    }
    load();
}

function cardsGrid(cards, lang, owned, decorate) {
    const fragment = document.createDocumentFragment();
    for (const card of cards) {
        const box = node('article', 'panel card');
        const title = node('span', 'card-caption');
        const subtitle = section === 'collection'
            ? `${card.set || card.cardId} · ×${card.quantity} · ${card.lang.toUpperCase()}`
            : `${card.set} · n° ${card.localId}`;
        title.append(node('strong', '', card.name), node('small', '', subtitle));
        const hit = button('', () => showCard(card, card.lang || lang), 'card-hit');
        hit.append(poster(card), title);
        box.append(hit);
        if (owned) {
            const label = owned.has(card.id) ? 'Possédée' : 'Manquante';
            box.append(node('small', owned.has(card.id) ? 'owned' : '', label));
        }
        if (decorate) decorate(box, card);
        if (section === 'collection') {
            const actions = node('div', 'card-buttons');
            const favorite = button(card.favorite ? '★ Favori' : '☆ Favori', async () => {
                await updateCollection(card.id, old => ({...old, favorite: !old.favorite}));
            });
            favorite.setAttribute('aria-pressed', String(card.favorite));
            const remove = button('− 1', async () => {
                await updateCollection(card.id, old => (old.quantity || 1) > 1 ? {...old, quantity: old.quantity - 1} : null);
            });
            remove.setAttribute('aria-label', 'Retirer un exemplaire de ' + card.name);
            actions.append(favorite, remove);
            box.append(actions);
        }
        fragment.append(box);
    }
    results.replaceChildren(fragment);
}

function dexGrid(entries) {
    const fragment = document.createDocumentFragment();
    for (const entry of entries) {
        const box = node('article', 'panel dex-item');
        const hit = button('', () => showPokemon(entry), 'dex-hit');
        const text = node('span', 'card-caption');
        text.append(node('small', '', 'N° ' + entry.number), node('strong', '', pokemonName(entry)));
        hit.append(sprite(entry), text);
        box.append(hit, audioControls(entry));
        fragment.append(box);
    }
    results.replaceChildren(fragment);
}

function paginate(data, load) {
    const pager = document.querySelector('#pager');
    if (data.totalPages < 2) {
        pager.replaceChildren();
        return;
    }
    const previous = button('← Précédente', () => load(data.page - 1));
    previous.disabled = data.page <= 1;
    const next = button('Suivante →', () => load(data.page + 1));
    next.disabled = data.page >= data.totalPages;
    const form = node('form');
    const label = node('label', '', 'Page');
    const input = node('input');
    input.type = 'number';
    input.min = 1;
    input.max = data.totalPages;
    input.value = data.page;
    const jump = node('button', 'secondary', 'Aller');
    label.append(input, node('span', '', 'sur ' + fmt.format(data.totalPages)));
    form.className = 'actions';
    form.append(label, jump);
    form.addEventListener('submit', event => {
        event.preventDefault();
        if (input.valueAsNumber >= 1 && input.valueAsNumber <= data.totalPages) load(input.valueAsNumber);
    });
    pager.replaceChildren(previous, form, next);
}

async function loadList(page = 1) {
    listRequest?.abort();
    const request = new AbortController();
    listRequest = request;
    const query = {...params(), page};
    const dex = section === 'pokedex';
    message('Chargement…');
    try {
        const path = dex ? '/api/pokemon' : '/api/cards';
        const data = await api(path, query, {signal: request.signal});
        if (dex) dexGrid(data.data);
        else cardsGrid(data.data, query.lang);
        paginate(data, loadList);
        message(data.totalCount ? `${fmt.format(data.totalCount)} ${dex ? 'entrées' : 'cartes'}` : 'Aucun résultat.');
    } catch (error) {
        if (error.name !== 'AbortError') {
            results.replaceChildren();
            document.querySelector('#pager').replaceChildren();
            message(error.message, true);
        }
    }
}

async function loadSets() {
    setsRequest?.abort();
    const request = new AbortController();
    setsRequest = request;
    const select = filters.elements.set;
    const defaultOption = new Option('Toutes les séries', '');
    select.replaceChildren(defaultOption);
    try {
        const {data} = await api('/api/sets', {lang: filters.elements.lang.value}, {signal: request.signal});
        data.sort((a, b) => collator.compare(a.name, b.name));
        select.append(...data.map(set => new Option(set.name, set.id)));
    } catch (error) {
        if (error.name !== 'AbortError') defaultOption.textContent = 'Séries indisponibles';
    }
}

function showBoosterCount(pack, count) {
    pack.dataset.remaining = count;
    pack.querySelector('[data-remaining]').textContent = count === 1
        ? '1 ouverture restante' : `${count} ouvertures restantes`;
}

async function openBooster(pack, packs) {
    const type = pack.dataset.booster;
    const query = {...params(), type: type === 'random' ? '' : type};
    const controls = [...filters.elements, ...packs];
    controls.forEach(control => { control.disabled = true; });
    message('Ouverture du booster…');
    status.focus();
    try {
        const available = await remainingBoosters(type);
        showBoosterCount(pack, available);
        if (available <= 0) throw new Error('La limite quotidienne de ce booster est atteinte.');
        const {data, remaining} = await drawBooster(query, type);
        showBoosterCount(pack, remaining);
        cardsGrid(data, query.lang);
        message(`5 cartes ajoutées à la collection. Ouvertures restantes aujourd’hui · ${remaining}`);
    } catch (error) {
        message(error.message || 'Impossible de sauvegarder le booster.', true);
    } finally {
        controls.forEach(control => { control.disabled = control.dataset.remaining === '0'; });
    }
}

async function updateCollection(id, callback) {
    try {
        await changeCard(id, callback);
        await showCollection();
    } catch (error) {
        message(error.message || 'Impossible de modifier la collection.', true);
    }
}

async function showCollection() {
    try {
        const all = await getCollection();
        const query = params();
        for (const [field, choices] of Object.entries({
            type: new Set(all.flatMap(card => card.types)),
            rarity: new Set(all.map(card => card.rarity).filter(Boolean)),
        })) {
            const select = filters.elements[field];
            select.replaceChildren(select.options[0], ...[...choices].sort(collator.compare).map(value => new Option(value, value)));
            select.value = query[field];
        }
        const cards = all.filter(c => (!query.q || clean(c.name + ' ' + c.cardId).includes(clean(query.q.trim())))
            && (query.view !== 'favorites' || c.favorite) && (query.view !== 'duplicates' || c.quantity > 1)
            && (!query.type || c.types.includes(query.type)) && (!query.rarity || c.rarity === query.rarity));
        cards.sort((a, b) => {
            if (query.sort === 'quantity') return b.quantity - a.quantity || collator.compare(a.name, b.name);
            if (query.sort === 'type') return collator.compare(a.types[0] || '', b.types[0] || '') || collator.compare(a.name, b.name);
            if (query.sort === 'rarity') return collator.compare(a.rarity, b.rarity) || collator.compare(a.name, b.name);
            return (query.sort === 'za' ? -1 : 1) * collator.compare(a.name, b.name);
        });
        cardsGrid(cards);
        message(!all.length ? 'Votre collection est vide. Ouvrez un booster ou ajoutez une carte au catalogue.' :
            `${fmt.format(cards.length)} carte(s) affichée(s) · ${fmt.format(all.reduce((sum, card) => sum + card.quantity, 0))} exemplaire(s) au total`);
    } catch (error) {
        message(error.message || 'Impossible de lire la collection enregistrée dans ce navigateur.', true);
    }
}

document.querySelector('#close-detail').addEventListener('click', () => dialog.close());
dialog.addEventListener('close', () => {
    detailRequest?.abort();
    currentAudio?.pause();
});

if (section === 'albums') import('./albums.js').then(({startAlbums}) => startAlbums(cardsGrid));
if (section === 'decks') import('./decks.js').then(({startDecks}) => startDecks());

if (section === 'cartes' || section === 'boosters') {
    loadSets();
    filters.elements.lang.addEventListener('change', () => {
        loadSets();
        if (section === 'cartes') loadList();
    });
}

if (section === 'cartes' || section === 'pokedex') {
    loadList();
    filters.addEventListener('submit', event => {
        event.preventDefault();
        loadList();
    });
}

if (section === 'boosters') {
    const packs = [...document.querySelectorAll('[data-booster]')];
    filters.addEventListener('submit', event => event.preventDefault());
    for (const pack of packs) {
        pack.disabled = true;
        pack.addEventListener('click', () => openBooster(pack, packs));
    }
    Promise.all(packs.map(pack => remainingBoosters(pack.dataset.booster))).then(counts => {
        packs.forEach((pack, index) => {
            showBoosterCount(pack, counts[index]);
            pack.disabled = counts[index] === 0;
        });
    }).catch(error => {
        for (const pack of packs) pack.querySelector('[data-remaining]').textContent = 'Indisponible';
        message(error.message || 'La collection locale est inaccessible dans ce navigateur.', true);
    });
}

if (section === 'collection') {
    const transfer = document.querySelector('#import-local-collection');
    accountState().then(state => {
        transfer.hidden = !state.accountId;
        document.querySelector('#collection-guest').hidden = !!state.accountId;
    }).catch(() => {});
    transfer.addEventListener('click', async () => {
        transfer.disabled = true;
        try {
            const count = await importLocalCollection();
            await showCollection();
            message(count ? `${count} carte(s) locale(s) importée(s).` : 'Aucune carte locale à importer.');
        } catch (error) {
            message(error.message, true);
        } finally {
            transfer.disabled = false;
        }
    });

    showCollection();
    filters.addEventListener('submit', event => {
        event.preventDefault();
        showCollection();
    });
    document.querySelector('#export').addEventListener('click', async () => {
        try {
            const cards = await getCollection();
            const url = URL.createObjectURL(new Blob([JSON.stringify({version: 1, cards}, null, 2)], {type: 'application/json'}));
            const link = node('a');
            link.href = url;
            link.download = 'collection-pokemon.json';
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch {
            message('L’export a échoué.', true);
        }
    });
    document.querySelector('#import').addEventListener('change', async event => {
        const file = event.target.files[0];
        if (!file) return;
        try {
            await importCollection(file);
            await showCollection();
        } catch (error) {
            message(error.message || 'L’import a échoué.', true);
        } finally {
            event.target.value = '';
        }
    });
}
