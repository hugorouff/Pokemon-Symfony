<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CardTrades
{
    public function __construct(private readonly Postgres $database, private readonly PlayerFeatures $features) {}

    public function list(string $owner): array
    {
        $query = $this->database->connection()->prepare(<<<'SQL'
            SELECT t.id, t.proposer, t.recipient, t.give_card, t.take_card, t.status, t.created_at,
                   coalesce(g.card->>'name', t.give_card) AS give_name,
                   coalesce(k.card->>'name', t.take_card) AS take_name
            FROM card_trades t
            LEFT JOIN account_cards g ON g.owner_id = t.proposer AND g.id = t.give_card
            LEFT JOIN account_cards k ON k.owner_id = t.recipient AND k.id = t.take_card
            WHERE t.proposer = ? OR t.recipient = ? ORDER BY t.created_at DESC LIMIT 100
            SQL);
        $query->execute([$owner, $owner]);

        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function profile(string $viewer, string $friend): array
    {
        $this->checkFriend($viewer, $friend);

        return ['offers' => $this->offers($friend), 'wishes' => $this->features->get($friend, 'wishlist')['data']];
    }

    public function offers(string $owner): array
    {
        $ids = $this->features->get($owner, 'offers')['data'];
        $query = $this->database->connection()->prepare(<<<'SQL'
            SELECT id, card->>'name' AS name, card->>'set' AS set
            FROM account_cards WHERE owner_id = ? AND (card->>'quantity')::int > 1
              AND id IN (SELECT jsonb_array_elements_text(CAST(? AS jsonb)))
            ORDER BY card->>'name'
            SQL);
        $query->execute([$owner, json_encode($ids, JSON_THROW_ON_ERROR)]);

        return $query->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function propose(string $owner, string $friend, string $give, string $take): void
    {
        $this->checkFriend($owner, $friend);
        if ($give === $take || !in_array($give, array_column($this->offers($owner), 'id'), true)
            || !in_array($take, array_column($this->offers($friend), 'id'), true)) {
            throw new BadRequestHttpException('Choisis deux doubles différents encore disponibles à l’échange.');
        }
        $db = $this->database->connection();
        $query = $db->prepare(<<<'SQL'
            INSERT INTO card_trades (id, proposer, recipient, give_card, take_card)
            SELECT ?, ?, ?, ?, ? WHERE (SELECT count(*) FROM card_trades WHERE proposer = ? AND status = 'pending') < 30
            ON CONFLICT DO NOTHING
            SQL);
        $query->execute([bin2hex(random_bytes(16)), $owner, $friend, $give, $take, $owner]);
        if (!$query->rowCount()) {
            throw new ConflictHttpException('Cette proposition existe déjà ou trop d’échanges sont en attente.');
        }
    }

    public function decide(string $owner, string $id, string $action): void
    {
        $db = $this->database->connection();
        $db->beginTransaction();
        try {
            $query = $db->prepare("SELECT * FROM card_trades WHERE id = ? AND status = 'pending' AND (proposer = ? OR recipient = ?) FOR UPDATE");
            $query->execute([$id, $owner, $owner]);
            $trade = $query->fetch(\PDO::FETCH_ASSOC);
            if (!$trade || ($action === 'cancel' ? $trade['proposer'] : $trade['recipient']) !== $owner) {
                throw new NotFoundHttpException('Proposition indisponible.');
            }
            if ($action === 'accept') {
                $this->exchange($db, $trade);
            }
            $status = ['accept' => 'accepted', 'reject' => 'rejected', 'cancel' => 'cancelled'][$action];
            $db->prepare('UPDATE card_trades SET status = ?, decided_at = now() WHERE id = ?')->execute([$status, $id]);
            $db->commit();
        } catch (\Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    }

    private function exchange(\PDO $db, array $trade): void
    {
        $owners = [$trade['proposer'], $trade['recipient']];
        $cards = [$trade['give_card'], $trade['take_card']];
        // Lock both account ledgers in a stable order before reading or creating destination rows.
        $lock = $db->prepare('SELECT id FROM rom_accounts WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
        $lock->execute($owners);
        $query = $db->prepare('SELECT owner_id, id, card FROM account_cards WHERE owner_id IN (?, ?) AND id IN (?, ?) ORDER BY owner_id, id FOR UPDATE');
        $query->execute([...$owners, ...$cards]);
        $stock = [];
        foreach ($query as $row) {
            $stock[$row['owner_id']][$row['id']] = json_decode($row['card'], true, flags: JSON_THROW_ON_ERROR);
        }
        $debit = $db->prepare("UPDATE account_cards SET card = jsonb_set(card, '{quantity}', to_jsonb((card->>'quantity')::int - 1)), version = version + 1 WHERE owner_id = ? AND id = ?");
        $credit = $db->prepare(<<<'SQL'
            INSERT INTO account_cards AS a (owner_id, id, card) VALUES (?, ?, CAST(? AS jsonb))
            ON CONFLICT (owner_id, id) DO UPDATE SET
                card = jsonb_set(a.card, '{quantity}', to_jsonb((a.card->>'quantity')::int + 1)),
                version = a.version + 1
            WHERE (a.card->>'quantity')::int < 9999
            SQL);
        foreach ([0, 1] as $side) {
            $source = $owners[$side];
            $destination = $owners[1 - $side];
            $key = $cards[$side];
            $card = $stock[$source][$key] ?? null;
            if (($card['quantity'] ?? 0) < 2 || !in_array($key, $this->features->get($source, 'offers')['data'], true)
                || ($stock[$destination][$key]['quantity'] ?? 0) >= 9999) {
                throw new ConflictHttpException('L’un des doubles n’est plus disponible ou la collection du destinataire est pleine.');
            }
            $debit->execute([$source, $key]);
            $card['quantity'] = 1;
            $card['favorite'] = false;
            $credit->execute([$destination, $key, json_encode($card, JSON_THROW_ON_ERROR)]);
            if (!$credit->rowCount()) {
                throw new ConflictHttpException('La quantité du destinataire a changé. Réessaie.');
            }
        }
    }

    private function checkFriend(string $owner, string $friend): void
    {
        if ($owner === $friend) {
            throw new BadRequestHttpException('Utilise le code d’un autre joueur.');
        }
        $query = $this->database->connection()->prepare('SELECT 1 FROM rom_accounts WHERE id = ?');
        $query->execute([$friend]);
        if (!$query->fetchColumn()) {
            throw new NotFoundHttpException('Joueur introuvable.');
        }
    }
}
