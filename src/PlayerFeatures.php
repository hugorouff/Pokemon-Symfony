<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PlayerFeatures
{
    public function __construct(private readonly Postgres $database) {}

    public function get(string $owner, string $kind): array
    {
        $query = $this->database->connection()->prepare('SELECT payload, version, public_token FROM player_features WHERE owner_id = ? AND kind = ?');
        $query->execute([$owner, $kind]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);

        return $row ? ['data' => json_decode($row['payload'], !str_starts_with($kind, 'prefs-'), flags: JSON_THROW_ON_ERROR), 'version' => (int) $row['version'], 'token' => $row['public_token']]
            : ['data' => [], 'version' => 0, 'token' => null];
    }

    public function put(string $owner, string $kind, mixed $value, int $version): array
    {
        $data = $this->validate($kind, $value);
        $db = $this->database->connection();
        $content = json_encode($data, JSON_THROW_ON_ERROR);
        $token = $kind === 'shelf' ? bin2hex(random_bytes(16)) : null;
        if ($version === 0) {
            $query = $db->prepare('INSERT INTO player_features (owner_id, kind, payload, public_token) VALUES (?, ?, CAST(? AS jsonb), ?) ON CONFLICT DO NOTHING RETURNING version, public_token');
            $query->execute([$owner, $kind, $content, $token]);
        } else {
            $query = $db->prepare('UPDATE player_features SET payload = CAST(? AS jsonb), version = version + 1 WHERE owner_id = ? AND kind = ? AND version = ? RETURNING version, public_token');
            $query->execute([$content, $owner, $kind, $version]);
        }
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new ConflictHttpException('Un autre appareil a modifié ces données. Actualise la page puis réessaie.');
        }

        return ['data' => $data, 'version' => (int) $row['version'], 'token' => $row['public_token']];
    }

    public function shelf(string $token): array
    {
        $query = $this->database->connection()->prepare(<<<'SQL'
            SELECT c.card->>'id' AS id, c.card->>'name' AS name, c.card->>'set' AS set,
                   c.card->'images'->>'small' AS image, featured.position
            FROM player_features f
            LEFT JOIN LATERAL jsonb_array_elements_text(f.payload->'cards') WITH ORDINALITY AS featured(id, position) ON true
            LEFT JOIN account_cards c ON c.owner_id = f.owner_id AND c.id = featured.id
            WHERE f.kind = 'shelf' AND f.public_token = ? AND f.payload->>'published' = 'true'
            ORDER BY featured.position
            SQL);
        $query->execute([$token]);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) {
            throw new NotFoundHttpException('Ce classeur est indisponible.');
        }

        return array_values(array_filter($rows, static fn (array $row): bool => $row['id'] !== null));
    }

    public static function cardId(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^(?:(?:fr|en):)?[a-z\d][a-z\d!%?._-]{0,99}$/iD', $value)) {
            throw new BadRequestHttpException('Identifiant de carte invalide.');
        }

        return $value;
    }

    private function text(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            throw new BadRequestHttpException('Texte invalide.');
        }

        return mb_substr(trim(str_replace("\0", '', $value)), 0, $max);
    }

    private function items(mixed $list, int $max): array
    {
        if (!is_array($list) || !array_is_list($list) || count($list) > $max) {
            throw new BadRequestHttpException('Liste invalide ou limite atteinte.');
        }

        return $list;
    }

    private function gamePreferences(array $value): array
    {
        $result = [];
        foreach (['desmume', 'desmume2015'] as $core) {
            if (!isset($value[$core])) {
                continue;
            }
            $profile = $value[$core];
            if (!is_array($profile) || !is_array($profile['settings'] ?? null)
                || !is_array($profile['controlSettings'] ?? null) || count($profile['settings']) > 200) {
                throw new BadRequestHttpException('Réglages de jeu invalides.');
            }
            $options = [];
            foreach ($profile['settings'] as $key => $setting) {
                if (is_string($key) && $key !== 'retroarch_core' && preg_match('/^[a-z0-9_-]{1,80}$/iD', $key)
                    && (is_bool($setting) || is_int($setting) || is_float($setting)
                        || (is_string($setting) && strlen($setting) <= 250))) {
                    $options[$key] = $setting;
                }
            }
            $controls = [];
            for ($player = 0; $player < 4; $player++) {
                $bindings = $profile['controlSettings'][$player] ?? [];
                if (!is_array($bindings) || count($bindings) > 128) {
                    throw new BadRequestHttpException('Commandes de jeu invalides.');
                }
                $keys = [];
                foreach ($bindings as $index => $mapping) {
                    if (!is_int($index) || $index < 0 || $index > 255 || !is_array($mapping)) {
                        continue;
                    }
                    $button = [];
                    foreach (['value', 'value2'] as $field) {
                        $assigned = $mapping[$field] ?? null;
                        if (is_int($assigned) || (is_string($assigned) && strlen($assigned) <= 96)) {
                            $button[$field] = $assigned;
                        }
                    }
                    $keys[$index] = (object) $button;
                }
                $controls[$player] = (object) $keys;
            }
            $result[$core] = ['settings' => (object) $options, 'controlSettings' => (object) $controls];
        }

        return $result;
    }

    private function validate(string $kind, mixed $value): array
    {
        if (!is_array($value)) {
            throw new BadRequestHttpException('Données invalides.');
        }
        if ($kind === 'offers') {
            return array_values(array_unique(array_map(self::cardId(...), $this->items($value, 100))));
        }
        if ($kind === 'shelf') {
            return [
                'published' => ($value['published'] ?? false) === true,
                'cards' => array_values(array_unique(array_map(self::cardId(...), $this->items($value['cards'] ?? null, 9)))),
            ];
        }
        if (str_starts_with($kind, 'prefs-')) {
            return $this->gamePreferences($value);
        }
        if (str_starts_with($kind, 'journal-')) {
            $badges = $value['badges'] ?? null;
            if (!is_int($badges) || $badges < 0 || $badges > 16) {
                throw new BadRequestHttpException('Le nombre de badges doit être compris entre 0 et 16.');
            }

            return ['badges' => $badges, 'team' => $this->text($value['team'] ?? '', 400),
                'goal' => $this->text($value['goal'] ?? '', 500), 'notes' => $this->text($value['notes'] ?? '', 2000)];
        }
        if ($kind === 'wishlist') {
            $cards = [];
            foreach ($this->items($value, 300) as $card) {
                if (!is_array($card)) {
                    throw new BadRequestHttpException('Carte recherchée invalide.');
                }
                $id = self::cardId($card['id'] ?? null);
                $cards[$id] = ['id' => $id, 'name' => $this->text($card['name'] ?? $id, 140), 'set' => $this->text($card['set'] ?? '', 140)];
            }

            return array_values($cards);
        }

        $decks = [];
        foreach ($this->items($value, 50) as $deck) {
            if (!is_array($deck) || !is_int($deck['id'] ?? null) || $deck['id'] < 1) {
                throw new BadRequestHttpException('Deck invalide.');
            }
            $name = $this->text($deck['name'] ?? '', 60);
            $entries = [];
            $total = 0;
            foreach ($this->items($deck['entries'] ?? null, 60) as $entry) {
                if (!is_array($entry) || !is_int($entry['quantity'] ?? null) || $entry['quantity'] < 1) {
                    throw new BadRequestHttpException('Quantité invalide.');
                }
                $key = self::cardId($entry['key'] ?? null);
                if (isset($entries[$key]) || $entry['quantity'] > 60) {
                    throw new BadRequestHttpException('Composition de deck invalide.');
                }
                $total += $entry['quantity'];
                $entries[$key] = [
                    'key' => $key, 'cardId' => self::cardId($entry['cardId'] ?? null), 'quantity' => $entry['quantity'],
                    'name' => $this->text($entry['name'] ?? $key, 140), 'set' => $this->text($entry['set'] ?? '', 140),
                    'lang' => ($entry['lang'] ?? '') === 'fr' ? 'fr' : 'en',
                ];
            }
            if ($name === '' || $total > 60 || isset($decks[$deck['id']])) {
                throw new BadRequestHttpException('Un deck doit avoir un nom et contenir au plus 60 cartes.');
            }
            $decks[$deck['id']] = ['id' => $deck['id'], 'name' => $name, 'entries' => array_values($entries)];
        }

        return array_values($decks);
    }
}
