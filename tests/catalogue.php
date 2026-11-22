<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__).'/vendor/autoload.php';

$kernel = new Kernel('prod', false);

function request(string $url, string $method = 'GET', int $expectedStatus = 200): array
{
    global $kernel;
    $response = $kernel->handle(Request::create($url, $method));
    check($response->getStatusCode() === $expectedStatus, $url.' : statut '.$response->getStatusCode());

    return json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
}

function check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

foreach (['fr', 'en'] as $lang) {
    $ids = [];
    $first = request('/api/cards?lang='.$lang.'&pageSize=100');
    check($first['totalCount'] > 20000, 'L’index doit dépasser la première page.');
    for ($page = 1; $page <= $first['totalPages']; ++$page) {
        $result = $page === 1 ? $first : request('/api/cards?lang='.$lang.'&pageSize=100&page='.$page);
        foreach ($result['data'] as $card) {
            check(!isset($ids[$card['id']]), 'Carte dupliquée entre deux pages : '.$card['id']);
            $ids[$card['id']] = true;
        }
    }
    check(count($ids) === $first['totalCount'], 'La pagination a perdu des cartes.');
    $albumIds = [];
    foreach (request('/api/albums?lang='.$lang)['data'] as $album) {
        check($album['ids'] !== [], 'Un album doit contenir au moins une carte.');
        foreach ($album['ids'] as $id) {
            check(isset($ids[$id]) && !isset($albumIds[$id]), 'Carte inconnue ou dupliquée dans les albums : '.$id);
            $albumIds[$id] = true;
        }
    }
    check(count($albumIds) === $first['totalCount'], 'Toutes les cartes doivent apparaître dans les albums.');

    $last = request('/api/cards?lang='.$lang.'&pageSize=100&page='.($first['totalPages'] + 1));
    check($last['data'] === [], 'Une page hors catalogue doit être vide.');
}

$set = request('/api/cards?lang=fr&set=swsh3&q=Fouinar');
check(in_array('swsh3-136', array_column($set['data'], 'id'), true), 'Le filtre doit combiner série et nom.');
$accent = request('/api/cards?q=vipelierre');
check($accent['totalCount'] > 0, 'Une recherche sans accent doit retrouver Vipélierre.');
$pack = request('/api/booster?lang=fr&type=feu', 'POST')['data'];
check(count(array_unique(array_column($pack, 'id'))) === 5, 'Un booster doit contenir cinq cartes différentes.');
foreach ($pack as $card) {
    check(in_array('Feu', $card['types'], true), 'Le booster doit respecter le type choisi.');
}
request('/api/booster?set=introuvable', 'POST', 400);
$dex = request('/api/pokemon?forms=1&pageSize=100');
check($dex['totalCount'] > 1300, 'Le Pokédex doit inclure les formes alternatives.');
$pikachu = request('/api/pokemon?q=25')['data'][0];
check($pikachu['name'] === 'Pikachu' && $pikachu['cry'] === '/api/pokemon/25/cry/latest' && $pikachu['legacy'] === '/api/pokemon/25/cry/legacy', 'Les deux cris de Pikachu doivent être disponibles.');

echo "Catalogue complet dans les deux langues, albums, filtres, boosters et Pokédex vérifiés.\n";
