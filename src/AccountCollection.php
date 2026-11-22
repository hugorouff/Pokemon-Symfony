<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class AccountCollection
{
    private const DAY = "date_trunc('day', now() AT TIME ZONE 'Europe/Paris') AT TIME ZONE 'Europe/Paris'";

    public function __construct(private readonly Postgres $database) {}

    public function list(string $owner, ?string $id): array
    {
        $sql = 'SELECT card, version FROM account_cards WHERE owner_id = ?';
        $query = $this->database->connection()->prepare($id === null ? $sql.' ORDER BY id' : $sql.' AND id = ?');
        $query->execute($id === null ? [$owner] : [$owner, $id]);
        $cards = [];
        foreach ($query as $row) {
            $cards[] = json_decode($row['card'], true, flags: JSON_THROW_ON_ERROR) + ['version' => (int) $row['version']];
        }

        return $cards;
    }

    public function merge(string $owner, array $cards, bool $add): void
    {
        $unique = [];
        foreach ($cards as $card) {
            if (!is_array($card)) {
                throw new BadRequestHttpException('Carte invalide.');
            }
            $entry = $this->normalize($card);
            $unique[$entry['id']] = $entry;
        }
        // Stable lock order when simultaneous uploads contain overlapping cards.
        ksort($unique);
        $quantity = $add ? 'LEAST(9999, (a.card->>\'quantity\')::int + (excluded.card->>\'quantity\')::int)'
            : 'GREATEST((a.card->>\'quantity\')::int, (excluded.card->>\'quantity\')::int)';
        $statement = $this->database->connection()->prepare(<<<SQL
            INSERT INTO account_cards AS a (owner_id, id, card)
            SELECT ?, value->>'id', value FROM jsonb_array_elements(CAST(? AS jsonb))
            ON CONFLICT (owner_id, id) DO UPDATE SET
                card = excluded.card || jsonb_build_object(
                    'quantity', $quantity,
                    'favorite', (a.card->>'favorite')::boolean OR (excluded.card->>'favorite')::boolean,
                    'images', CASE WHEN excluded.card->'images'->>'small' IS NULL THEN a.card->'images' ELSE excluded.card->'images' END,
                    'types', CASE WHEN excluded.card->'types' = '[]'::jsonb THEN a.card->'types' ELSE excluded.card->'types' END,
                    'rarity', coalesce(nullif(excluded.card->>'rarity', ''), a.card->>'rarity')
                ), version = a.version + 1
            SQL);
        $statement->execute([$owner, json_encode(array_values($unique), JSON_THROW_ON_ERROR)]);
    }

    public function change(string $owner, string $id, int $version, ?array $card): void
    {
        $db = $this->database->connection();
        if ($card === null) {
            $query = $db->prepare('DELETE FROM account_cards WHERE owner_id = ? AND id = ? AND version = ?');
            $query->execute([$owner, $id, $version]);
        } else {
            $card['id'] = $id;
            $query = $db->prepare('UPDATE account_cards SET card = CAST(? AS jsonb), version = version + 1 WHERE owner_id = ? AND id = ? AND version = ?');
            $query->execute([json_encode($this->normalize($card), JSON_THROW_ON_ERROR), $owner, $id, $version]);
        }
        if (!$query->rowCount()) {
            throw new ConflictHttpException('Cette carte a été modifiée ailleurs. Actualisez votre collection, puis réessayez.');
        }
    }

    public function remaining(string $owner): array
    {
        $counts = $this->database->connection()->prepare(
            'SELECT type_filter, count(*) FROM booster_openings WHERE owner_id = ? AND opened_at >= '.self::DAY.' GROUP BY type_filter'
        );
        $counts->execute([$owner]);
        $remaining = ['random' => 10] + array_fill_keys(array_keys(Catalog::TYPES), 3);
        foreach ($counts as $row) {
            $type = $row['type_filter'] ?: 'random';
            if (isset($remaining[$type])) {
                $remaining[$type] = max(0, $remaining[$type] - (int) $row['count']);
            }
        }

        return $remaining;
    }

    public function recordBooster(string $owner, string $language, string $set, string $type, array $cards): int
    {
        $db = $this->database->connection();
        $db->beginTransaction();
        try {
            $lock = $db->prepare('SELECT id FROM rom_accounts WHERE id = ? FOR UPDATE');
            $lock->execute([$owner]);
            $remaining = $this->remaining($owner)[$type ?: 'random'] ?? 0;
            if ($remaining <= 0) {
                throw new TooManyRequestsHttpException(null, 'La limite quotidienne de ce booster est atteinte.');
            }
            $statement = $db->prepare('INSERT INTO booster_openings (owner_id, locale, set_filter, type_filter, cards) VALUES (?, ?, ?, ?, CAST(? AS jsonb))');
            $statement->execute([$owner, $language, $set, $type, json_encode($cards, JSON_THROW_ON_ERROR)]);
            $this->merge($owner, array_map(static function (array $card) use ($language): array {
                $card['cardId'] = $card['id'];
                $card['id'] = $language.':'.$card['id'];
                $card['lang'] = $language;

                return $card;
            }, $cards), true);
            $db->commit();

            return $remaining - 1;
        } catch (\Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }
    }

    private function normalize(array $card): array
    {
        $id = $card['id'] ?? null;
        $cardId = $card['cardId'] ?? $id;
        if (!is_string($id) || !preg_match('/^(?:(?:fr|en):)?[a-z\d][a-z\d!%?._-]{0,99}$/iD', $id)
            || !is_string($cardId) || !preg_match('/^[a-z\d][a-z\d!%?._-]{0,99}$/iD', $cardId)) {
            throw new BadRequestHttpException('Identifiant de carte invalide.');
        }

        $images = is_array($card['images'] ?? null) ? $card['images'] : [];
        $types = is_array($card['types'] ?? null) ? array_slice(array_filter($card['types'], 'is_string'), 0, 8) : [];
        $set = $card['set'] ?? '';
        if (is_array($set)) {
            $set = $set['name'] ?? $set['id'] ?? '';
        }

        return [
            'id' => $id,
            'cardId' => $cardId,
            'lang' => ($card['lang'] ?? '') === 'fr' ? 'fr' : 'en',
            'name' => $this->limit($card['name'] ?? $cardId, 140),
            'set' => $this->limit($set, 140),
            'images' => ['small' => $this->image($images['small'] ?? null), 'large' => $this->image($images['large'] ?? null)],
            'types' => array_values(array_map(fn ($kind) => $this->limit($kind, 60), $types)),
            'rarity' => $this->limit($card['rarity'] ?? '', 100),
            'quantity' => is_int($card['quantity'] ?? null) ? max(1, min(9999, $card['quantity'])) : 1,
            'favorite' => ($card['favorite'] ?? false) === true,
        ];
    }

    private function limit(mixed $text, int $length): string
    {
        return is_string($text) ? mb_substr(str_replace("\0", '', $text), 0, $length) : '';
    }

    private function image(mixed $url): ?string
    {
        return is_string($url) && strlen($url) < 500 && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array(parse_url($url, PHP_URL_HOST), ['assets.tcgdex.net', 'images.pokemontcg.io'], true)
            ? $url : null;
    }
}
