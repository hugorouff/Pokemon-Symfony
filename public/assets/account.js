import {accountRequest, accountState} from './account-session.js';

const login = document.querySelector('#account-login');
const register = document.querySelector('#account-register');
const logout = document.querySelector('#account-logout');
const summary = document.querySelector('#account-summary');
const notice = document.querySelector('#account-status');

async function submit(event, action, state) {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button');
    button.disabled = true;
    notice.textContent = '';
    notice.classList.remove('error');
    try {
        await accountRequest('/api/rom-access/' + action, {}, 'POST', state.token, new FormData(form));
        if (action === 'logout') {
            try { await globalThis.caches?.delete('atlas-roms-v1'); } catch {}
            location.replace('/');
            return;
        }
        location.reload();
    } catch (error) {
        notice.textContent = error.message;
        notice.classList.add('error');
        if (error.code === 'account_exists') {
            login.elements.email.value = register.elements.email.value.trim();
            register.reset();
            login.elements.password.focus();
        }
        button.disabled = false;
    }
}

function enableRecovery(state) {
    const open = document.querySelector('#account-recover');
    const dialog = document.querySelector('#account-recovery-dialog');
    const request = document.querySelector('#recovery-request');
    const confirm = document.querySelector('#recovery-confirm');
    const status = document.querySelector('#recovery-status');
    let requestedEmail = '';
    open.hidden = false;
    open.addEventListener('click', () => {
        request.elements.email.value = login.elements.email.value;
        if (!dialog.open) dialog.showModal();
    });
    async function send(event, step) {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button');
        const data = new FormData(form);
        const email = step === 'request' ? data.get('email') : requestedEmail;
        data.set('email', email);
        button.disabled = true;
        status.textContent = '';
        status.classList.remove('error');
        try {
            const result = await accountRequest('/api/rom-access/recover/' + step, {}, 'POST', state.token, data);
            if (step === 'request') {
                requestedEmail = email;
                status.textContent = result.message;
                confirm.hidden = false;
                confirm.elements.code.focus();
            } else {
                confirm.reset();
                confirm.hidden = true;
                status.textContent = '';
                dialog.close();
                login.elements.email.value = email;
                login.elements.password.value = '';
                notice.classList.remove('error');
                notice.textContent = result.message;
                login.elements.password.focus();
            }
        } catch (error) {
            status.textContent = error.message;
            status.classList.add('error');
        } finally {
            button.disabled = false;
        }
    }
    request.addEventListener('submit', event => send(event, 'request'));
    confirm.addEventListener('submit', event => send(event, 'confirm'));
}

accountState().then(state => {
    if ((state.accountId && !summary) || (!state.accountId && !login)) {
        location.replace('/');
        return;
    }
    if (state.accountId) {
        summary.hidden = false;
        logout.addEventListener('submit', event => submit(event, 'logout', state));
    } else if (state.accounts) {
        login.hidden = register.hidden = false;
        login.addEventListener('submit', event => submit(event, 'login', state));
        register.addEventListener('submit', event => submit(event, 'register', state));
        if (state.recovery) enableRecovery(state);
    } else if (!state.accounts) {
        notice.textContent = 'Les comptes sont momentanément indisponibles.';
    }
}).catch(error => { notice.textContent = error.message; });
