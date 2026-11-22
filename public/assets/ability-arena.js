import * as THREE from './3d/three.module.min.js';
import {GLTFLoader} from './3d/GLTFLoader.js';
import {DRACOLoader} from './3d/DRACOLoader.js';
import {clone} from './3d/SkeletonUtils.js';

const root = '/assets/3d/arena-models/';
const roster = new Map([
    ['448', 'Lucario'], ['384', 'Rayquaza'], ['643', 'Reshiram'],
    ['6', 'Dracaufeu'], ['9', 'Tortank'], ['3', 'Florizarre'],
]);
const mega = {'448': 'lucario-mega', '384': 'rayquaza-mega'};
const draco = new DRACOLoader().setDecoderPath('/assets/3d/').setWorkerLimit(2);
const loader = new GLTFLoader().setDRACOLoader(draco);
const cached = new Map();

function model(id) {
    if (!cached.has(id)) cached.set(id, loader.loadAsync(root + id + '.glb').catch(error => {
        cached.delete(id);
        throw error;
    }));
    return cached.get(id);
}

function fighter(x, color) {
    const body = new THREE.Group();
    const rig = new THREE.Group();
    const marker = new THREE.Mesh(
        new THREE.RingGeometry(.8, .86, 48),
        new THREE.MeshBasicMaterial({color, side: THREE.DoubleSide, transparent: true, opacity: .73}),
    );
    marker.rotation.x = -Math.PI / 2;
    marker.position.y = .025;
    body.position.x = x;
    body.add(rig, marker);
    return {body, rig, marker, base: x};
}

function normalizedModel(gltf) {
    const copy = clone(gltf.scene);
    const bounds = new THREE.Box3().setFromObject(copy);
    const size = bounds.getSize(new THREE.Vector3());
    const mid = bounds.getCenter(new THREE.Vector3());
    const fit = Math.min(2.7 / size.y, 3.5 / size.x, 3.5 / size.z);
    copy.position.sub(new THREE.Vector3(mid.x, bounds.min.y, mid.z));
    const wrapper = new THREE.Group();
    wrapper.scale.setScalar(fit);
    wrapper.add(copy);
    return wrapper;
}

export function createArena(scene, attackerInput, targetInput, notice, refresh) {
    const left = fighter(-3.65, 0x62dfeb);
    const right = fighter(3.65, 0xedac81);
    scene.add(left.body, right.body);
    const actors = [left, right];
    const controls = [attackerInput, targetInput];
    const requests = [0, 0];
    const state = ['', ''];

    for (const input of controls) {
        input.replaceChildren(...[...roster].map(([id, name]) => new Option(name, id)));
    }
    targetInput.value = '384';

    function load(index) {
        const id = controls[index].value;
        const actor = actors[index];
        const request = ++requests[index];
        state[index] = 'Chargement de ' + roster.get(id) + '…';
        notice.textContent = state.filter(Boolean).join(' ');
        model(id).then(gltf => {
            if (requests[index] !== request) return;
            const base = normalizedModel(gltf);
            actor.rig.clear();
            actor.baseModel = base;
            actor.enhanced = null;
            actor.rig.add(base);
            state[index] = '';
            notice.textContent = state.filter(Boolean).join(' ');
            refresh();
            if (mega[id] && index === 0) {
                model(mega[id]).then(alternate => {
                    if (requests[index] !== request) return;
                    actor.enhanced = normalizedModel(alternate);
                    actor.enhanced.visible = false;
                    actor.rig.add(actor.enhanced);
                    refresh();
                }).catch(() => {});
            }
        }).catch(() => {
            if (requests[index] !== request) return;
            state[index] = 'Le modèle de ' + roster.get(id) + ' est indisponible.';
            notice.textContent = state.filter(Boolean).join(' ');
        });
    }

    controls.forEach((input,index) => {
        input.addEventListener('change', () => load(index));
        load(index);
    });

    return {
        names: () => [roster.get(attackerInput.value), roster.get(targetInput.value)],
        pose(plan, frame, time) {
            const {approach, anticipation, reaction, settle, age} = frame;
            const momentum = Math.sin(Math.PI * approach / 2) * (1 - settle);
            left.body.position.x = left.base + (plan.contact ? 4.85 : .64) * momentum - anticipation * .34;
            left.rig.position.y = Math.sin(time * 2.8) * .035 + (plan.contact ? Math.sin(Math.PI * approach) * .65 : 0);
            left.rig.rotation.set(0, Math.PI / 2 - anticipation * .18, .23 * anticipation - .2 * momentum);
            const grow = plan.mode === 'giant' ? 1 + Math.min(1, approach + settle) * .34 : 1;
            left.rig.scale.set(grow, grow * (1 - .08 * anticipation), grow);
            const evolved = plan.mode === 'mega' && age >= 0 && left.enhanced;
            if (left.baseModel) left.baseModel.visible = !evolved;
            if (left.enhanced) left.enhanced.visible = Boolean(evolved);

            const flinch = plan.self || plan.ally ? 0 : reaction;
            right.body.position.x = right.base + flinch * 1.22;
            right.rig.position.y = Math.sin(time * 2.1 + .8) * .039 + flinch * .33;
            right.rig.rotation.set(flinch * .17, -Math.PI / 2 + flinch * .28, flinch * -.31);
            right.rig.scale.setScalar(1 - .095 * flinch);
            right.marker.material.opacity = .69 + flinch * .3;
            left.marker.material.opacity = .64 + anticipation * .33;
        },
    };
}
