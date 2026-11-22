import {accountState} from './account-session.js';
import {changeDeck, createDeck, DECK_CAPACITY, getCollection, getDecks, importLocalDecks} from './collection.js';
import {button, clean, collator, node} from './ui.js';

const LIBRARY_PAGE_SIZE = 25;

export function startDecks() {
    const $ = id => document.getElementById(id);
    const addForm = $('new-deck');
    const renameForm = $('rename-deck');
    let decks = [];
    let library = [];
    let active;
    let page = 1;
    let busy = false;

    function message(text, error = false) {
        $('status').textContent = text;
        $('status').classList.toggle('error', error);
    }

    async function refresh() {
        [decks, library] = await Promise.all([getDecks(), getCollection()]);
        library.sort((a, b) => collator.compare(a.name, b.name) || collator.compare(a.cardId, b.cardId));
        if (!decks.some(deck => deck.id === active)) active = decks[0]?.id;
        render();
        renderComparison();
    }

    async function operate(work, success) {
        if (busy) return;
        busy = true;
        try {
            await work();
            await refresh();
            if (success) message(success);
        } catch (error) {
            message(error?.message || 'Impossible d’enregistrer ce changement.', true);
        } finally {
            busy = false;
        }
    }

    function edit(change, success = '') {
        return operate(() => changeDeck(active, change), success);
    }

    function row(title, subtitle, controls) {
        const li = node('li', 'workbench-row');
        const labels = node('div', 'workbench-text');
        labels.append(node('strong', '', title), node('small', '', subtitle));
        li.append(labels, controls);
        return li;
    }

    function render() {
        const deck = decks.find(entry => entry.id === active);
        $('deck-layout').hidden = !deck;
        if (!deck) {
            message('Créez un deck pour commencer.');
            return;
        }
        $('deck-select').replaceChildren(...decks.map(item => new Option(item.name, item.id)));
        $('deck-select').value = active;
        renameForm.elements.name.value = deck.name;
        const total = deck.entries.reduce((count, item) => count + item.quantity, 0);
        const available = new Map(library.map(card => [card.id, card.quantity]));
        const assigned = new Map(deck.entries.map(entry => [entry.key, entry.quantity]));
        const unavailable = deck.entries.reduce((count, item) => count + Math.max(0, item.quantity - (available.get(item.key) || 0)), 0);
        $('deck-progress').value = total;
        $('deck-count').textContent = `${total} / ${DECK_CAPACITY} cartes` +
            (unavailable ? ` · ${unavailable} exemplaire(s) manquant(s)` : total === DECK_CAPACITY ? ' · Deck complet' : '');
        $('deck-export').disabled = total === 0;
        const entries = [...deck.entries].sort((a, b) => collator.compare(a.name, b.name));
        $('deck-entries').replaceChildren(...entries.map(entry => {
            const controls = node('div', 'actions deck-row-actions');
            const minus = button('−', () => edit({kind: 'remove', key: entry.key}, 'Carte retirée du deck.'));
            const plus = button('+', () => edit({kind: 'add', key: entry.key}, 'Carte ajoutée au deck.'));
            minus.setAttribute('aria-label', 'Retirer ' + entry.name);
            plus.setAttribute('aria-label', 'Ajouter ' + entry.name);
            plus.disabled = total >= DECK_CAPACITY || entry.quantity >= (available.get(entry.key) || 0);
            controls.append(minus, plus);
            return row(`${entry.quantity} × ${entry.name}`, `${entry.cardId} · ${entry.lang.toUpperCase()} · ${available.get(entry.key) || 0} en collection`, controls);
        }));
        if (!entries.length) $('deck-entries').append(node('li', '', 'Ce deck est vide. Ajoutez une carte de votre collection.'));
        renderLibrary(assigned, total);
    }

    function renderLibrary(assigned, total) {
        const match = clean($('deck-search').value.trim());
        const favorites = $('deck-favorites').checked;
        const filtered = library.filter(card => (!favorites || card.favorite) && (!match || clean(card.name + ' ' + card.cardId).includes(match)));
        const last = Math.max(1, Math.ceil(filtered.length / LIBRARY_PAGE_SIZE));
        page = Math.min(page, last);
        $('deck-library').replaceChildren(...filtered.slice((page - 1) * LIBRARY_PAGE_SIZE, page * LIBRARY_PAGE_SIZE).map(card => {
            const used = assigned.get(card.id) || 0;
            const add = button('Ajouter', () => edit({kind: 'add', key: card.id}, 'Carte ajoutée au deck.'));
            add.setAttribute('aria-label', 'Ajouter ' + card.name + ' depuis la collection');
            add.disabled = used >= card.quantity || total >= DECK_CAPACITY;
            return row(card.name, `${card.cardId} · ${card.lang.toUpperCase()} · ${used} / ${card.quantity} dans ce deck`, add);
        }));
        if (!filtered.length) $('deck-library').append(node('li', '', library.length ? 'Aucune carte pour ce filtre.' : 'Votre collection est vide.'));
        const prev = button('← Précédente', () => { page--; renderLibrary(assigned, total); });
        const next = button('Suivante →', () => { page++; renderLibrary(assigned, total); });
        prev.disabled = page === 1;
        next.disabled = page === last;
        $('deck-pages').replaceChildren(...(last > 1 ? [prev, node('span', '', `Page ${page} / ${last}`), next] : []));
    }

    const comparison = $('deck-comparison');
    const compared = [$('compare-first'), $('compare-second')];
    const targets = [$('compare-key-first'), $('compare-key-second')];
    function fillTargets() {
        compared.forEach((select, index) => {
            const selected = targets[index].value;
            const deck = decks.find(candidate => candidate.id === Number(select.value));
            targets[index].replaceChildren(...(deck?.entries || []).map(entry => new Option(entry.name, entry.key)));
            if ([...targets[index].options].some(option => option.value === selected)) targets[index].value = selected;
        });
        $('compare-results').replaceChildren();
    }
    function renderComparison() {
        comparison.hidden = decks.length < 2;
        if (comparison.hidden) return;
        compared.forEach((select, index) => {
            const selected = select.value;
            select.replaceChildren(...decks.map(deck => new Option(deck.name, deck.id)));
            select.value = decks.some(deck => String(deck.id) === selected) ? selected : String(decks[index].id);
        });
        fillTargets();
    }

    function sampleHand(entries) {
        const pool = [...entries];
        for (let i = 0; i < 7; i++) {
            const j = i + Math.floor(Math.random() * (pool.length - i));
            [pool[i], pool[j]] = [pool[j], pool[i]];
        }
        return pool.slice(0, 7);
    }

    compared.forEach(select => select.addEventListener('change', fillTargets));
    comparison.addEventListener('submit', event => {
        event.preventDefault();
        const selected = compared.map(select => decks.find(deck => deck.id === Number(select.value)));
        if (selected[0] === selected[1]) {
            $('compare-results').textContent = 'Choisis deux decks différents.';
            return;
        }
        $('compare-results').replaceChildren(...selected.map((deck, index) => {
            const panel = node('div', 'panel deck-panel');
            panel.append(node('h3', '', deck.name));
            const pool = deck.entries.flatMap(entry => Array(entry.quantity).fill(entry));
            if (pool.length < 7) {
                panel.append(node('p', '', 'Ajoute au moins 7 cartes pour simuler une main.'));
                return panel;
            }
            const key = targets[index].value;
            const sample = sampleHand(pool);
            let hits = 0;
            for (let i = 0; i < 1000; i++) if (sampleHand(pool).some(card => card.key === key)) hits++;
            const copies = pool.filter(card => card.key === key).length;
            let missed = 1;
            for (let i = 0; i < 7; i++) missed *= Math.max(0, pool.length - copies - i) / (pool.length - i);
            panel.append(node('p', '', `${pool.length} cartes dans le deck. Carte clé présente dans ${hits / 10} % des 1 000 mains simulées.`),
                node('p', '', `Probabilité théorique · ${((1 - missed) * 100).toFixed(1)} %`));
            const list = node('ul');
            list.append(...sample.map(card => node('li', '', card.name)));
            panel.append(list);
            return panel;
        }));
    });
    const migrate = $('deck-import-local');
    accountState().then(state => { migrate.hidden = !state.accountId; }).catch(() => {});
    migrate.addEventListener('click', () => operate(async () => {
        const amount = await importLocalDecks();
        message(amount ? `${amount} deck(s) importé(s).` : 'Aucun nouveau deck local.');
    }));

    addForm.addEventListener('submit', event => {
        event.preventDefault();
        operate(async () => {
            active = await createDeck(addForm.elements.name.value);
            page = 1;
            addForm.reset();
        }, 'Deck créé.');
    });
    renameForm.addEventListener('submit', event => {
        event.preventDefault();
        edit({kind: 'rename', name: renameForm.elements.name.value}, 'Deck renommé.');
    });
    $('deck-select').addEventListener('change', event => {
        active = Number(event.target.value);
        page = 1;
        render();
    });
    [$('deck-search'), $('deck-favorites')].forEach(input => input.addEventListener('input', () => {
        page = 1;
        const deck = decks.find(entry => entry.id === active);
        if (!deck) return;
        const assigned = new Map(deck.entries.map(entry => [entry.key, entry.quantity]));
        const total = deck.entries.reduce((count, item) => count + item.quantity, 0);
        renderLibrary(assigned, total);
    }));
    $('deck-delete').addEventListener('click', () => {
        const deck = decks.find(entry => entry.id === active);
        if (deck && window.confirm('Supprimer le deck « ' + deck.name + ' » ?')) edit({kind: 'delete'}, 'Deck supprimé.');
    });
    $('deck-export').addEventListener('click', () => {
        const deck = decks.find(entry => entry.id === active);
        if (!deck?.entries.length) return;
        const total = deck.entries.reduce((count, item) => count + item.quantity, 0);
        const lines = [deck.name, `${total} / ${DECK_CAPACITY} cartes`, ''];
        for (const entry of deck.entries) lines.push(`${entry.quantity} × ${entry.name} — ${entry.cardId} [${entry.lang.toUpperCase()}]`);
        const link = node('a');
        const url = URL.createObjectURL(new Blob([lines.join('\r\n') + '\r\n'], {type: 'text/plain;charset=UTF-8'}));
        link.href = url;
        link.download = 'deck-' + (clean(deck.name).replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || deck.id) + '.txt';
        link.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
    });
    refresh().catch(error => message(error?.message || 'Impossible de lire votre collection.', true));
}
