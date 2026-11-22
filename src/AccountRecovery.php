<?php

declare(strict_types=1);

namespace App;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AccountRecovery
{
    public function __construct(
        private readonly Postgres $database,
        private readonly RomAccounts $accounts,
        private readonly HttpClientInterface $http,
    ) {}

    public function enabled(): bool
    {
        return $this->accounts->enabled() && Postgres::setting('RESEND_API_KEY') !== ''
            && filter_var(Postgres::setting('PASSWORD_RESET_FROM'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function sendCode(string $email): void
    {
        $email = strtolower(trim($email));
        $account = $this->accounts->byEmail($email);
        if ($account === null) {
            return;
        }

        $token = bin2hex(random_bytes(16));
        $hash = hash('sha256', $token);
        $db = $this->database->connection();
        $store = $db->prepare(<<<'SQL'
            INSERT INTO account_password_resets (owner_id, token_hash, expires_at)
            VALUES (?, ?, now() + interval '15 minutes')
            ON CONFLICT (owner_id) DO UPDATE SET token_hash = excluded.token_hash, expires_at = excluded.expires_at,
                requested_at = now()
            WHERE account_password_resets.requested_at < now() - interval '1 minute'
            SQL);
        $store->execute([$account['id'], $hash]);
        if ($store->rowCount() !== 1) {
            return;
        }

        $accepted = false;
        try {
            $response = $this->http->request('POST', 'https://api.resend.com/emails', [
                'auth_bearer' => Postgres::setting('RESEND_API_KEY'),
                'json' => [
                    'from' => Postgres::setting('PASSWORD_RESET_FROM'),
                    'to' => [$email],
                    'subject' => 'Réinitialiser ton mot de passe Pokémon TCG App',
                    'text' => "Ton code de réinitialisation est : ".$token."\n\nSaisis-le dans « Mot de passe oublié ? » sur Pokémon TCG App. Il expire dans 15 minutes et ne peut être utilisé qu’une seule fois.\n\nSi tu n’as pas demandé ce code, ignore ce message.",
                ],
                'timeout' => 5,
                'max_duration' => 8,
                'max_redirects' => 0,
            ]);
            $status = $response->getStatusCode();
            $accepted = $status >= 200 && $status < 300;
        } catch (ExceptionInterface) {
            // Keep the public response identical for missing accounts and delivery failures.
        }

        if (!$accepted) {
            $db->prepare('DELETE FROM account_password_resets WHERE owner_id = ? AND token_hash = ?')
                ->execute([$account['id'], $hash]);
            error_log('Le service d’envoi de codes de réinitialisation est indisponible.');
        }
    }

    public function reset(string $email, string $code, string $password): bool
    {
        $query = $this->database->connection()->prepare(<<<'SQL'
            WITH used AS (
                DELETE FROM account_password_resets r USING rom_accounts a
                WHERE r.owner_id = a.id AND a.email = ? AND r.token_hash = ? AND r.expires_at > now()
                RETURNING r.owner_id
            )
            UPDATE rom_accounts SET password_hash = ? FROM used WHERE rom_accounts.id = used.owner_id
            SQL);
        $query->execute([strtolower(trim($email)), hash('sha256', strtolower($code)), password_hash($password, PASSWORD_DEFAULT)]);

        return $query->rowCount() === 1;
    }
}
