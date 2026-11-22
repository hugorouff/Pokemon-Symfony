const hasFlag = (flags, index) => Boolean(flags & (1 << index));

export function choreography(move) {
    const {id, slug = '', damage, meta, target, flags, hits, priority, category, shape} = move;
    const seed = Number.isInteger(id) ? id : 9700 + Number(id.split('-').at(-1));
    const spectacle = ['Capacité Z', 'Dynamax', 'Gigamax', 'Transformation'].includes(category);
    const field = [4, 6, 9, 11, 12, 14].includes(target) || shape === 'field';
    const self = target === 7 || category === 'Transformation' || shape === 'heal';
    const ally = !self && [3, 5, 13, 15, 16].includes(target);
    const contact = !self && !ally && !field && damage === 2 && hasFlag(flags, 0);
    const guard = /^(protect|detect|endure|king-s-shield|spiky-shield|silk-trap|obstruct|baneful-bunker|burning-bulwark|max-guard|wide-guard|quick-guard)$/.test(slug);
    const mode = category === 'Transformation' ? shape : guard ? 'shield'
        : damage === 2 && !contact && field ? 'field'
        : damage === 2 && !contact && shape === 'strike' ? 'burst'
        : shape === 'aura' && !self && !ally && !field && meta === 1 ? 'hex'
        : !['beam', 'jet', 'pillars', 'crystals'].includes(shape) && hasFlag(flags, 2) ? 'sound'
        : shape === 'burst' && hasFlag(flags, 3) ? 'pulse'
        : shape;
    const charge = hasFlag(flags, 1) || mode === 'beam' || spectacle;
    const preparation = (charge ? .91 : contact ? .37 : .54) + (seed % 19) / 105 - Math.min(Math.max(priority, 0), 5) * .032;
    const flight = (self || ally ? .18 : contact ? .32 : mode === 'beam' ? .24 : .55) + (seed % 17) / 100;
    const count = Math.max(1, Math.min(hits || 1, 10));
    const spacing = count > 5 ? .105 : .155 + (seed % 7) / 140;
    const impacts = Array.from({length: count}, (_, index) => preparation + flight + index * spacing);
    const recovery = (spectacle ? 1.65 : 1.11) + (hasFlag(flags, 7) ? .36 : 0) + seed % 9 / 90;
    return {
        seed, mode, field, self, ally, contact, charge, spectacle,
        preparation, flight, impacts, recovery,
        duration: impacts.at(-1) + recovery,
    };
}

export function attackFrame(plan, time) {
    const {preparation, flight, impacts, recovery, duration} = plan;
    const clamp = value => Math.max(0, Math.min(1, value));
    const approach = clamp((time - preparation) / flight);
    const settle = clamp((time - impacts.at(-1)) / recovery);
    const anticipation = time < preparation ? Math.sin(clamp(time / preparation) * Math.PI) : 0;
    const reaction = Math.max(...impacts.map(impact => {
        const age = time - impact;
        return age < 0 || age > .66 ? 0 : Math.exp(-age * 6) * Math.sin(age * 13);
    }), 0);
    const lastImpact = impacts.findLast(impact => time >= impact);
    const age = lastImpact === undefined ? -1 : time - lastImpact;
    return {anticipation, approach, settle, reaction, age, done: time >= duration};
}
