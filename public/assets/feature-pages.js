import {accountState} from './account-session.js';
import {getCollection} from './collection.js';
import {readFeature, toggleWishlist, writeFeature} from './player-features.js';
import {api, button, node} from './ui.js';

const $ = id => document.getElementById(id);
const section = document.body.dataset.section;
const note = $('feature-status');
const selected = control => [...control.selectedOptions].map(option => option.value);
const cardOption = card => new Option([card.name || card.id, card.set].filter(Boolean).join(' · '), card.id);

function message(text, error = false) {
    note.textContent = text;
    note.classList.toggle('error', error);
}

function act(control, work) {
    control.disabled = true;
    return Promise.resolve().then(work).catch(error => message(error.message, true)).finally(() => { control.disabled = false; });
}

function progress(label, current, target) {
    const box = node('div', 'challenge-progress');
    const bar = node('progress');
    bar.max = target;
    bar.value = Math.min(current, target);
    bar.setAttribute('aria-label', label);
    box.append(node('strong', '', label), bar, node('p', '', `${current} / ${target}${current >= target ? ' · Défi réussi' : ''}`));
    return box;
}

async function objectives() {
    const [wishlist, collection, fr, en] = await Promise.all([
        readFeature('wishlist'), getCollection(), api('/api/albums', {lang: 'fr'}), api('/api/albums', {lang: 'en'})
    ]);
    const owned = new Set(collection.map(card => card.lang + ':' + card.cardId));
    function renderWishes(items) {
        $('wished-cards').replaceChildren(...items.map(card => {
            const li = node('li', 'workbench-row');
            const text = node('span', '', card.name + (card.set ? ' · ' + card.set : '') + (owned.has(card.id) ? ' · Obtenue' : ''));
            const remove = button('Retirer', () => act(remove, async () => renderWishes((await toggleWishlist(card)).data)));
            li.append(text, remove);
            return li;
        }));
        if (!items.length) $('wished-cards').append(node('li', '', 'Aucune carte recherchée.'));
    }
    renderWishes(wishlist.data);
    const water = new Set(collection.filter(card => card.types.some(type => /^(eau|water)$/i.test(type))).map(card => card.cardId));
    $('water-challenge').append(progress('Obtenir dix cartes Eau différentes', water.size, 10));
    const albums = [fr, en].flatMap(({data}, index) =>
        data.filter(album => album.ids.length).map(album => ({...album, lang: index ? 'en' : 'fr'})));
    const choose = $('challenge-album');
    choose.replaceChildren(...albums.map((album, index) => new Option(`${album.name} · ${album.lang.toUpperCase()}`, index)));
    function renderGoal() {
        const album = albums[Number(choose.value)];
        if (!album) return;
        const count = album.ids.filter(id => owned.has(album.lang + ':' + id)).length;
        $('album-challenge').replaceChildren(progress(album.name, count, Math.ceil(album.ids.length / 2)));
    }
    choose.addEventListener('change', renderGoal);
    renderGoal();
}

function renderShelf(cards) {
    $('display-shelf').replaceChildren(...cards.map(card => {
        const box = node('article', 'panel shelf-card');
        if (card.image) {
            const image = node('img');
            image.src = card.image;
            image.alt = '';
            image.loading = 'lazy';
            box.append(image);
        }
        box.append(node('strong', '', card.name), node('small', '', card.set || ''));
        return box;
    }));
}

async function shelfEditor() {
    const [collection, initial] = await Promise.all([getCollection(), readFeature('shelf')]);
    let previous = initial;
    const picker = $('shelf-picker');
    picker.replaceChildren(...collection.map(cardOption));
    for (const option of picker.options) option.selected = (previous.data.cards || []).includes(option.value);
    $('shelf-publish').checked = !!previous.data.published;
    function render() {
        const link = $('shelf-link');
        link.hidden = !previous.data.published || !previous.token;
        if (!link.hidden) {
            link.href = new URL('/classeur?partage=' + previous.token, location.origin).href;
            link.textContent = link.href;
        }
        renderShelf((previous.data.cards || []).flatMap(id => {
            const card = collection.find(entry => entry.id === id);
            return card ? [{...card, image: card.images.small}] : [];
        }));
    }
    $('save-shelf').addEventListener('click', event => act(event.currentTarget, async () => {
        const cards = selected(picker);
        if (cards.length > 9) throw new Error('Choisis neuf cartes au maximum.');
        previous = await writeFeature('shelf', previous, {cards, published: $('shelf-publish').checked});
        render();
        message(previous.data.published ? 'Classeur publié.' : 'Classeur privé enregistré.');
    }));
    render();
}

async function start() {
    const token = section === 'classeur' ? new URLSearchParams(location.search).get('partage') : null;
    if (token !== null) {
        const {data} = await api('/api/shelf/' + encodeURIComponent(token));
        renderShelf(data);
        if (!data.length) message('Ce classeur ne contient encore aucune carte.');
        return;
    }
    const state = await accountState();
    if (!state.accountId) {
        $('feature-login').hidden = false;
        return;
    }
    await {objectifs: objectives, classeur: shelfEditor}[section](state);
    $('feature-content').hidden = false;
}
start().catch(error => message(error.message, true));
