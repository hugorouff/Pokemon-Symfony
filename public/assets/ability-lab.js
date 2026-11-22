import * as THREE from './3d/three.module.min.js';
import {createArena} from './ability-arena.js';
import {attackFrame, choreography} from './ability-choreography.js';

const featuredEffects = [
    {label: 'Aire de Feu', type: 'Feu', shape: 'pillars', color: 0xff492a, accent: 0xffc650, description: 'Des colonnes de flammes se propagent sur le terrain.'},
    {label: 'Hydroblast', type: 'Eau', shape: 'jet', color: 0x2996ff, accent: 0x87faff, description: 'Un jet d’eau sous pression se disperse en gouttes et en ondes.'},
    {label: 'Lance-Soleil', type: 'Plante', shape: 'beam', color: 0xfaff70, accent: 0x74fb7a, description: 'La lumière se concentre avant de traverser le terrain en un rayon.'},
    {label: 'Tonnerre', type: 'Électrik', shape: 'strike', color: 0xffde38, accent: 0xe6e7ff, description: 'Des arcs électriques jaillissent puis frappent le sol.'},
    {label: 'Ball’Ombre', type: 'Spectre', shape: 'burst', color: 0x9c51ff, accent: 0xec73dc, description: 'Une sphère d’ombre se condense puis se disperse à l’impact.'},
    {label: 'Capacité Z · Pyro-Explosion Cataclysmique', type: 'Feu', shape: 'burst', color: 0xff631c, accent: 0xffeb96, description: 'Un anneau canalise l’énergie avant l’impact d’une boule de feu.'},
    {label: 'Capacité Z · Supernova Originelle', type: 'Psy', shape: 'burst', color: 0xda45fc, accent: 0x7dc9ff, description: 'Une sphère psychique déclenche une expansion d’ondes lumineuses.'},
    {label: 'Gigamax', type: 'Transformation', shape: 'giant', color: 0xfb3e80, accent: 0x8b6cff, description: 'Des nuages tourbillonnent autour d’une silhouette qui grandit.'},
    {label: 'Fournaise G-Max', type: 'Feu', shape: 'pillars', color: 0xf92961, accent: 0xff9d47, description: 'Un tourbillon de feu encercle le point d’impact.'},
    {label: 'Méga-Évolution', type: 'Transformation', shape: 'mega', color: 0x48ffd4, accent: 0xf575ff, description: 'Des anneaux d’énergie se referment sur un cristal qui libère une onde.'},
    {label: 'Téracristallisation', type: 'Transformation', shape: 'crystals', color: 0x73f4ff, accent: 0xb78bff, description: 'Des prismes se dressent autour du champ et se couvrent d’éclats.'},
    {label: 'Téra Explosion', type: 'Capacité Téra', shape: 'beam', color: 0xb170ff, accent: 0x82f9ff, description: 'Un noyau cristallin émet un rayon et une pluie de fragments.'},
];

const palette = {
    Acier: [0x8bacc6, 0xf3faff], Combat: [0xca652f, 0xffcd80], Dragon: [0x4c70ff, 0xa078ff],
    Eau: [0x2988ff, 0x85eaff], Électrik: [0xffd03d, 0xffff9d], Fée: [0xff80c9, 0xffdbff],
    Feu: [0xff4c21, 0xffc84a], Glace: [0x52e7ff, 0xc0feff], Insecte: [0x99ce26, 0xe7f592],
    Normal: [0xadb5bc, 0xfffaf0], Plante: [0x48d86e, 0xd4ff5f], Poison: [0xc345d9, 0xf985be],
    Psy: [0xf54aaa, 0xffb5c6], Roche: [0xaa8260, 0xe6b370], Sol: [0xc67e3d, 0xf5bb75],
    Spectre: [0x8250e9, 0xcb8dff], Ténèbres: [0x634ab4, 0x965eec], Vol: [0x6ec9e5, 0xe6fbff],
};
const specials = new Map([[519, featuredEffects[0]], [308, featuredEffects[1]], [76, featuredEffects[2]], [85, featuredEffects[3]], [247, featuredEffects[4]], [640, featuredEffects[5]], [641, featuredEffects[5]], [703, featuredEffects[6]], [851, featuredEffects[11]], [920, {...featuredEffects[2], color:0xa5f3ff, accent:0xe3c8ff}]]);
const folded = value => value.normalize('NFD').toLowerCase().replace(/\p{Diacritic}/gu, '');

function moveEffect(record) {
    const [id, label, slug, type, damage, power, meta, category, accuracy, pp, target, hits, priority, flags] = record;
    const [color, accent] = palette[type] || palette.Normal;
    const bespoke = specials.get(id) || (label === 'Fournaise G-Max' ? featuredEffects[8] : null);
    const shape = damage === 0 ? (type === 'Feu' ? 'pillars' : type === 'Eau' ? 'jet' : 'burst')
        : damage === 1 ? (meta === 3 ? 'heal' : [10,11].includes(meta) ? 'field' : 'aura')
        : damage === 2 ? 'strike'
            : type === 'Eau' ? 'jet' : type === 'Électrik' ? 'strike'
                : ['Glace', 'Roche'].includes(type) ? 'crystals' : 'burst';
    const description = [
        bespoke?.description,
        ['Statut', 'Physique', 'Spéciale'][damage - 1],
        power && 'Puissance ' + power,
        accuracy && 'Précision ' + accuracy + ' %',
        pp && pp + ' PP',
    ].filter(Boolean).join(' · ');

    return {...{shape, color, accent}, ...bespoke, id, label, slug, type, category, power, description, target, hits, priority, flags, damage, meta};
}

export async function startLab() {
    const $ = id => document.getElementById(id);
    const panel = $('ability-lab');
    const stage = $('lab-stage');
    const select = $('lab-effect');
    const details = $('lab-description');
    const response = await fetch('/assets/3d/moves-fr.json?v=arena');
    if (!response.ok) throw new Error('Catalogue indisponible.');
    const catalog = (await response.json()).map(moveEffect);
    const transformations = [
        featuredEffects[7], featuredEffects[9], featuredEffects[10],
        {label:'Dynamax', type:'Transformation', shape:'giant', color:0x9234ff, accent:0xff6fb5, description:'Une énergie violette agrandit la silhouette et soulève des anneaux de poussière.'},
        {label:'Primo-Résurgence', type:'Transformation', shape:'field', color:0xffa443, accent:0x7dbaff, description:'Une onde élémentaire embrase le sol depuis le centre de la scène.'},
    ].map((effect, index) => ({
        ...effect, id: 'form-' + index, slug: '', category: 'Transformation', target: 7, hits: 1, priority: 0, flags: 0,
    }));
    const all = [...catalog.sort((a,b) => a.label.localeCompare(b.label,'fr')), ...transformations];
    const category = $('lab-category');
    const filterType = $('lab-type');
    const search = $('lab-search');
    filterType.append(...[...new Set(all.map(effect => effect.type))].sort((a,b) => a.localeCompare(b,'fr')).map(type => new Option(type,type)));
    let available = [];
    let initialized = false;
    function filter() {
        const q = folded(search.value.trim());
        available = all.filter(effect => (!category.value || effect.category === category.value)
            && (!filterType.value || effect.type === filterType.value)
            && (!q || folded(effect.label + ' ' + effect.slug + ' ' + effect.category).includes(q)));
        const previous = select.value;
        select.replaceChildren(...available.map(effect => new Option(effect.label, effect.id)));
        if (available.some(effect => String(effect.id) === previous)) select.value = previous;
        else if (!initialized) select.value = '519';
        initialized = true;
        select.disabled = available.length === 0;
        return available.find(effect => String(effect.id) === select.value);
    }
    const getCurrent = () => available.find(effect => String(effect.id) === select.value);
    filter();
    let renderer;
    try {
        renderer = new THREE.WebGLRenderer({alpha: true, antialias: true});
    } catch {
        const describe = () => {
            const effect = getCurrent();
            details.textContent = effect ? effect.description + ' · WebGL indisponible sur cet appareil.' : 'Aucune capacité pour ces filtres.';
        };
        [category, filterType].forEach(control => control.addEventListener('change', () => { filter(); describe(); }));
        search.addEventListener('input', () => { filter(); describe(); });
        select.addEventListener('change', describe);
        ['lab-play', 'lab-left', 'lab-right', 'lab-attacker', 'lab-target', 'lab-zoom', 'lab-audio', 'lab-cinematic'].forEach(id => { $(id).disabled = true; });
        describe();
        return;
    }

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(38, 1, 0.1, 80);
    const canvas = renderer.domElement;
    let yaw = 0.6;
    let drag;
    let animation;
    let started;
    let current;
    let plan;
    let zoom = 1;
    let announced = '';
    let frozenAt;
    let audio;
    const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)');
    const phaseLabel = $('lab-phase');
    const cinematic = $('lab-cinematic');
    const soundtrack = $('lab-audio');
    const grid = new THREE.GridHelper(19, 19, 0x337789, 0x1a394c);
    const disc = new THREE.Mesh(new THREE.CircleGeometry(7, 72), new THREE.MeshBasicMaterial({color:0x162936}));
    disc.rotation.x = -Math.PI / 2;
    disc.position.y = -.01;
    const foundation = new THREE.Mesh(
        new THREE.CylinderGeometry(7, 7.25, .42, 72),
        new THREE.MeshStandardMaterial({color:0x214252, roughness:.65, metalness:.35}),
    );
    foundation.position.y = -.24;
    const platform = new THREE.Mesh(new THREE.RingGeometry(6.7, 6.76, 72), new THREE.MeshBasicMaterial({color:0x438d9b, side:THREE.DoubleSide}));
    platform.rotation.x = -Math.PI / 2;
    scene.add(grid, disc, platform, foundation);
    const rim = new THREE.Mesh(new THREE.TorusGeometry(7.08, .022, 6, 72), new THREE.MeshBasicMaterial({color:0x2b596b}));
    rim.rotation.x = -Math.PI / 2;
    rim.position.y = -.2;
    scene.add(rim);
    const ambient = new THREE.HemisphereLight(0xc1efff, 0x655a96, 2.4);
    const light = new THREE.PointLight(0x7ff1ff, 57, 20);
    light.position.set(0, 3, 1.5);
    scene.add(ambient, light);
    const particles = new THREE.Group();
    scene.add(particles);
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, window.innerWidth < 600 ? 1.2 : 1.5));
    stage.append(canvas);
    const arena = createArena(scene, $('lab-attacker'), $('lab-target'), $('lab-arena-status'), () => rotate(0));

    const orbGeometry = new THREE.IcosahedronGeometry(1, 1);
    const shardGeometry = new THREE.OctahedronGeometry(1);
    const ringGeometry = new THREE.TorusGeometry(1, .035, 8, 40);
    const beamGeometry = new THREE.CylinderGeometry(.67, .67, 1, 14, 1, true);
    const materials = [new THREE.MeshBasicMaterial(), new THREE.MeshBasicMaterial(), new THREE.MeshBasicMaterial({
        transparent: true, opacity: .3, depthWrite: false, blending: THREE.AdditiveBlending, side: THREE.DoubleSide,
    })];
    function add(geometry, color = 0) {
        const mesh = new THREE.Mesh(geometry, materials[color]);
        particles.add(mesh);
        return mesh;
    }
    const sparks = Array.from({length: 145}, (_, i) => ({
        mesh: add(i % 5 ? orbGeometry : shardGeometry, i % 2),
        angle: i * 2.39996, speed: .4 + (i * 37 % 53) / 53, delay: (i * 17 % 89) / 140,
    }));
    const rings = Array.from({length: 6}, () => add(ringGeometry, 1));
    const core = new THREE.Mesh(shardGeometry, new THREE.MeshStandardMaterial({
        color: 0x6affdf, emissive: 0x5cebff, emissiveIntensity: .45, roughness: .27, metalness: .3,
    }));
    particles.add(core);
    const beam = add(beamGeometry, 2);
    const haloSurface = document.createElement('canvas');
    haloSurface.width = haloSurface.height = 64;
    const painter = haloSurface.getContext('2d');
    const gradient = painter.createRadialGradient(32, 32, 0, 32, 32, 32);
    gradient.addColorStop(0, '#fff');
    gradient.addColorStop(.22, '#ffffff8f');
    gradient.addColorStop(1, '#ffffff00');
    painter.fillStyle = gradient;
    painter.fillRect(0, 0, 64, 64);
    const halo = new THREE.Sprite(new THREE.SpriteMaterial({
        map: new THREE.CanvasTexture(haloSurface), blending: THREE.AdditiveBlending, depthWrite: false,
    }));
    particles.add(halo);
    const center = new THREE.Vector3(0, 1.15, 0);
    const strikeLight = new THREE.PointLight(0x74f3ff, 0, 12);
    scene.add(strikeLight);
    const originX = -3.65;
    const targetX = 3.65;

    function phase(name) {
        if (name !== announced) {
            announced = name;
            phaseLabel.textContent = name;
        }
    }

    function draw(time) {
        const width = Math.max(1, stage.clientWidth);
        const height = Math.max(1, canvas.clientHeight);
        if (canvas.width !== Math.floor(width * renderer.getPixelRatio()) || canvas.height !== Math.floor(height * renderer.getPixelRatio())) {
            renderer.setSize(width, height, false);
            camera.aspect = width / height;
            camera.updateProjectionMatrix();
        }
        const t = Math.min(time, plan.duration);
        const motion = attackFrame(plan, t);
        const {anticipation, approach, settle, reaction, age} = motion;
        const {mode: shape, self, ally, contact, field} = plan;
        const strength = Math.max(.64, Math.min(1.8, (current.power || 88) / 88));
        const impactX = self ? originX : field ? (current.target === 4 ? originX : current.target === 6 ? targetX : 0) : targetX;
        const flowX = THREE.MathUtils.lerp(originX, impactX, approach);
        const projectile = ['jet', 'beam', 'burst', 'pulse', 'sound', 'hex'].includes(shape);
        const travel = t >= plan.preparation && age < .4;
        const pulse = age >= 0 ? Math.max(0, 1 - age * 2.3) : 0;
        arena.pose(plan, motion, t);

        const framing = Math.max(1, 1.2 / camera.aspect);
        const activeCamera = cinematic.checked && !reduceMotion.matches;
        const swing = activeCamera ? Math.sin(t / plan.duration * Math.PI) * .25 : 0;
        const distance = (14.7 - (activeCamera ? anticipation * 1.6 + reaction * .55 : 0)) * framing / zoom;
        camera.position.set(Math.sin(yaw + swing) * distance, 6.1 * framing / zoom, Math.cos(yaw + swing) * distance);
        center.set(activeCamera ? (approach - .5) * .95 : 0, 1.14, 0);
        camera.lookAt(center);

        if (motion.done) phase('Séquence terminée');
        else if (t < plan.preparation) phase(plan.charge ? 'Charge' : 'Préparation');
        else if (age < 0) phase(self ? 'Activation' : contact ? 'Approche' : 'Lancement');
        else if (age < .48) phase(self ? 'Effet sur le lanceur' : ally ? 'Effet de soutien' : field ? 'Effet sur la zone' : 'Impact');
        else phase('Retour en position');

        strikeLight.color.setHex(age >= 0 ? current.accent : current.color);
        strikeLight.intensity = (anticipation * 13 + pulse * 64) * strength;
        strikeLight.position.set(age >= 0 ? impactX : originX, 2, 1);
        beam.visible = shape === 'beam' && travel && approach > .065;
        beam.position.set((originX + flowX) / 2, 1.42, 0);
        beam.rotation.z = Math.PI / 2;
        beam.scale.set(.42 + pulse * .52, Math.max(.1, Math.abs(flowX - originX)), .64);
        core.visible = (projectile || ['giant', 'mega', 'crystals', 'shield'].includes(shape)) && settle < .86;
        core.position.set(projectile ? flowX : impactX, shape === 'giant' ? 2.4 : 1.48, 0);
        core.rotation.set(t * .9, t * 1.7, t * .24);
        const scale = (.16 + anticipation * .35 + pulse * .53 + approach * .24) * strength * (1 - settle * .85);
        core.scale.set(scale, scale * (shape === 'giant' ? 2.1 : 1.2), scale);
        halo.visible = core.visible || beam.visible || pulse > 0;
        halo.position.set(pulse > .38 ? impactX : core.position.x, 1.43, .1);
        halo.scale.setScalar(1.2 + 4.5 * scale);
        halo.material.opacity = .19 + anticipation * .26 + pulse * .52;

        for (const [index, ring] of rings.entries()) {
            const offset = age - index * .095;
            ring.visible = offset > 0 && offset < .67;
            const vertical = projectile && !field;
            ring.rotation.set(vertical ? 0 : Math.PI / 2, vertical ? Math.PI / 2 : 0, 0);
            ring.position.set(impactX + (vertical ? offset * .86 : 0), vertical ? 1.27 : .14 + index * .11, 0);
            ring.scale.setScalar(.34 + Math.max(0, offset) * (field ? 5 : 3.3) * strength);
        }

        for (const [i, {mesh, angle, speed, delay}] of sparks.entries()) {
            const buildup = t < plan.preparation;
            const emission = t - plan.preparation - delay * .36;
            const impactAge = age - (i % 9) * .018;
            const local = Math.max(0, impactAge);
            const spread = .32 + i % 17 * .093;
            const orbit = angle + t * speed * (2 + plan.seed % 5 * .2);
            const focus = buildup ? originX : impactX;
            const size = (.04 + speed * .078) * strength;
            mesh.visible = width < 480 && i > 96 ? false : buildup ? i < 34 : emission >= 0 && (age < 0 || impactAge < .86);
            if (!mesh.visible) continue;
            let particleWidth = size;
            let height = size * 1.4;

            if (buildup) {
                const distance = spread * (1.7 - anticipation * .82);
                mesh.position.set(focus + Math.cos(orbit) * distance, 1.18 + Math.sin(angle) * distance * .7, Math.sin(orbit) * distance);
                particleWidth *= .8 + anticipation * .4;
            } else if (shape === 'pillars' || shape === 'field') {
                const radius = field ? 3.1 : 1.65;
                const climb = (emission * speed * 2.4 + i / 14) % 1;
                mesh.position.set(focus + Math.cos(angle) * spread * radius, .1 + (shape === 'pillars' ? climb * 3.7 : climb * .48), Math.sin(angle) * spread * radius);
                height *= shape === 'pillars' ? 1.4 + climb * 2.4 : .65;
            } else if (shape === 'crystals' || shape === 'shield' || shape === 'mega' || shape === 'giant' || shape === 'heal' || shape === 'aura') {
                const radius = spread * (shape === 'mega' ? Math.abs(1.9 - emission * 2.5) + .35 : 1.05 + pulse * .8);
                const cycle = (emission * speed + i / 12) % 1;
                mesh.position.set(focus + Math.cos(orbit) * radius, .24 + cycle * (shape === 'giant' ? 4 : 2.8), Math.sin(orbit) * radius);
                height *= shape === 'crystals' ? 3.5 : 1.8;
            } else if (projectile && age < 0) {
                const position = Math.max(0, approach - delay * .51);
                const widthAt = spread * (shape === 'beam' ? .36 : .77 + position * .29);
                const helix = shape === 'sound' || shape === 'pulse' ? orbit * 1.7 : orbit;
                mesh.position.set(THREE.MathUtils.lerp(originX, impactX, position), 1.44 + Math.sin(helix) * widthAt, Math.cos(helix) * widthAt);
                particleWidth *= shape === 'jet' ? 2.9 : 1.35;
            } else if (shape === 'strike' && !contact && current.type === 'Électrik' && age < .18) {
                const descent = 1 - Math.min(1, approach + i % 12 / 12);
                mesh.position.set(focus + Math.sin(angle + descent * 14) * .45, .25 + descent * 5.9, Math.cos(orbit) * spread * .44);
                height *= 2.7;
            } else {
                const reach = local * (projectile ? 4.4 : 3.1) * speed * strength;
                mesh.position.set(focus + Math.cos(angle) * reach, Math.max(.05, 1 + Math.sin(orbit) * reach * .52 - local * local * 2.4), Math.sin(angle) * reach);
                particleWidth *= Math.max(.15, 1 - local);
            }
            mesh.scale.set(particleWidth, height, size);
            mesh.rotation.set(orbit, orbit * .7, angle);
        }
        renderer.render(scene, camera);
    }

    function frame(now) {
        if (panel.hidden || !current) { animation = undefined; audio?.cancel(); return; }
        const elapsed = (now - started) / 1000;
        draw(elapsed);
        animation = elapsed < plan.duration ? requestAnimationFrame(frame) : undefined;
    }
    function replay(withSound = false, preview = false) {
        current = getCurrent();
        $('lab-play').disabled = !current;
        if (!current) {
            details.textContent = 'Aucune capacité pour ces filtres.';
            phase('');
            renderer.clear();
            return;
        }
        plan = choreography(current);
        const [attacker, target] = arena.names();
        const pairing = plan.self ? attacker : attacker + (plan.ally ? ' soutient ' : ' → ') + target;
        details.textContent = [pairing, current.category, current.type, current.description].filter(Boolean).join(' · ');
        materials[0].color.setHex(current.color);
        materials[1].color.setHex(current.accent);
        materials[2].color.setHex(current.color);
        core.material.color.setHex(current.color);
        core.material.emissive.setHex(current.accent);
        halo.material.color.setHex(current.color);
        if (audio) audio.cancel();
        if (withSound && soundtrack.checked) {
            audio ||= createAudio();
            audio?.play(plan);
        }
        started = performance.now();
        frozenAt = preview ? plan.impacts[0] + .08 : undefined;
        if (preview) {
            if (animation) cancelAnimationFrame(animation);
            animation = undefined;
            draw(frozenAt);
            return;
        }
        if (!animation) animation = requestAnimationFrame(frame);
    }
    function rotate(delta) {
        yaw += delta;
        if (!panel.hidden && current) draw(frozenAt ?? Math.max(0, (performance.now() - started) / 1000));
    }
    select.addEventListener('change', () => replay());
    [category, filterType].forEach(control => control.addEventListener('change', () => { filter(); replay(); }));
    search.addEventListener('input', () => { filter(); replay(); });
    $('lab-play').addEventListener('click', () => replay(true));
    $('lab-zoom').addEventListener('input', event => {
        zoom = Number(event.target.value);
        rotate(0);
    });
    cinematic.addEventListener('change', () => rotate(0));
    for (const field of [$('lab-attacker'), $('lab-target')]) {
        field.addEventListener('change', () => replay(false, true));
    }
    $('lab-left').addEventListener('click', () => rotate(-.45));
    $('lab-right').addEventListener('click', () => rotate(.45));
    canvas.addEventListener('pointerdown', event => {
        drag = event.clientX;
        canvas.setPointerCapture(event.pointerId);
    });
    canvas.addEventListener('pointermove', event => {
        if (drag === undefined) return;
        rotate((event.clientX - drag) * .012);
        drag = event.clientX;
    });
    canvas.addEventListener('lostpointercapture', () => { drag = undefined; });
    new ResizeObserver(() => { if (current) rotate(0); }).observe(stage);
    replay(false, reduceMotion.matches);
}

function createAudio() {
    const Context = window.AudioContext || window.webkitAudioContext;
    if (!Context) return null;
    const context = new Context();
    let notes = [];
    return {
        cancel() {
            notes.forEach(oscillator => { try { oscillator.stop(); } catch {} });
            notes = [];
        },
        play(plan) {
            context.resume().catch(() => {});
            const start = context.currentTime + .03;
            function tone(offset, from, to, duration, volume, type) {
                const at = start + offset;
                const wave = context.createOscillator();
                const gain = context.createGain();
                wave.type = type;
                wave.frequency.setValueAtTime(from, at);
                wave.frequency.exponentialRampToValueAtTime(to, at + duration);
                gain.gain.setValueAtTime(.001, at);
                gain.gain.linearRampToValueAtTime(volume, at + .035);
                gain.gain.exponentialRampToValueAtTime(.001, at + duration);
                wave.connect(gain).connect(context.destination);
                wave.start(at);
                wave.stop(at + duration);
                wave.onended = () => { wave.disconnect(); gain.disconnect(); };
                notes.push(wave);
            }
            const pitch = 95 + plan.seed % 213;
            tone(.03, pitch, pitch * (plan.charge ? 2.3 : 1.5), plan.preparation * .92, .037, 'sine');
            plan.impacts.forEach(impact => tone(impact, pitch * 1.6, 48 + plan.seed % 64, .24, .058, plan.self ? 'sine' : 'triangle'));
        },
    };
}
