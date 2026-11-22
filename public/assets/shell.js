const groups = [...document.querySelectorAll('.nav-group')];
for (const group of groups) {
    group.addEventListener('toggle', () => {
        if (group.open) for (const other of groups) if (other !== group) other.open = false;
    });
    group.addEventListener('keydown', event => {
        if (event.key === 'Escape' && group.open) {
            group.open = false;
            group.querySelector('summary').focus();
        }
    });
}
document.addEventListener('click', event => {
    for (const group of groups) if (!group.contains(event.target)) group.open = false;
});

const install = document.querySelector('#install-app');
const help = document.querySelector('#install-help');
const instructions = document.querySelector('#install-instructions');
const standalone = window.matchMedia('(display-mode: standalone)');
let prompt;
const check = () => { install.hidden = !window.isSecureContext || standalone.matches || navigator.standalone === true; };

window.addEventListener('beforeinstallprompt', event => {
    event.preventDefault();
    prompt = event;
    check();
});
window.addEventListener('appinstalled', () => {
    install.hidden = true;
    prompt = undefined;
});
standalone.addEventListener('change', check);
install.addEventListener('click', async () => {
    if (prompt) {
        const current = prompt;
        prompt = undefined;
        try {
            await current.prompt();
            if ((await current.userChoice).outcome === 'accepted') install.hidden = true;
            return;
        } catch {}
    }
    const ios = /iPhone|iPad|iPod/i.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    instructions.textContent = ios
        ? 'Dans Safari, ouvre Partager, puis choisis « Sur l’écran d’accueil ».'
        : 'Dans Chrome ou Edge, ouvre le menu du navigateur et cherche « Installer cette application » ou « Installer la page en tant qu’application ».';
    if (!help.open) help.showModal();
});
check();
if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/service-worker.js').catch(error => console.warn('Installation web indisponible.', error));
}
window.addEventListener('pageshow', event => {
    if (event.persisted) location.reload();
});
