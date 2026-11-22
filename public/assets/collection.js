const requestResult = request => new Promise((resolve, reject) => {
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
});

let database;
function openDatabase() {
    if (!database) {
        const request = indexedDB.open('pokemonCollection', 3);
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('cards')) {
                db.createObjectStore('cards', {keyPath: 'id'});
            }
            if (!db.objectStoreNames.contains('limits')) {
                db.createObjectStore('limits', {keyPath: 'type'});
            }
            if (!db.objectStoreNames.contains('decks')) {
                db.createObjectStore('decks', {keyPath: 'id', autoIncrement: true});
            }
        };
        const opening = new Promise((resolve, reject) => {
            let blocked = false;
            request.onblocked = () => {
                blocked = true;
                reject(new Error('Fermez les autres onglets de l’ancienne application puis réessayez.'));
            };
            request.onerror = () => reject(request.error);
            request.onsuccess = () => {
                const db = request.result;
                if (blocked) {
                    db.close();
                    return;
                }
                db.onversionchange = () => {
                    db.close();
                    database = undefined;
                };
                resolve(db);
            };
        });
        database = opening.catch(error => {
            database = undefined;
            throw error;
        });
    }
    return database;
}

function safeImage(value) {
    try {
        const url = new URL(value);
        if (url.protocol === 'https:' && ['assets.tcgdex.net', 'images.pokemontcg.io'].includes(url.hostname)) {
            return url.href;
        }
    } catch {
        return null;
    }
    return null;
}

export function normalizeCard(card) {
    const lang = card.lang === 'fr' ? 'fr' : 'en';
    const id = card.cardId || card.id;
    return {
        id: card.cardId ? lang + ':' + id : id,
        cardId: id,
        lang,
        name: String(card.name || id).slice(0, 140),
        set: String(card.set?.name || card.set?.id || card.set || '').slice(0, 140),
        images: {small: safeImage(card.images?.small), large: safeImage(card.images?.large)},
        types: Array.isArray(card.types) ? card.types.filter(t => typeof t === 'string').slice(0, 8) : [],
        rarity: typeof card.rarity === 'string' ? card.rarity.slice(0, 100) : '',
        quantity: Number.isInteger(card.quantity) ? Math.max(1, Math.min(card.quantity, 9999)) : 1,
        favorite: card.favorite === true,
    };
}

export async function getCollection() {
    const db = await openDatabase();
    const cards = await requestResult(db.transaction('cards').objectStore('cards').getAll());
    return cards.map(card => {
        const normalized = normalizeCard(card);
        normalized.id = card.id; // Les cartes de la première version gardent leur clé.
        return normalized;
    });
}

const oldTypes = {feu: 'fire', eau: 'water', plante: 'grass', electrique: 'lightning', psy: 'psychic',
    combat: 'fighting', tenebres: 'darkness', incolore: 'colorless', random: 'random'};

function initialLimits(type) {
    const now = new Date();
    const date = [now.getFullYear(), now.getMonth() + 1, now.getDate()].join('-');
    const limit = type === 'random' ? 10 : 3;
    let remaining = limit;
    try {
        const saved = JSON.parse(localStorage.getItem('boosterDailyLimits'));
        const count = saved?.counts?.[oldTypes[type]];
        if (saved?.date === now.toLocaleDateString() && Number.isInteger(count)) {
            remaining = Math.max(0, Math.min(limit, count));
        }
    } catch {
        // Les anciens compteurs sont facultatifs.
    }
    return {type, date, remaining};
}

export async function remainingBoosters(type) {
    const db = await openDatabase();
    const initial = initialLimits(type);
    const previous = await requestResult(db.transaction('limits').objectStore('limits').get(type));
    return previous?.date === initial.date ? previous.remaining : initial.remaining;
}

export async function storeCards(cards, lang, mode, type) {
    const db = await openDatabase();
    const transaction = db.transaction(type ? ['cards', 'limits'] : ['cards'], 'readwrite');
    const store = transaction.objectStore('cards');
    const managed = cards.map(card => {
        const row = normalizeCard(lang ? {...card, cardId: card.id, lang} : card);
        if (mode === 'import' && !card.id.includes(':')) {
            row.id = card.id; // Un export de cartes de l’ancienne version peut contenir une clé brute.
        }
        return row;
    });
    let failure;
    let remaining;
    const completed = new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve(remaining);
        transaction.onabort = () => reject(failure || transaction.error || new Error('Enregistrement interrompu.'));
    });

    function write() {
        for (const card of managed) {
            const request = store.get(card.id);
            request.onsuccess = () => {
                const found = request.result ? normalizeCard(request.result) : null;
                const quantity = mode === 'draw'
                    ? Math.min(9999, (found?.quantity || 0) + card.quantity)
                    : Math.max(found?.quantity || 0, card.quantity);
                const images = card.images.small ? card.images : found?.images || card.images;
                store.put({...card, images, quantity, favorite: !!(found?.favorite || card.favorite),
                    rarity: card.rarity || found?.rarity || '', types: card.types.length ? card.types : found?.types || []});
            };
        }
    }

    if (type) {
        const limits = transaction.objectStore('limits');
        const initial = initialLimits(type);
        const request = limits.get(type);
        request.onsuccess = () => {
            const state = request.result?.date === initial.date ? request.result : initial;
            if (state.remaining <= 0) {
                failure = new Error('La limite quotidienne de ce booster est atteinte.');
                transaction.abort();
                return;
            }
            remaining = state.remaining - 1;
            limits.put({...state, remaining});
            write();
        };
    } else {
        write();
    }
    return completed;
}

export async function changeCard(id, callback) {
    const db = await openDatabase();
    const transaction = db.transaction('cards', 'readwrite');
    const store = transaction.objectStore('cards');
    const completed = new Promise((resolve, reject) => {
        transaction.oncomplete = resolve;
        transaction.onabort = () => reject(transaction.error);
    });
    const request = store.get(id);
    request.onsuccess = () => {
        if (!request.result) return;
        const card = callback(request.result);
        if (card) store.put(card);
        else store.delete(id);
    };
    return completed;
}

export async function importCollection(file) {
    if (file.size > 15_000_000) throw new Error('Le fichier dépasse 15 Mo.');
    const {cards} = JSON.parse(await file.text()) || {};
    if (!Array.isArray(cards) || cards.length > 40000 || cards.some(card => !card
        || typeof card.id !== 'string' || !/^(?:(?:fr|en):)?[a-z\d][a-z\d!%?._-]{0,99}$/i.test(card.id)
        || (card.cardId && (typeof card.cardId !== 'string' || !/^[a-z\d][a-z\d!%?._-]{0,99}$/i.test(card.cardId))))) {
        throw new Error('Ce fichier ne correspond pas à un export de collection.');
    }
    const unique = [...new Map(cards.map(card => [card.id, card])).values()];
    await storeCards(unique, null, 'import');
    return unique.length;
}


export const DECK_CAPACITY = 60;

export async function getDecks() {
    const db = await openDatabase();
    return requestResult(db.transaction('decks').objectStore('decks').getAll());
}

export async function createDeck(name) {
    const db = await openDatabase();
    const transaction = db.transaction('decks', 'readwrite');
    const request = transaction.objectStore('decks').add({name: deckName(name), entries: []});
    return new Promise((resolve, reject) => {
        transaction.oncomplete = () => resolve(request.result);
        transaction.onabort = () => reject(transaction.error);
    });
}

function deckName(name) {
    const value = name.trim().slice(0, 60);
    if (!value) throw new Error('Saisissez un nom de deck.');
    return value;
}

export async function changeDeck(id, change) {
    const db = await openDatabase();
    const transaction = db.transaction(['decks', 'cards'], 'readwrite');
    const decks = transaction.objectStore('decks');
    let failure;
    const completed = new Promise((resolve, reject) => {
        transaction.oncomplete = resolve;
        transaction.onabort = () => reject(failure || transaction.error || new Error('Modification interrompue.'));
    });
    function abort(error) {
        failure = error;
        transaction.abort();
    }
    const request = decks.get(id);
    request.onsuccess = () => {
        const deck = request.result;
        if (!deck) {
            abort(new Error('Ce deck n’existe plus.'));
            return;
        }
        if (change.kind === 'delete') {
            decks.delete(id);
            return;
        }
        if (change.kind === 'rename') {
            try {
                deck.name = deckName(change.name);
                decks.put(deck);
            } catch (error) {
                abort(error);
            }
            return;
        }
        const entry = deck.entries.find(row => row.key === change.key);
        if (change.kind === 'remove') {
            if (entry?.quantity > 1) entry.quantity--;
            else deck.entries = deck.entries.filter(row => row !== entry);
            decks.put(deck);
            return;
        }
        const cardRequest = transaction.objectStore('cards').get(change.key);
        cardRequest.onsuccess = () => {
            const card = cardRequest.result;
            if (!card || (entry?.quantity || 0) >= (card.quantity || 1)) {
                abort(new Error('Aucun exemplaire supplémentaire dans votre collection.'));
                return;
            }
            if (deck.entries.reduce((count, row) => count + row.quantity, 0) >= DECK_CAPACITY) {
                abort(new Error('Ce deck contient déjà 60 cartes.'));
                return;
            }
            if (entry) entry.quantity++;
            else {
                const normalized = normalizeCard(card);
                deck.entries.push({key: card.id, cardId: normalized.cardId, name: normalized.name,
                    lang: normalized.lang, set: normalized.set, quantity: 1});
            }
            decks.put(deck);
        };
    };
    return completed;
}
