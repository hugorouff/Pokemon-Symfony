import {readFeature, writeFeature} from './player-features.js';
import {accountState} from './account-session.js';
import {button, node} from './ui.js';

const $ = id => document.getElementById(id);
const dataPath = 'https://cdn.emulatorjs.org/4.2.3/data/';
const tabs = $('rom-tabs');
const gamePanel = $('rom-game');
const romCache = 'atlas-roms-v1';
const chunkSize = 8 * 1024 * 1024;
let selectedGame;
let library = [];
let cloudStorage = false;
let cloudSaves = false;
let csrfToken = '';
let accountId;
let canSave = false;
let playingRom;
let selectedSaveRom;
let saveBusy = false;
const saveDialog = $('rom-saves');
const saveForm = $('save-import');
const dateFormat = new Intl.DateTimeFormat('fr-FR', {dateStyle: 'medium', timeStyle: 'short'});
let gameUrl;
let checkpointUrl;
let playingCore;
let busy = false;
const sizeFormat = new Intl.NumberFormat('fr-FR', {maximumFractionDigits: 1});

function status(message, error = false) {
    $('status').hidden = !message;
    $('status').textContent = message;
    $('status').classList.toggle('error', error);
}

function lock(value) {
    busy = value;
    for (const control of [...tabs.querySelectorAll('button'), ...gamePanel.querySelectorAll('button')]) control.disabled = value;
}

async function readJson(response) {
    const data = await response.json().catch(() => null);
    if (!response.ok || !data) throw new Error(data?.error || 'Le serveur n’a pas pu traiter la demande.');
    return data;
}

function sizeLabel(bytes) {
    if (bytes >= 1_000_000_000) return sizeFormat.format(bytes / 1_000_000_000) + ' Go';
    if (bytes >= 1_000_000) return sizeFormat.format(bytes / 1_000_000) + ' Mo';
    return sizeFormat.format(bytes / 1000) + ' Ko';
}

function downloadLink(text, url, label) {
    const link = node('a', 'secondary rom-download', text);
    link.href = url;
    link.setAttribute('aria-label', label);
    return link;
}

async function post(url, data) {
    return readJson(await fetch(url, {method: 'POST', headers: {'X-CSRF-Token': csrfToken}, body: data}));
}

async function upload(url, data, onProgress) {
    if (cloudSaves) {
        const romId = /^\/api\/roms\/([a-f0-9]{64})\/sauvegardes$/.exec(url)?.[1];
        return uploadToCloud(data.get('save'), romId, onProgress);
    }
    const response = await new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('X-CSRF-Token', csrfToken);
        xhr.responseType = 'json';
        xhr.upload.onprogress = event => {
            if (event.lengthComputable) onProgress(Math.round(event.loaded / event.total * 100));
        };
        xhr.onload = () => resolve({status: xhr.status, data: xhr.response});
        xhr.onerror = () => reject(new Error('Le transfert vers le serveur a été interrompu.'));
        xhr.send(data);
    });
    if (response.status !== 201 || !response.data) {
        throw new Error(response.data?.error || (response.status === 413
            ? 'Le fichier dépasse la limite d’envoi de l’hébergement.'
            : 'Le serveur n’a pas pu enregistrer le fichier.'));
    }
    return response.data;
}

async function uploadToCloud(file, romId, onProgress) {
    const request = new URLSearchParams({name: file.name, size: String(file.size)});
    if (romId) request.set('romId', romId);
    const {token, chunkSize, parts} = await post('/api/roms/transferts', request);
    const route = '/api/roms/transferts/' + token;
    let next = 1;
    let failed = false;
    const progress = new Array(parts).fill(0);
    async function transfer() {
        try {
            while (!failed && next <= parts) {
                const part = next++;
                const start = (part - 1) * chunkSize;
                const chunk = file.slice(start, Math.min(file.size, start + chunkSize));
                const {url} = await post(`${route}/morceaux/${part}`);
                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('PUT', url);
                    xhr.upload.onprogress = event => {
                        progress[part - 1] = event.loaded;
                        onProgress(Math.round(progress.reduce((sum, count) => sum + count, 0) / file.size * 100));
                    };
                    xhr.onload = () => xhr.status >= 200 && xhr.status < 300
                        ? resolve()
                        : reject(new Error('Le stockage en ligne a refusé un morceau.'));
                    xhr.onerror = () => reject(new Error('Le transfert a été interrompu. Vérifiez la connexion et les règles CORS du stockage.'));
                    xhr.send(chunk);
                });
                progress[part - 1] = chunk.size;
                onProgress(Math.round(progress.reduce((sum, count) => sum + count, 0) / file.size * 100));
            }
        } catch (error) {
            failed = true;
            throw error;
        }
    }

    try {
        const results = await Promise.allSettled([transfer(), transfer()]);
        const error = results.find(result => result.status === 'rejected');
        if (error) throw error.reason;
        return await post(route + '/terminer');
    } catch (error) {
        await post(route + '/annuler').catch(() => {});
        throw error;
    }
}

async function loadLibrary() {
    const {data, cloud, postgres, cloudSaves: uploadSavesToBucket} = await readJson(await fetch('/api/roms'));
    cloudStorage = cloud;
    cloudSaves = uploadSavesToBucket;
    library = data;
    $('rom-filters').hidden = !data.some(rom => rom.core === 'desmume');
    $('rom-totals').textContent = `${data.length} jeu(x) · ${sizeLabel(data.reduce((total, rom) => total + rom.size, 0))} de ROMs · ${postgres ? 'PostgreSQL et fichiers locaux' : cloudStorage ? 'Stockage en ligne' : 'Stockage local'}`;
    renderTabs();
}

function renderTabs() {
    tabs.replaceChildren(...library.map((rom, index) => {
        const tab = button(displayName(rom), () => selectGame(index), 'secondary');
        tab.id = 'rom-tab-' + index;
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', gamePanel.id);
        return tab;
    }));
    if (library.length === 0) {
        gamePanel.removeAttribute('role');
        gamePanel.removeAttribute('aria-labelledby');
        gamePanel.replaceChildren(node('p', '', 'Aucune ROM disponible.'));
        return;
    }
    selectGame(Math.max(0, library.findIndex(rom => rom.id === selectedGame)), false, false);
}

function displayName(rom) {
    return rom.name.replace(/\.[^.]+$/, '');
}

function selectGame(index, focus = false, remember = true) {
    const rom = library[index];
    if (remember) selectedGame = rom.id;
    for (const [position, tab] of [...tabs.children].entries()) {
        tab.setAttribute('aria-selected', String(position === index));
        tab.tabIndex = position === index ? 0 : -1;
        if (position === index && focus) tab.focus();
    }
    gamePanel.setAttribute('role', 'tabpanel');
    gamePanel.setAttribute('aria-labelledby', 'rom-tab-' + index);
    const labels = node('div', 'rom-meta');
    labels.append(node('strong', '', displayName(rom)),
        node('small', '', `${rom.system} · ${sizeLabel(rom.size)}${rom.core ? '' : ' · Téléchargement'}`));
    const actions = node('div', 'actions rom-actions');
    if (rom.core) actions.append(button('Jouer', () => openRom(rom), 'primary'));
    if (rom.core === 'desmume') {
        actions.append(button('Reprendre ma partie', () => resumeGame(rom)),
            button('Carnet d’aventure', () => showJournal(rom)));
    }
    actions.append(button('Sauvegardes', () => showSaves(rom)),
        downloadLink('Télécharger', '/api/roms/' + rom.id + '?download=1', 'Télécharger ' + rom.name));
    const row = node('div', 'panel rom-row');
    row.append(labels, actions);
    gamePanel.replaceChildren(row);
}

tabs.addEventListener('keydown', event => {
    if (busy || !library.length || !['Home', 'End', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
    const count = library.length;
    const active = [...tabs.children].findIndex(tab => tab.getAttribute('aria-selected') === 'true');
    const next = {Home: 0, End: count - 1, ArrowLeft: (active - 1 + count) % count, ArrowRight: (active + 1) % count};
    event.preventDefault();
    selectGame(next[event.key], true);
});

function applyAccess(data) {
    csrfToken = data.token;
    accountId = data.accountId;
    canSave = data.authenticated;
}

const access = accountState().then(applyAccess).catch(() => {});

function savesUrl(rom) {
    return '/api/roms/' + rom.id + '/sauvegardes';
}

async function loadSaves(rom) {
    const {data} = await readJson(await fetch(savesUrl(rom)));
    if (selectedSaveRom !== rom || !saveDialog.open) return;
    const rows = data.map(save => {
        const labels = node('div', 'rom-meta');
        labels.append(node('strong', '', save.name),
            node('small', '', dateFormat.format(new Date(save.modified * 1000)) + ' · ' + sizeLabel(save.size)));
        const row = node('li', 'panel rom-row');
        row.append(labels, downloadLink('Télécharger', savesUrl(rom) + '/' + save.id, 'Télécharger ' + save.name));
        return row;
    });
    $('save-list').replaceChildren(...rows);
    if (!rows.length) $('save-list').append(node('li', '', 'Aucune sauvegarde pour ce jeu.'));
}

async function showSaves(rom) {
    await access;
    if (!canSave) await accountState(true).then(applyAccess).catch(() => {});
    selectedSaveRom = rom;
    $('save-title').textContent = 'Sauvegardes · ' + rom.name;
    $('save-list').replaceChildren();
    $('save-status').textContent = '';
    saveForm.reset();
    $('save-account').hidden = canSave;
    saveForm.hidden = !canSave;
    if (!saveDialog.open) saveDialog.showModal();
    if (!canSave) return;
    loadSaves(rom).catch(error => { if (selectedSaveRom === rom) $('save-status').textContent = error.message; });
}

saveForm.addEventListener('submit', async event => {
    event.preventDefault();
    if (saveBusy || !canSave) return;
    const rom = selectedSaveRom;
    const payload = new FormData(saveForm);
    const submit = saveForm.querySelector('button');
    submit.disabled = saveBusy = true;
    try {
        await upload(savesUrl(rom), payload, percent => {
            if (selectedSaveRom === rom) $('save-status').textContent = `Import… ${percent} %`;
        });
        if (selectedSaveRom === rom) {
            saveForm.reset();
            $('save-status').textContent = 'Nouvelle version enregistrée.';
            await loadSaves(rom);
        }
    } catch (error) {
        if (selectedSaveRom === rom) $('save-status').textContent = error.message;
    } finally {
        submit.disabled = saveBusy = false;
    }
});

$('player-saves').addEventListener('click', () => { if (playingRom?.id) showSaves(playingRom); });

async function readStoredRom(rom) {
    try {
        const response = await fetch('/api/roms/' + rom.id, {cache: 'no-store'});
        if (!response.ok && response.status < 500) {
            await readJson(response);
        }
        if (response.status === 200) {
            const file = await response.blob();
            if (file.size === rom.size) return file;
        }
    } catch (error) {
        if (!(error instanceof TypeError)) throw error;
    }
    return readStoredRomInChunks(rom);
}

async function readStoredRomInChunks(rom) {
    const chunks = [];
    let lastModified;
    for (let start = 0; start < rom.size; start += chunkSize) {
        const end = Math.min(start + chunkSize, rom.size) - 1;
        const headers = {Range: `bytes=${start}-${end}`};
        if (lastModified && !cloudStorage) headers['If-Range'] = lastModified;
        const response = await fetch('/api/roms/' + rom.id, {headers, cache: 'no-store'});
        if (!response.ok) throw new Error('La ROM ne se trouve plus dans roms/ ou le serveur ne peut pas l’ouvrir.');
        if (response.status === 200 && start === 0) {
            const file = await response.blob();
            if (file.size === rom.size) return file;
            throw new Error('La ROM a changé ou le transfert est incomplet. Rechargez la bibliothèque.');
        }
        if (response.status !== 206 || response.headers.get('Content-Range') !== `bytes ${start}-${end}/${rom.size}`) {
            await response.body?.cancel();
            throw new Error('La ROM a changé ou le transfert est incomplet. Rechargez la bibliothèque.');
        }
        lastModified ??= response.headers.get('Last-Modified');
        const chunk = await response.blob();
        if (chunk.size !== end - start + 1) throw new Error('Le transfert de la ROM est incomplet.');
        chunks.push(chunk);
        status(`Chargement du jeu… ${Math.round((end + 1) * 100 / rom.size)} %`);
    }
    return new Blob(chunks, {type: 'application/octet-stream'});
}

async function readCachedRom(rom) {
    const prefix = `/api/roms/${rom.id}?cached=`;
    const key = prefix + rom.size + '-' + rom.modified;
    let cache;
    try {
        cache = await globalThis.caches?.open(romCache);
        const cached = await cache?.match(key);
        if (cached) {
            const file = await cached.blob();
            if (file.size === rom.size) return file;
            await cache.delete(key);
        }
    } catch {}
    const file = await readStoredRom(rom);
    if (cache) {
        try {
            await cache.put(key, new Response(file));
            const entries = await cache.keys();
            await Promise.all(entries.filter(entry => new URL(entry.url).pathname + new URL(entry.url).search !== key
                && entry.url.startsWith(location.origin + prefix)).map(entry => cache.delete(entry)));
        } catch {}
    }
    return file;
}

async function unpackDsZip(file) {
    // Use the central directory for lengths, including archives written with data descriptors.
    const tail = new DataView(await file.slice(-65557).arrayBuffer());
    let end = tail.byteLength - 22;
    while (end >= 0 && (tail.getUint32(end, true) !== 0x06054b50
        || end + 22 + tail.getUint16(end + 20, true) !== tail.byteLength)) end--;
    if (end < 0) throw new Error('Le fichier ZIP est incomplet.');
    const start = tail.getUint32(end + 16, true);
    const length = tail.getUint32(end + 12, true);
    const count = tail.getUint16(end + 10, true);
    if (tail.getUint16(end + 4, true) || tail.getUint16(end + 6, true)
        || length > 4 * 1024 * 1024 || start + length > file.size) {
        throw new Error('Le format de ce ZIP Nintendo DS n’est pas pris en charge.');
    }

    const entries = new DataView(await file.slice(start, start + length).arrayBuffer());
    const decoder = new TextDecoder();
    let game;
    let offset = 0;
    for (let index = 0; index < count; index++) {
        if (offset + 46 > length || entries.getUint32(offset, true) !== 0x02014b50) {
            throw new Error('L’index du fichier ZIP est invalide.');
        }
        const nameLength = entries.getUint16(offset + 28, true);
        const next = offset + 46 + nameLength + entries.getUint16(offset + 30, true) + entries.getUint16(offset + 32, true);
        if (next > length) throw new Error('L’index du fichier ZIP est incomplet.');
        const filename = decoder.decode(new Uint8Array(entries.buffer, offset + 46, nameLength));
        if (/\.nds$/i.test(filename) && !filename.startsWith('__MACOSX/') && !filename.split('/').pop().startsWith('._')) {
            if (game) throw new Error('Placez un seul fichier .nds par archive ZIP.');
            game = {
                flags: entries.getUint16(offset + 8, true),
                method: entries.getUint16(offset + 10, true),
                compressed: entries.getUint32(offset + 20, true),
                size: entries.getUint32(offset + 24, true),
                offset: entries.getUint32(offset + 42, true),
            };
        }
        offset = next;
    }
    if (!game || game.flags & 1 || ![0, 8].includes(game.method) || game.size < 512 || game.size > 512 * 1024 * 1024) {
        throw new Error('Le ZIP doit contenir une seule ROM Nintendo DS non chiffrée de 512 Mio maximum.');
    }
    const local = new DataView(await file.slice(game.offset, game.offset + 30).arrayBuffer());
    if (local.byteLength !== 30 || local.getUint32(0, true) !== 0x04034b50) throw new Error('La ROM est absente du ZIP.');
    const data = game.offset + 30 + local.getUint16(26, true) + local.getUint16(28, true);
    if (data + game.compressed > start) throw new Error('La ROM est incomplète dans le ZIP.');
    const packed = file.slice(data, data + game.compressed);
    if (game.method === 0 && packed.size === game.size) return packed;
    if (game.method === 0 || typeof DecompressionStream === 'undefined') throw new Error('Impossible de décompresser cette ROM.');
    const reader = packed.stream().pipeThrough(new DecompressionStream('deflate-raw')).getReader();
    const parts = [];
    let size = 0;
    while (true) {
        const {done, value} = await reader.read();
        if (done) break;
        size += value.byteLength;
        if (size > game.size) {
            await reader.cancel();
            throw new Error('La taille de la ROM décompressée est incorrecte.');
        }
        parts.push(value);
    }
    if (size !== game.size) throw new Error('La ROM décompressée est incomplète.');
    return new Blob(parts, {type: 'application/octet-stream'});
}

let resuming = false;
async function resumeGame(rom) {
    if (busy || resuming) return;
    resuming = true;
    try {
        await access;
        if (!accountId) await accountState(true).then(applyAccess);
        if (!accountId) {
            await showSaves(rom);
            return;
        }
        const {data} = await readJson(await fetch(savesUrl(rom), {cache: 'no-store'}));
        const checkpoint = data.find(save => save.core === 'desmume' || save.core === 'desmume2015');
        if (!checkpoint) {
            status('Aucun point de reprise. Lance le jeu puis clique sur « Sauvegarder en ligne ».');
            return;
        }
        await openRom(rom, checkpoint);
    } catch (error) {
        status(error.message, true);
    } finally {
        resuming = false;
    }
}

$('player-checkpoint').addEventListener('click', async event => {
    const control = event.currentTarget;
    control.disabled = true;
    try {
        const emulator = window.EJS_emulator;
        const core = emulator?.getCore?.() || playingCore;
        const data = emulator?.gameManager?.getState?.();
        if (!data?.byteLength || !['desmume', 'desmume2015'].includes(core)) {
            throw new Error('Le lecteur n’a pas encore de partie DS à sauvegarder.');
        }
        const file = new Blob([data], {type: 'application/octet-stream'});
        if (file.size > 16 * 1024 * 1024) throw new Error('Ce point de reprise dépasse 16 Mio.');
        const form = new FormData();
        form.append('save', file, 'reprise.state');
        form.append('checkpointCore', core);
        await upload(savesUrl(playingRom), form, () => {});
        status('Point de reprise enregistré en ligne.');
        if (saveDialog.open) await loadSaves(playingRom);
    } catch (error) {
        status(error.message, true);
    } finally {
        control.disabled = false;
    }
});

let activeJournal;
const adventureForm = $('adventure-form');
async function showJournal(rom) {
    await access;
    if (!accountId) await accountState(true).then(applyAccess).catch(() => {});
    $('adventure-title').textContent = 'Carnet d’aventure · ' + displayName(rom);
    $('adventure-status').textContent = '';
    $('adventure-login').hidden = !!accountId;
    adventureForm.hidden = true;
    activeJournal = null;
    if (!$('adventure-dialog').open) $('adventure-dialog').showModal();
    if (!accountId) return;
    const kind = 'journal-' + rom.id;
    try {
        const state = await readFeature(kind);
        activeJournal = {kind, state};
        const {badges = 0, team = '', goal = '', notes = ''} = state.data;
        Object.entries({badges, team, goal, notes}).forEach(([key, value]) => { adventureForm.elements[key].value = value; });
        adventureForm.hidden = false;
    } catch (error) {
        $('adventure-status').textContent = error.message;
    }
}
adventureForm.addEventListener('submit', async event => {
    event.preventDefault();
    const {kind, state} = activeJournal;
    const button = adventureForm.querySelector('button');
    button.disabled = true;
    try {
        const {badges, team, goal, notes} = Object.fromEntries(new FormData(adventureForm));
        const updated = await writeFeature(kind, state, {badges: Number(badges), team, goal, notes});
        activeJournal = {kind, state: updated};
        $('adventure-status').textContent = 'Carnet enregistré.';
    } catch (error) {
        $('adventure-status').textContent = error.message;
    } finally {
        button.disabled = false;
    }
});

const gameSurface = $('game');
const scrollKeys = new Set(['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight', ' ', 'Spacebar']);
const formControl = 'input, textarea, select, button, a[href], summary, [contenteditable]:not([contenteditable="false"]), [role="slider"]';
gameSurface.addEventListener('pointerdown', event => {
    if (playingRom && event.target instanceof Element && !event.target.closest(formControl)) {
        gameSurface.focus({preventScroll: true});
    }
}, {capture: true});
gameSurface.addEventListener('keydown', event => {
    if (!playingRom || $('player').hidden || !scrollKeys.has(event.key)) return;
    if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey
        || (event.target instanceof Element && event.target.closest(formControl))) return;
    // Keep propagation intact so EmulatorJS still receives the key itself.
    event.preventDefault();
}, {capture: true});

const preferencesButton = $('player-preferences');
const preferencesNotice = $('player-preferences-status');

async function restorePreferences(rom, core, gameId, emulatorName) {
    if (!accountId || rom.core !== 'desmume') return;
    try {
        const {data} = await readFeature('prefs-' + rom.id);
        const profile = data[core];
        if (!profile) return;
        // EmulatorJS 4.2.3 reads this key before loading its core and input mapping.
        const key = 'ejs-' + (gameId || 1) + '-nds-' + emulatorName + '-settings';
        const old = JSON.parse(localStorage.getItem(key) || '{}');
        localStorage.setItem(key, JSON.stringify({
            ...profile,
            settings: {...profile.settings, retroarch_core: core},
            cheats: Array.isArray(old.cheats) ? old.cheats : [],
        }));
        preferencesNotice.textContent = 'Réglages et commandes du compte chargés.';
    } catch {
        preferencesNotice.textContent = 'Les réglages en ligne n’ont pas pu être chargés. Le jeu utilise les réglages locaux.';
    }
}

preferencesButton.addEventListener('click', async () => {
    const emulator = window.EJS_emulator;
    const core = emulator?.getCore?.() || playingCore;
    if (!playingRom || !emulator?.settings || !emulator?.controls || !['desmume', 'desmume2015'].includes(core)) {
        preferencesNotice.textContent = 'Démarre le jeu avant de synchroniser les réglages.';
        return;
    }
    preferencesButton.disabled = true;
    try {
        const kind = 'prefs-' + playingRom.id;
        const previous = await readFeature(kind);
        await writeFeature(kind, previous, {
            ...previous.data,
            [core]: {settings: emulator.settings, controlSettings: emulator.controls},
        });
        preferencesNotice.textContent = 'Réglages et commandes enregistrés dans ton compte.';
    } catch (error) {
        preferencesNotice.textContent = error.message;
    } finally {
        preferencesButton.disabled = false;
    }
});

async function openRom(rom, checkpoint = null) {
    if (busy) return;
    lock(true);
    status('Chargement du jeu…');
    let gameId;
    try {
        if (checkpoint) {
            const saved = await fetch(savesUrl(rom) + '/' + checkpoint.id, {cache: 'no-store'});
            if (!saved.ok) throw new Error('Impossible de récupérer le point de reprise.');
            checkpointUrl = URL.createObjectURL(await saved.blob());
        }
        const stored = await readCachedRom(rom);
        const file = /\.zip$/i.test(rom.name) ? await unpackDsZip(stored) : stored;
        if (!file.size) throw new Error('Le fichier de la ROM est vide.');
        if (rom.core === 'desmume') {
            const header = new DataView(await file.slice(0, 512).arrayBuffer());
            if (header.byteLength < 512 || header.getUint32(0x20, true) < 0x200
                || header.getUint32(0x20, true) >= file.size) {
                throw new Error('Cette ROM Nintendo DS est invalide ou incomplète.');
            }
            gameId = header.getUint32(12, false);
        } else {
            gameId = Number.parseInt(rom.id.slice(0, 8), 16);
        }
        await access;
        if (accountId && globalThis.crypto?.subtle) {
            const identity = new TextEncoder().encode(accountId + ':' + gameId);
            gameId = new DataView(await crypto.subtle.digest('SHA-256', identity)).getUint32(0, false);
        }
        gameUrl = URL.createObjectURL(file);
    } catch (error) {
        const message = error instanceof TypeError
            ? 'Le chargement de la ROM a été interrompu.'
            : error.message;
        status(message, true);
        if (checkpointUrl) {
            URL.revokeObjectURL(checkpointUrl);
            checkpointUrl = undefined;
        }
        lock(false);
        return;
    }

    playingRom = rom;
    playingCore = checkpoint?.core || (rom.core === 'desmume' ? $('ds-core').value : rom.core);
    const pathName = rom.name.replace(/\.zip$/i, '.nds');
    const emulatorName = rom.core === 'desmume'
        ? pathName.replace(/(\.[^.]+)$/, '-' + playingCore + (accountId ? '-' + accountId : '') + '$1')
        : pathName;
    $('player-saves').hidden = !rom.id;
    $('current-game').textContent = rom.name;
    $('selection').hidden = true;
    $('player').hidden = false;
    status('Chargement de l’émulateur…');
    await restorePreferences(rom, playingCore, gameId, emulatorName);
    Object.assign(window, {
        EJS_player: '#game',
        EJS_core: playingCore,
        EJS_defaultOptions: {retroarch_core: playingCore},
        EJS_hideSettings: ['retroarch_core'],
        EJS_pathtodata: dataPath,
        EJS_gameUrl: gameUrl,
        EJS_gameName: emulatorName,
        EJS_loadStateURL: checkpointUrl,
        EJS_gameID: gameId,
        EJS_threads: false,
        EJS_startOnLoaded: !!checkpoint,
        EJS_startButtonName: 'Démarrer',
        EJS_onGameStart: () => {
            status('');
            gameSurface.tabIndex = 0;
            gameSurface.focus({preventScroll: true});
            $('player-checkpoint').hidden = !(accountId && rom.core === 'desmume');
            preferencesButton.hidden = !(accountId && rom.core === 'desmume');
        },
        EJS_ready: () => status(checkpoint ? 'Chargement du point de reprise…' : 'Cliquez sur « Démarrer » dans le lecteur.'),
    });

    const script = document.createElement('script');
    script.src = dataPath + 'loader.js';
    script.onerror = () => status('Le moteur d’émulation n’a pas pu se charger. Vérifiez votre connexion, puis rechargez la page.', true);
    document.head.append(script);
}

$('change-rom').addEventListener('click', () => {
    if (window.confirm('Revenir à la liste ? Pensez à sauvegarder votre partie avant de quitter.')) location.reload();
});
window.addEventListener('pagehide', event => {
    if (!event.persisted) for (const url of [gameUrl, checkpointUrl]) if (url) URL.revokeObjectURL(url);
});
loadLibrary().catch(error => {
    status(error instanceof TypeError ? 'Impossible de joindre le serveur de la bibliothèque.' : error.message, true);
});
