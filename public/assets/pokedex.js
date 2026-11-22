import {button, fmt, node} from './ui.js';

const source = 'https://cdn.jsdelivr.net/gh/Pokemon-3D-api/assets@429de1288cea0d43f5b4f56305d2276e94239d65/models/opt/';
const favoriteKey = 'pokemon-dex-favorites-v1';
let favorites = new Set();
try {
    const stored = JSON.parse(localStorage.getItem(favoriteKey) || '[]');
    if (Array.isArray(stored)) favorites = new Set(stored.filter(Number.isInteger));
} catch {}

export const dexFavorites = () => [...favorites];

export function favoriteControl(entry) {
    const control = button('', () => {
        if (favorites.has(entry.id)) favorites.delete(entry.id);
        else if (favorites.size < 300) favorites.add(entry.id);
        try { localStorage.setItem(favoriteKey, JSON.stringify(dexFavorites())); } catch {}
        render();
    });
    function render() {
        control.textContent = favorites.has(entry.id) ? '★ Favori' : '☆ Favori';
        control.setAttribute('aria-label', 'Favori : ' + entry.label);
        control.setAttribute('aria-pressed', String(favorites.has(entry.id)));
    }
    render();
    return control;
}

export function pokemonViewer(entry) {
    const wrap = node('section', 'dex-viewer');
    const modes = node('div', 'dex-view-modes');
    modes.setAttribute('role', 'group');
    modes.setAttribute('aria-label', 'Vue du Pokémon');
    const stage = node('div', 'dex-model');
    const art = node('img');
    art.alt = entry.label;
    let artworkAvailable = true;
    art.src = entry.artwork;
    art.addEventListener('error', () => {
        if (art.src !== entry.sprite) art.src = entry.sprite;
        else {
            artworkAvailable = false;
            art.hidden = true;
            syncView();
        }
    });
    const info = node('p', 'model-caption');
    info.setAttribute('role', 'status');
    const actions = node('div', 'actions dex-view-actions');
    stage.append(art);
    wrap.append(modes, stage, actions, info);

    const views = [
        ['fixed', 'Vue 3D fixe'],
        ['rotate', 'Rotation 3D'],
        ['art', 'Illustration'],
    ];
    const controls = new Map();
    for (const [key, label] of views) {
        const control = button(label, () => chooseView(key));
        control.disabled = key !== 'art' && !entry.models;
        controls.set(key, control);
        modes.append(control);
    }

    const initialOrbit = '30deg 72deg auto';
    let model;
    let mode = entry.models ? 'fixed' : 'art';
    let variant = 'normal';
    let request;
    let failed = false;
    let presentation;
    let previousClip = -1;

    function resetCamera() {
        if (!model?.loaded) return;
        model.resetTurntableRotation();
        model.cameraOrbit = initialOrbit;
        model.fieldOfView = 'auto';
        model.jumpCameraToGoal();
    }

    function stopAnimation() {
        presentation?.cancel();
        presentation = undefined;
        if (model?.loaded) {
            model.pause();
            model.currentTime = 0;
        }
    }

    function syncView() {
        const shown = mode !== 'art' && model?.loaded && !failed
            && model.src === source + entry.models[variant];
        for (const [key, control] of controls) {
            control.setAttribute('aria-pressed', String(key === mode));
        }
        if (model) {
            model.hidden = mode === 'art';
            model.classList.toggle('model-loading', !shown);
            model.toggleAttribute('auto-rotate', Boolean(shown && mode === 'rotate'));
            model.toggleAttribute('camera-controls', Boolean(shown && mode === 'rotate'));
        }
        art.hidden = !artworkAvailable || Boolean(shown);
        reset.hidden = mode !== 'rotate' || !shown;
        if (shiny) shiny.hidden = mode === 'art';
        if (mode === 'art') {
            info.textContent = !artworkAvailable ? 'Illustration indisponible pour cette forme.'
                : entry.models ? 'Illustration du Pokémon.' : 'Illustration du Pokémon. Modèle 3D indisponible pour cette forme.';
        } else if (failed) {
            info.textContent = 'Modèle 3D indisponible. L’illustration est affichée.';
        } else if (!shown) {
            info.textContent = 'Chargement du modèle 3D…';
        } else {
            info.textContent = mode === 'fixed'
                ? 'Modèle immobile dans sa pose de départ.'
                : 'Rotation automatique. Fais glisser pour tourner ou utilise la molette pour zoomer.';
        }
    }

    async function ensureModel() {
        if (!entry.models) return;
        if (model) {
            const url = source + entry.models[variant];
            if (model.src !== url) {
                model.removeAttribute('animation-name');
                previousClip = -1;
                model.src = url;
            }
            return;
        }
        request ??= importViewer();
        await request;
    }

    async function importViewer() {
        try {
            if (!customElements.get('model-viewer')) {
                globalThis.ModelViewerElement = {dracoDecoderLocation: '/assets/3d/'};
            }
            await import('./3d/model-viewer.min.js');
            if (!wrap.isConnected) return;
            model = document.createElement('model-viewer');
            model.setAttribute('alt', 'Modèle 3D de ' + entry.label);
            model.setAttribute('loading', 'eager');
            model.setAttribute('touch-action', 'pan-y');
            model.setAttribute('interaction-prompt', 'none');
            model.setAttribute('shadow-intensity', '0.65');
            model.setAttribute('camera-orbit', initialOrbit);
            model.setAttribute('rotation-per-second', '20deg');
            model.addEventListener('load', () => {
                failed = false;
                resetCamera();
                syncView();
            });
            model.addEventListener('error', () => {
                failed = true;
                syncView();
            });
            model.src = source + entry.models[variant];
            stage.append(model);
            syncView();
        } catch {
            failed = true;
            syncView();
        }
    }

    function chooseView(next) {
        if (mode !== next) stopAnimation();
        mode = next;
        if (mode !== 'art') ensureModel();
        if (mode === 'fixed') resetCamera();
        syncView();
    }

    const reset = button('Recentrer', resetCamera);
    const play = button('Animation', () => {
        stopAnimation();
        const displayedModel = mode !== 'art' && model?.loaded && !failed
            && model.src === source + entry.models[variant];
        if (displayedModel && model.availableAnimations.length) {
            const tracks = model.availableAnimations;
            previousClip = (previousClip + 1) % tracks.length;
            model.animationName = tracks[previousClip];
            model.currentTime = 0;
            model.play({repetitions: 1});
            info.textContent = 'Animation du modèle 3D.';
            return;
        }

        const element = displayedModel ? model : art;
        if (element.hidden) {
            info.textContent = 'Aucun visuel disponible à animer dans cette vue.';
            return;
        }
        presentation = element.animate([
            {transform: 'translateY(0) scale(1)'},
            {transform: 'translateY(-8px) scale(1.04)', offset: .44},
            {transform: 'translateY(0) scale(1)'},
        ], {duration: 1050, easing: 'ease-in-out'});
        info.textContent = displayedModel ? 'Animation de présentation du modèle.' : 'Animation de présentation de l’illustration.';
    });
    actions.append(play, reset);
    const shiny = entry.models?.shiny ? button('Chromatique', () => {
        stopAnimation();
        variant = variant === 'normal' ? 'shiny' : 'normal';
        failed = false;
        shiny.setAttribute('aria-pressed', String(variant === 'shiny'));
        ensureModel();
        resetCamera();
        syncView();
    }) : null;
    if (shiny) {
        shiny.setAttribute('aria-pressed', 'false');
        actions.append(shiny);
    }

    if (entry.models) {
        const credit = node('a', 'model-attribution', 'Source des modèles');
        credit.href = 'https://github.com/Pokemon-3D-api/assets';
        credit.target = '_blank';
        credit.rel = 'noopener';
        if (entry.models.normal === 'za/448.glb') {
            credit.textContent = 'Mariokart07 · CC BY 4.0';
            credit.href = 'https://sketchfab.com/3d-models/mega-lucario-z-za-dfd8c9bbab904eafab576a2f73f5b20d';
        }
        wrap.append(credit);
    }
    syncView();
    if (entry.models) queueMicrotask(ensureModel);
    return wrap;
}

export function statsPanel(data) {
    const wrap = node('div', 'dex-stats');
    if (data.stats?.length !== 6) return wrap;
    wrap.append(node('strong', '', 'Statistiques de base'));
    ['PV', 'Attaque', 'Défense', 'Attaque Spé.', 'Défense Spé.', 'Vitesse'].forEach((name, index) => {
        const value = data.stats[index];
        const row = node('div', 'dex-stat');
        const bar = node('meter');
        bar.max = 255;
        bar.value = value;
        bar.setAttribute('aria-label', name);
        row.append(node('span', '', name), bar, node('span', '', fmt.format(value)));
        wrap.append(row);
    });
    return wrap;
}
