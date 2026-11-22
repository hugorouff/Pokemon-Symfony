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
    try {
        await accountRequest('/api/rom-access/' + action, {}, 'POST', state.token, new FormData(form));
        location.reload();
    } catch (error) {
        notice.textContent = error.message;
        button.disabled = false;
    }
}

accountState().then(state => {
    if (state.accountId) {
        summary.hidden = false;
        logout.addEventListener('submit', event => submit(event, 'logout', state));
    } else if (state.accounts) {
        login.hidden = register.hidden = false;
        login.addEventListener('submit', event => submit(event, 'login', state));
        register.addEventListener('submit', event => submit(event, 'register', state));
    } else {
        notice.textContent = 'Les comptes sont momentanément indisponibles.';
    }
}).catch(error => { notice.textContent = error.message; });
