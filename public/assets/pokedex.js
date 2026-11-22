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
    const stage = node('div', 'dex-model');
    const art = node('img');
    art.alt = entry.label;
    art.src = entry.artwork;
    art.addEventListener('error', () => {
        if (art.src !== entry.sprite) art.src = entry.sprite;
        else art.hidden = true;
    });
    const info = node('p', 'model-caption');
    info.setAttribute('role', 'status');
    const actions = node('div', 'actions');
    stage.append(art);
    wrap.append(stage, actions, info);
    if (!entry.models) {
        info.textContent = 'Modèle 3D indisponible pour cette forme.';
        return wrap;
    }

    const credit = node('a', 'model-attribution', 'Source des modèles');
    credit.href = 'https://github.com/Pokemon-3D-api/assets';
    credit.target = '_blank';
    credit.rel = 'noopener';
    if (entry.models.normal === 'za/448.glb') {
        credit.textContent = 'Mariokart07 · CC BY 4.0';
        credit.href = 'https://sketchfab.com/3d-models/mega-lucario-z-za-dfd8c9bbab904eafab576a2f73f5b20d';
    }
    wrap.append(credit);
    let model;
    let variant = 'normal';
    async function display(next) {
        variant = next;
        info.textContent = 'Chargement du modèle 3D…';
        try {
            if (!customElements.get('model-viewer')) {
                globalThis.ModelViewerElement = {dracoDecoderLocation: '/assets/3d/'};
            }
            await import('./3d/model-viewer.min.js');
            if (!wrap.isConnected || next !== variant) return;
            if (model) {
                model.removeAttribute('animation-name');
                play.hidden = true;
                model.src = source + entry.models[next];
                return;
            }
            model = document.createElement('model-viewer');
            model.setAttribute('alt', 'Modèle 3D de ' + entry.label);
            model.setAttribute('camera-controls', '');
            model.setAttribute('loading', 'eager');
            model.setAttribute('touch-action', 'pan-y');
            model.setAttribute('interaction-prompt', 'none');
            model.setAttribute('shadow-intensity', '0.65');
            model.setAttribute('camera-orbit', '30deg 72deg auto');
            model.className = 'model-loading';
            model.addEventListener('load', () => {
                model.classList.remove('model-loading');
                art.hidden = true;
                play.hidden = !model.availableAnimations.length;
                info.textContent = 'Fais glisser pour tourner. Utilise la molette ou pince pour zoomer.';
            });
            model.addEventListener('error', () => {
                model.classList.add('model-loading');
                art.hidden = false;
                play.hidden = true;
                info.textContent = 'Modèle indisponible ou 3D désactivée sur cet appareil.';
            });
            model.src = source + entry.models[next];
            stage.append(model);
        } catch {
            info.textContent = 'La visionneuse 3D n’a pas pu démarrer.';
        }
    }
    const rotate = button('Rotation auto', () => {
        if (!model?.loaded) return;
        rotate.setAttribute('aria-pressed', String(model.toggleAttribute('auto-rotate')));
    });
    rotate.setAttribute('aria-pressed', 'false');
    const reset = button('Recentrer', () => {
        if (model) {
            model.cameraOrbit = '30deg 72deg auto';
            model.fieldOfView = 'auto';
            model.jumpCameraToGoal();
        }
    });
    const play = button('Animation', () => {
        if (!model?.availableAnimations.length) return;
        const tracks = model.availableAnimations;
        model.animationName = tracks[(tracks.indexOf(model.animationName) + 1) % tracks.length];
        model.currentTime = 0;
        model.play({repetitions: 1});
    });
    play.hidden = true;
    actions.append(rotate, reset, play);
    if (entry.models.shiny) {
        const shiny = button('Chromatique', () => {
            const next = variant === 'normal' ? 'shiny' : 'normal';
            shiny.setAttribute('aria-pressed', String(next === 'shiny'));
            display(next);
        });
        shiny.setAttribute('aria-pressed', 'false');
        actions.append(shiny);
    }
    queueMicrotask(() => display('normal'));
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
