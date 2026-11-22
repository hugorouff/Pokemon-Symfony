<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class Catalog
{
    public const TYPES = [
        'feu' => ['Feu', 'Fire'],
        'eau' => ['Eau', 'Water'],
        'plante' => ['Plante', 'Grass'],
        'electrique' => ['Électrique', 'Lightning'],
        'psy' => ['Psy', 'Psychic'],
        'combat' => ['Combat', 'Fighting'],
        'tenebres' => ['Obscurité', 'Darkness'],
        'incolore' => ['Incolore', 'Colorless'],
    ];

    private const TCG = 'https://api.tcgdex.net/v2/';
    private const CRIES = 'https://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/';
    private const CRIES_MIRROR = 'https://cdn.jsdelivr.net/gh/PokeAPI/cries@main/cries/pokemon/';
    private array $loaded = [];

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly CacheInterface $cache,
        private readonly string $dataDirectory,
    ) {}

    public function cards(string $lang, string $query, string $set, string $type, string $sort, int $page, int $size): array
    {
        $needle = $this->normalize($query);
        $typed = $type !== '' ? array_fill_keys($this->index('types-'.$lang)[$type] ?? [], true) : null;
        $cards = [];
        foreach ($this->index('cards-'.$lang) as $row) {
            $setId = $this->setId($row);
            if (($set !== '' && $setId !== $set) || ($typed !== null && !isset($typed[$row['id']]))) {
                continue;
            }
            if ($needle !== '' && !str_contains($this->normalize($row['name']), $needle)
                && strtolower($row['id']) !== strtolower($query)) {
                continue;
            }
            $cards[] = $row;
        }
        if ($sort !== 'catalogue') {
            $direction = $sort === 'za' ? -1 : 1;
            usort($cards, fn (array $a, array $b) => $direction * (strnatcasecmp($this->normalize($a['name']), $this->normalize($b['name'])) ?: strnatcasecmp($a['id'], $b['id'])));
        }

        return $this->page($cards, $page, $size, fn (array $row) => $this->card($row));
    }

    public function sets(string $lang): array
    {
        $sets = $this->index('sets-'.$lang);
        $known = array_fill_keys(array_column($sets, 'id'), true);
        $names = array_column($this->index('sets-'.($lang === 'fr' ? 'en' : 'fr')), 'name', 'id');
        foreach ($this->index('cards-'.$lang) as $card) {
            $id = $this->setId($card);
            if (!isset($known[$id])) {
                $sets[] = ['id' => $id, 'name' => $names[$id] ?? $id];
                $known[$id] = true;
            }
        }

        return $sets;
    }

    public function albums(string $lang): array
    {
        $bySet = [];
        foreach ($this->index('cards-'.$lang) as $card) {
            $bySet[$this->setId($card)][] = $card['id'];
        }

        $albums = [];
        foreach ($this->sets($lang) as $set) {
            $ids = $bySet[$set['id']] ?? [];
            if ($ids !== []) {
                $albums[] = ['id' => $set['id'], 'name' => $set['name'], 'ids' => $ids];
            }
        }

        return $albums;
    }

    public function cardDetail(string $lang, string $id): array
    {
        $partial = false;
        try {
            $row = $this->cache->get('card.'.$lang.'.'.hash('sha256', $id), function (ItemInterface $item) use ($lang, $id) {
                $item->expiresAfter(86400);
                // Certains numéros TCGdex contiennent déjà un caractère encodé, par ex. %3F.
                $response = $this->client->request('GET', self::TCG.$lang.'/cards/'.rawurlencode(rawurldecode($id)));
                if ($response->getStatusCode() === 404) {
                    throw new NotFoundHttpException('Carte introuvable.');
                }

                return $response->toArray();
            });
        } catch (ExceptionInterface | NotFoundHttpException $error) {
            foreach ($this->index('cards-'.$lang) as $candidate) {
                if (strcasecmp($candidate['id'], $id) === 0) {
                    $row = $candidate;
                    $partial = true;
                    break;
                }
            }
            if (!isset($row)) {
                throw $error;
            }
        }

        $dexIds = $row['dexId'] ?? $row['nationalDexId'] ?? [];
        $dexIds = array_map('intval', is_array($dexIds) ? $dexIds : [$dexIds]);
        $pokemon = [];
        $cardName = $this->normalize($row['name']);
        $canMatchName = $dexIds === [] && ($partial || $this->normalize((string) ($row['category'] ?? '')) === 'pokemon');
        foreach ($this->index('dex') as $entry) {
            $name = $lang === 'fr' ? $this->normalize($entry[3]) : $entry[2];
            $matchesName = $canMatchName && ($cardName === $name
                || preg_match('/^'.preg_quote($name, '/').'[ -](?:ex|gx|v|vmax|vstar|niv[. ].*|lv[. ].*)$/', $cardName));
            if ($entry[4] && (in_array($entry[1], $dexIds, true) || $matchesName)) {
                $pokemon[] = $this->pokemon($entry);
            }
        }

        return $this->card($row) + [
            'description' => $row['description'] ?? '',
            'types' => $row['types'] ?? [],
            'rarity' => $row['rarity'] ?? '',
            'category' => $row['category'] ?? '',
            'hp' => $row['hp'] ?? null,
            'attacks' => $row['attacks'] ?? [],
            'setName' => $row['set']['name'] ?? '',
            'pokemon' => $pokemon,
            'partial' => $partial,
        ];
    }

    public function booster(string $lang, string $set, string $type): array
    {
        $typed = $type !== '' ? array_fill_keys($this->index('types-'.$lang)[$type] ?? [], true) : null;
        $cards = array_values(array_filter($this->index('cards-'.$lang), fn (array $card) =>
            isset($card['image'])
            && ($set === '' || $this->setId($card) === $set)
            && ($typed === null || isset($typed[$card['id']]))
        ));
        if (count($cards) < 5) {
            throw new BadRequestHttpException('Ce filtre doit contenir au moins cinq cartes illustrées. Choisissez un autre filtre.');
        }

        $indices = array_rand($cards, 5);
        shuffle($indices);
        $selected = array_map(fn (int $index) => $this->card($cards[$index]), $indices);
        foreach ($this->index('types-'.$lang) as $key => $ids) {
            $lookup = array_fill_keys($ids, true);
            foreach ($selected as &$card) {
                if (isset($lookup[$card['id']])) {
                    $card['types'][] = self::TYPES[$key][$lang === 'en' ? 1 : 0];
                }
            }
            unset($card);
        }

        return $selected;
    }

    public function dex(string $query, bool $forms, int $page, int $size, string $mode = '', string $type = '', array $favorites = []): array
    {
        $needle = $this->normalize($query);
        $entries = array_values(array_filter($this->index('dex'), function (array $row) use ($forms, $mode, $needle, $type, $favorites): bool {
            $eligible = match ($mode) {
                'mega' => $this->isMega($row[2]),
                'gmax' => str_ends_with($row[2], '-gmax'),
                'favorites' => isset($favorites[$row[0]]),
                default => $forms || $row[4],
            };
            $name = $this->dexName($row[2], $row[3], (bool) $row[4]);

            return $eligible && ($type === '' || in_array($type, $row[6], true))
                && ($needle === '' || (ctype_digit($needle) && ((int) $needle === $row[0] || (int) $needle === $row[1]))
                    || str_contains($this->normalize($name), $needle)
                    || str_contains($row[2], $needle));
        }));

        return $this->page($entries, $page, $size, fn (array $row) => $this->pokemon($row));
    }

    private function isMega(string $slug): bool
    {
        return in_array('mega', explode('-', $slug), true);
    }

    private function dexName(string $slug, string $name, bool $default): string
    {
        if ($default) {
            return $name;
        }
        if ($this->isMega($slug)) {
            $suffix = substr($slug, strrpos($slug, 'mega') + 4);

            return 'Méga-'.$name.strtoupper(str_replace('-', ' ', $suffix));
        }

        return str_ends_with($slug, '-gmax') ? $name.' Gigamax' : $name.' · '.$slug;
    }

    public function pokemonDetail(int $id): array
    {
        $entry = $this->pokemonEntry($id);
        $live = $this->livePokemon($id);

        $forms = array_filter($this->index('dex'), static fn (array $row) => $row[1] === $entry['number'] && $row[0] !== $id);

        return array_replace($entry, [
            'forms' => array_values(array_map(fn (array $row) => $this->pokemon($row), $forms)),
            'stats' => array_column($live['stats'] ?? [], 'base_stat'),
            'cry' => !empty($live['cries']['latest']) ? $this->cryUrl($id, 'latest') : $entry['cry'],
            'legacy' => !empty($live['cries']['legacy']) ? $this->cryUrl($id, 'legacy') : $entry['legacy'],
            'sprite' => $live['sprites']['front_default'] ?? $entry['sprite'],
            'types' => $entry['types'] ?: array_column(array_column($live['types'] ?? [], 'type'), 'name'),
            'height' => isset($live['height']) ? $live['height'] / 10 : $entry['height'],
            'weight' => isset($live['weight']) ? $live['weight'] / 10 : $entry['weight'],
        ]);
    }

    public function pokemonCry(int $id, string $variant): array
    {
        return $this->cache->get('pokemon-audio.'.$id.'.'.$variant, function (ItemInterface $item) use ($id, $variant) {
            $item->expiresAfter(604800);
            $entry = $this->pokemonEntry($id);
            $indexed = $entry[$variant === 'latest' ? 'cry' : 'legacy'];
            $liveUrl = $this->livePokemon($id)['cries'][$variant] ?? null;
            $path = $variant.'/'.$id.'.ogg';
            $officialUrl = self::CRIES.$path;
            // N'envoyer des requêtes qu'au dépôt officiel pour le Pokémon demandé.
            if (!$indexed && $liveUrl !== $officialUrl) {
                throw new NotFoundHttpException('Ce cri n’est pas disponible.');
            }

            $missing = 0;
            foreach ([$officialUrl, self::CRIES_MIRROR.$path] as $url) {
                try {
                    $response = $this->client->request('GET', $url, [
                        'headers' => ['Accept' => 'audio/*, application/octet-stream'],
                        'max_redirects' => 0,
                        'timeout' => 8,
                        'max_duration' => 10,
                    ]);
                    if ($response->getStatusCode() === 404) {
                        ++$missing;
                        continue;
                    }
                    $audio = $response->getContent();
                    $mime = $this->audioMime($audio);
                    if ($mime && strlen($audio) <= 512_000) {
                        return ['audio' => $audio, 'mime' => $mime];
                    }
                } catch (ExceptionInterface) {
                    continue;
                }
            }
            if ($missing === 2) {
                throw new NotFoundHttpException('Ce cri n’est pas disponible.');
            }

            throw new HttpException(502, 'Le fournisseur de cris est temporairement indisponible.');
        });
    }

    private function audioMime(string $audio): ?string
    {
        if (str_starts_with($audio, 'OggS')) {
            return 'audio/ogg';
        }
        if (str_starts_with($audio, 'ID3') || (strlen($audio) > 3 && ord($audio[0]) === 0xff && (ord($audio[1]) & 0xe0) === 0xe0)) {
            return 'audio/mpeg';
        }

        return null;
    }

    private function pokemonEntry(int $id): array
    {
        foreach ($this->index('dex') as $row) {
            if ($row[0] === $id) {
                return $this->pokemon($row);
            }
        }

        throw new NotFoundHttpException('Pokémon introuvable.');
    }

    private function livePokemon(int $id): array
    {
        return $this->cache->get('pokemon.'.$id, function (ItemInterface $item) use ($id) {
            $item->expiresAfter(86400);
            try {
                return $this->client->request('GET', 'https://pokeapi.co/api/v2/pokemon/'.$id, [
                    'timeout' => 3, 'max_duration' => 4,
                ])->toArray();
            } catch (ExceptionInterface) {
                $item->expiresAfter(60);

                return [];
            }
        });
    }

    private function cryUrl(int $id, string $variant): string
    {
        return '/api/pokemon/'.$id.'/cry/'.$variant;
    }

    public function refresh(string $lang): array
    {
        $base = self::TCG.$lang.'/';
        $cardsRequest = $this->client->request('GET', $base.'cards', ['max_duration' => 35]);
        $setsRequest = $this->client->request('GET', $base.'sets', ['max_duration' => 35]);
        $typeRequests = [];
        foreach (self::TYPES as $key => $names) {
            $typeRequests[$key] = $this->client->request('GET', $base.'cards', [
                'query' => ['types' => 'eq:'.$names[$lang === 'en' ? 1 : 0]],
                'max_duration' => 35,
            ]);
        }
        $cards = $cardsRequest->toArray();
        $sets = $setsRequest->toArray();
        $types = [];
        foreach ($typeRequests as $key => $request) {
            $types[$key] = array_column($request->toArray(), 'id');
        }
        if (!array_is_list($cards) || !array_is_list($sets) || count($cards) < 1000 || count($sets) < 30
            || !array_filter($types) || in_array([], $types, true)) {
            throw new \UnexpectedValueException('Le catalogue reçu est incomplet.');
        }
        $directory = $this->dataDirectory.'/../var/catalogue';
        $files = new \Symfony\Component\Filesystem\Filesystem();
        // Un seul fichier par langue permet une mise à jour atomique des cartes, séries et types.
        $files->dumpFile($directory.'/'.$lang.'.json.gz', gzencode(json_encode([
            'cards-'.$lang => $cards,
            'sets-'.$lang => $sets,
            'types-'.$lang => $types,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)));
        $this->loaded = [];

        return [count($cards), count($sets)];
    }

    private function index(string $key): array
    {
        if (!isset($this->loaded[$key])) {
            $lang = substr($key, -2);
            $updated = $this->dataDirectory.'/../var/catalogue/'.$lang.'.json.gz';
            if (in_array($lang, ['fr', 'en'], true) && is_file($updated)) {
                $this->loaded += $this->read($updated);
            }
            $this->loaded[$key] ??= $this->read($this->dataDirectory.'/'.$key.'.json.gz');
        }

        return $this->loaded[$key];
    }

    private function read(string $file): array
    {
        return json_decode(gzdecode(file_get_contents($file)), true, 512, JSON_THROW_ON_ERROR);
    }

    private function setId(array $row): string
    {
        return substr($row['id'], 0, -strlen((string) $row['localId']) - 1);
    }

    private function card(array $row): array
    {
        $image = $row['image'] ?? null;

        return [
            'id' => $row['id'],
            'name' => $row['name'],
            'localId' => $row['localId'],
            'set' => $row['set']['id'] ?? $this->setId($row),
            'images' => ['small' => $image ? $image.'/low.webp' : null, 'large' => $image ? $image.'/high.webp' : null],
        ];
    }

    private function pokemon(array $row): array
    {
        [$id, $species, $name, $french, $default, $audio, $types, $height, $weight] = $row;

        $models = $this->loaded['dex-models'] ??= json_decode(
            file_get_contents($this->dataDirectory.'/dex-models.json'), true, 512, JSON_THROW_ON_ERROR
        );

        $description = $this->index('dex-descriptions')[$species] ?? '';
        if ($this->isMega($name)) {
            $description = 'Cette Méga-Évolution transforme temporairement '.$french.'. '.$description;
        } elseif (str_ends_with($name, '-gmax')) {
            $description = 'Cette forme Gigamax change l’apparence de '.$french.' et augmente sa taille. '.$description;
        }

        return [
            'description' => trim($description),
            'label' => $this->dexName($name, $french, (bool) $default),
            'kind' => $this->isMega($name) ? 'mega' : (str_ends_with($name, '-gmax') ? 'gmax' : 'normal'),
            'models' => $models[$id] ?? null,
            'artwork' => 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/'.$id.'.png',
            'id' => $id, 'number' => $species, 'name' => $french, 'slug' => $name, 'default' => (bool) $default,
            'cry' => $audio & 1 ? $this->cryUrl($id, 'latest') : null,
            'legacy' => $audio & 2 ? $this->cryUrl($id, 'legacy') : null,
            'sprite' => 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/'.$id.'.png',
            'types' => $types, 'height' => $height, 'weight' => $weight,
        ];
    }

    private function page(array $items, int $page, int $size, callable $map): array
    {
        $total = count($items);

        return [
            'data' => array_map($map, array_slice($items, ($page - 1) * $size, $size)),
            'page' => $page, 'pageSize' => $size,
            'totalCount' => $total, 'totalPages' => (int) ceil($total / $size),
        ];
    }

    private function normalize(string $name): string
    {
        return strtr(mb_strtolower($name), [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a',
            'ï' => 'i', 'î' => 'i', 'ô' => 'o', 'ö' => 'o', 'û' => 'u', 'ü' => 'u',
            'ù' => 'u', 'ç' => 'c', 'œ' => 'oe', '’' => "'", '♀' => 'f', '♂' => 'm',
        ]);
    }
}
