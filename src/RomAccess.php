<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class RomAccess
{
    public function __construct(
        private readonly string $directory,
        private readonly RateLimiterFactoryInterface $romLoginLimiter,
        private readonly RomAccounts $accounts,
        private readonly RateLimiterFactoryInterface $accountRegistrationLimiter,
        private readonly RateLimiterFactoryInterface $accountRecoveryLimiter,
        private readonly RateLimiterFactoryInterface $accountResetLimiter,
        private readonly AccountRecovery $recovery,
    ) {}

    public function state(Request $request): array
    {
        $session = $request->getSession();
        $token = $session->get('rom.csrf');
        if (!is_string($token)) {
            $token = bin2hex(random_bytes(32));
            $session->set('rom.csrf', $token);
        }

        $owner = $this->owner($request);

        return [
            'configured' => $this->accounts->enabled() || $this->passwordHash() !== '',
            'accounts' => $this->accounts->enabled(),
            'recovery' => $this->recovery->enabled(),
            'accountId' => $owner ?: null,
            'authenticated' => $owner !== null,
            'token' => $token,
        ];
    }

    public function login(Request $request): JsonResponse
    {
        if (!$this->validToken($request)) {
            return $this->error('La session a expiré. Rechargez la page.', 403);
        }
        $limiter = $this->romLoginLimiter->create($request->getClientIp() ?? 'local');
        $limit = $limiter->consume();
        if (!$limit->isAccepted()) {
            return new JsonResponse(['error' => 'Trop de tentatives. Réessayez dans quelques minutes.'], 429, [
                'Retry-After' => (string) max(1, $limit->getRetryAfter()->getTimestamp() - time()),
                'Cache-Control' => 'private, no-store',
            ]);
        }
        $password = $request->request->getString('password');
        $account = null;
        if ($this->accounts->enabled()) {
            $email = trim($request->request->getString('email'));
            if (strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $account = $this->accounts->byEmail($email);
            }
            $hash = $account['password_hash'] ?? '';
        } else {
            $hash = $this->passwordHash();
            if ($hash === '') {
                return $this->error('Initialisez le mot de passe de la bibliothèque.', 503);
            }
        }
        $verificationHash = $hash ?: '$2y$12$wWIlASh2JYhrR8xOKyAXe.pJKl9XqTiTvhrzXq6nwt.SnydZW9qF6';
        if (strlen($password) > 128 || !password_verify($password, $verificationHash) || $hash === '') {
            return $this->error('Identifiants incorrects.', 401);
        }
        $limiter->reset();
        $this->authenticate($request, $account['id'] ?? '', $hash);

        return new JsonResponse($this->state($request), 200, ['Cache-Control' => 'private, no-store']);
    }

    public function register(Request $request): JsonResponse
    {
        if (!$this->accounts->enabled()) {
            return $this->error('La création de comptes est désactivée.', 503);
        }
        if (!$this->validToken($request)) {
            return $this->error('La session a expiré. Rechargez la page.', 403);
        }
        if (!$this->accountRegistrationLimiter->create($request->getClientIp() ?? 'local')->consume()->isAccepted()) {
            return $this->error('Trop de tentatives. Réessayez dans quelques minutes.', 429);
        }

        $email = trim($request->request->getString('email'));
        $password = $request->request->getString('password');
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Saisissez une adresse mail valide.', 422);
        }
        if ($error = $this->newPasswordError($password, $request->request->getString('confirmation'))) {
            return $this->error($error, 422);
        }
        $account = $this->accounts->register($email, $password);
        $created = $account !== null;
        if (!$created) {
            $existing = $this->accounts->byEmail($email);
            if ($existing === null || !password_verify($password, $existing['password_hash'])) {
                return new JsonResponse(['error' => 'Adresse déjà utilisée. Connecte-toi avec le mot de passe de ce compte.',
                    'code' => 'account_exists'], 409, ['Cache-Control' => 'private, no-store']);
            }
            $account = $existing;
        }
        $this->authenticate($request, $account['id'], $account['password_hash']);

        return new JsonResponse($this->state($request), $created ? 201 : 200, ['Cache-Control' => 'private, no-store']);
    }

    public function recover(Request $request, bool $confirm): JsonResponse
    {
        if (!$this->recovery->enabled()) {
            return $this->error('La récupération par e-mail est indisponible.', 503);
        }
        if (!$this->validToken($request)) {
            return $this->error('La session a expiré. Rechargez la page.', 403);
        }
        $limiter = $confirm ? $this->accountResetLimiter : $this->accountRecoveryLimiter;
        if (!$limiter->create($request->getClientIp() ?? 'local')->consume()->isAccepted()) {
            return $this->error('Trop de tentatives. Réessaie dans quelques minutes.', 429);
        }
        $email = trim($request->request->getString('email'));
        if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('Saisis une adresse mail valide.', 422);
        }
        if (!$confirm) {
            $this->recovery->sendCode($email);

            return new JsonResponse(['message' => 'Si cette adresse correspond à un compte, un code lui sera envoyé.'], 200);
        }

        $code = trim($request->request->getString('code'));
        $password = $request->request->getString('password');
        if ($error = $this->newPasswordError($password, $request->request->getString('confirmation'))) {
            return $this->error($error, 422);
        }
        if (!preg_match('/^[a-f0-9]{32}$/iD', $code) || !$this->recovery->reset($email, $code, $password)) {
            return $this->error('Code invalide ou expiré. Demande un nouveau code.', 422);
        }

        return new JsonResponse(['message' => 'Mot de passe mis à jour. Connecte-toi avec ton nouveau mot de passe.'], 200);
    }

    private function newPasswordError(string $password, string $confirmation): ?string
    {
        if (mb_strlen($password) < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            return 'Utilise un mot de passe d’au moins 12 caractères et de 72 octets maximum.';
        }

        return $password !== $confirmation ? 'Les deux mots de passe doivent être identiques.' : null;
    }

    private function authenticate(Request $request, string $id, string $hash): void
    {
        $session = $request->getSession();
        $session->migrate(true);
        $session->set('rom.auth', hash('sha256', $hash));
        $session->set('rom.account_id', $id);
        $session->remove('rom.csrf');
    }

    public function logout(Request $request): JsonResponse
    {
        if (!$this->validToken($request)) {
            return $this->error('La session a expiré. Rechargez la page.', 403);
        }
        $request->getSession()->invalidate();

        return new JsonResponse(['authenticated' => false], 200, ['Cache-Control' => 'private, no-store']);
    }

    #[AsEventListener(event: 'kernel.controller')]
    public function guard(ControllerEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->getBoolean('_public_auth')
            || ($request->attributes->get('_route') === 'section' && $request->attributes->get('section') === 'compte')) {
            return;
        }
        $owner = $this->owner($request);
        if ($owner === null || $owner === '') {
            $event->setController(str_starts_with($request->getPathInfo(), '/api/')
                ? fn () => $this->error('Connecte-toi pour accéder à l’application.', 401)
                : static fn () => new RedirectResponse('/', 302, ['Cache-Control' => 'private, no-store']));

            return;
        }
        if (!$request->isMethodSafe() && !$this->validToken($request)) {
            $event->setController(fn () => $this->error('La session a expiré. Rechargez la page.', 403));

            return;
        }
        $request->attributes->set('rom.owner', $owner);
        // Libère la session avant un transfert long, pour les autres onglets et téléchargements.
        if ($request->isMethodSafe()) {
            $request->getSession()->save();
        }
    }

    #[AsEventListener(event: 'kernel.response')]
    public function preventPrivateCaching(ResponseEvent $event): void
    {
        if ($event->isMainRequest()) {
            $event->getResponse()->headers->set('Cache-Control', 'private, no-store');
        }
    }

    public function initialize(bool $replace): ?string
    {
        if ($this->environmentHash() !== '') {
            return null;
        }
        $files = new Filesystem();
        $files->mkdir($this->directory);
        $lock = fopen($this->directory.'/.credentials.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Le dossier des ROMs n’est pas accessible en écriture.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Impossible de verrouiller le fichier de configuration.');
            }
            if (!$replace && $this->passwordHash() !== '') {
                return null;
            }
            $password = bin2hex(random_bytes(16));
            $path = $this->directory.'/.password-hash';
            $files->dumpFile($path, password_hash($password, PASSWORD_DEFAULT));
            if (PHP_OS_FAMILY !== 'Windows') {
                $files->chmod($path, 0600);
            }

            return $password;
        } finally {
            fclose($lock);
        }
    }

    private function environmentHash(): string
    {
        return Postgres::setting('ROMS_PASSWORD_HASH');
    }

    private function passwordHash(): string
    {
        $hash = $this->environmentHash() ?: trim((string) @file_get_contents($this->directory.'/.password-hash'));

        return password_get_info($hash)['algo'] ? $hash : '';
    }

    public function owner(Request $request): ?string
    {
        $session = $request->getSession();
        $proof = $session->get('rom.auth');
        if (!is_string($proof)) {
            return null;
        }
        $id = $session->get('rom.account_id', '');
        if ($this->accounts->enabled()) {
            if ($id === '') {
                return null;
            }
            $hash = $this->accounts->byId($id)['password_hash'] ?? '';
        } else {
            if ($id !== '') {
                return null;
            }
            $hash = $this->passwordHash();
        }

        return $hash !== '' && hash_equals(hash('sha256', $hash), $proof) ? $id : null;
    }

    public function validToken(Request $request): bool
    {
        $token = $request->getSession()->get('rom.csrf');

        return is_string($token) && hash_equals($token, $request->headers->get('X-CSRF-Token', ''));
    }

    private function error(string $text, int $status): JsonResponse
    {
        return new JsonResponse(['error' => $text], $status, ['Cache-Control' => 'private, no-store']);
    }
}
