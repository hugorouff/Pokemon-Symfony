import {accountRequest, accountState} from './account-session.js';

const login = document.querySelector('#account-login');
const register = document.querySelector('#account-register');
const logout = document.querySelector('#account-logout');
const summary = document.querySelector('#account-summary');
const notice = document.querySelector('#account-status');
const guest = document.querySelector('#guest-actions');
let guestChosen = false;

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
        if (guest) guest.hidden = true;
        logout.addEventListener('submit', event => submit(event, 'logout', state));
    } else if (state.accounts && !guestChosen) {
        if (guest) guest.hidden = false;
        login.hidden = register.hidden = false;
        login.addEventListener('submit', event => submit(event, 'login', state));
        register.addEventListener('submit', event => submit(event, 'register', state));
    } else if (!state.accounts) {
        notice.textContent = 'Les comptes sont momentanément indisponibles.';
    }
}).catch(error => { notice.textContent = error.message; });

const continueAsGuest = document.querySelector('#guest-continue');
continueAsGuest?.addEventListener('click', () => {
    guestChosen = true;
    login.hidden = register.hidden = true;
    guest.hidden = true;
    document.querySelector('#guest-home').hidden = false;
});
if (continueAsGuest) document.querySelector('#guest-actions').hidden = false;
