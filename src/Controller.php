<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Twig\Environment;

final class Controller
{
    public function __construct(
        private readonly Environment $twig,
        private readonly Catalog $catalog,
    ) {}

    #[Route('/', name: 'home', defaults: ['section' => 'accueil', '_public_auth' => true], methods: ['GET'])]
    #[Route('/{section}', name: 'section', requirements: ['section' => 'cartes|boosters|collection|albums|decks|pokedex|emulateur|compte|objectifs|classeur'], methods: ['GET'])]
    public function page(string $section, Request $request, RomAccess $access, RomAccounts $accounts): Response
    {
        $owner = $request->attributes->get('rom.owner') ?? $access->owner($request);

        $signedIn = $owner !== null && $owner !== '';
        $accountEmail = $signedIn && $section === 'compte' ? ($accounts->byId($owner)['email'] ?? null) : null;

        return new Response($this->twig->render('app.html.twig', [
            'section' => $section,
            'signedIn' => $signedIn,
            'accountEmail' => $accountEmail,
        ]), 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/noir-2', methods: ['GET'])]
    public function legacyPlayer(): RedirectResponse
    {
        return new RedirectResponse('/emulateur', 301);
    }

    #[Route('/{old}.html', name: 'legacy', requirements: ['old' => 'index|search|booster|collection'], methods: ['GET'])]
    public function legacy(string $old): RedirectResponse
    {
        return new RedirectResponse(['index' => '/', 'search' => '/cartes', 'booster' => '/boosters', 'collection' => '/collection'][$old], 301);
    }

    #[Route('/api/rom-access', defaults: ['_public_auth' => true], methods: ['GET'])]
    public function romAccess(Request $request, RomAccess $access): JsonResponse
    {
        return new JsonResponse($access->state($request), 200, ['Cache-Control' => 'private, no-store']);
    }

    #[Route('/api/rom-access/login', defaults: ['_public_auth' => true], methods: ['POST'])]
    public function unlockRoms(Request $request, RomAccess $access): JsonResponse
    {
        return $access->login($request);
    }

    #[Route('/api/rom-access/register', defaults: ['_public_auth' => true], methods: ['POST'])]
    public function register(Request $request, RomAccess $access): JsonResponse
    {
        return $access->register($request);
    }

    #[Route('/api/rom-access/recover/{action}', requirements: ['action' => 'request|confirm'], defaults: ['_public_auth' => true], methods: ['POST'])]
    public function recover(Request $request, RomAccess $access, string $action): JsonResponse
    {
        return $access->recover($request, $action === 'confirm');
    }

    #[Route('/api/rom-access/logout', methods: ['POST'])]
    public function lockRoms(Request $request, RomAccess $access): JsonResponse
    {
        return $access->logout($request);
    }

    #[Route('/api/cards', methods: ['GET'])]
    public function cards(Request $request): JsonResponse
    {
        [$page, $size] = $this->pagination($request);

        return new JsonResponse($this->catalog->cards(
            $this->language($request),
            mb_substr(trim($request->query->getString('q', $request->query->getString('query'))), 0, 100),
            $request->query->getString('set'),
            $request->query->getString('type'),
            $request->query->getString('sort', 'catalogue'),
            $page,
            $size,
        ));
    }

    #[Route('/api/sets', methods: ['GET'])]
    public function sets(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->catalog->sets($this->language($request))]);
    }

    #[Route('/api/albums', methods: ['GET'])]
    public function albums(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => $this->catalog->albums($this->language($request))]);
    }

    #[Route('/api/card', methods: ['GET'])]
    public function card(Request $request): JsonResponse
    {
        $id = $request->query->getString('id');
        if (!preg_match('/^[a-z0-9][a-z0-9!%?._-]{0,99}$/iD', $id)) {
            return new JsonResponse(['error' => 'Identifiant de carte invalide.'], 400);
        }

        return $this->api(fn () => $this->catalog->cardDetail($this->language($request), $id));
    }

    #[Route('/api/booster', methods: ['POST'])]
    #[Route('/api/collection/booster', defaults: ['_private_account' => true], methods: ['POST'])]
    public function booster(Request $request, Postgres $database, AccountCollection $collection): JsonResponse
    {
        return $this->api(function () use ($request, $database, $collection): array {
            $language = $this->language($request);
            $set = $request->query->getString('set');
            $type = $request->query->getString('type');
            $cards = $this->catalog->booster($language, $set, $type);
            if ($request->attributes->get('_private_account')) {
                try {
                    $remaining = $collection->recordBooster($request->attributes->getString('rom.owner'), $language, $set, $type, $cards);
                } catch (\PDOException) {
                    throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(null, 'Impossible d’enregistrer ce booster dans la collection.');
                }

                return ['data' => $cards, 'remaining' => $remaining];
            }
            if ($database->enabled()) {
                try {
                    $statement = $database->connection()->prepare(
                        'INSERT INTO booster_openings (locale, set_filter, type_filter, cards) VALUES (?, ?, ?, CAST(? AS jsonb))'
                    );
                    $statement->execute([$language, $set, $type, json_encode($cards, JSON_THROW_ON_ERROR)]);
                } catch (\PDOException) {
                    throw new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(
                        null, 'Impossible d’enregistrer ce booster dans PostgreSQL.'
                    );
                }
            }

            return ['data' => $cards];
        });
    }

    #[Route('/api/pokemon', methods: ['GET'])]
    public function dex(Request $request): JsonResponse
    {
        [$page, $size] = $this->pagination($request);

        return new JsonResponse($this->catalog->dex(
            mb_substr(trim($request->query->getString('q')), 0, 100),
            $request->query->getBoolean('forms'),
            $page,
            $size,
            $request->query->getString('mode'),
            $request->query->getString('type'),
            array_fill_keys(explode(',', mb_substr($request->query->getString('favorites'), 0, 2400)), true),
        ));
    }

    #[Route('/api/pokemon/{id}', requirements: ['id' => '\d{1,6}'], methods: ['GET'])]
    public function pokemon(int $id): JsonResponse
    {
        return $this->api(fn () => $this->catalog->pokemonDetail($id));
    }

    #[Route('/api/pokemon/{id}/cry/{variant}', requirements: ['id' => '\d{1,6}', 'variant' => 'latest|legacy'], methods: ['GET'])]
    public function pokemonCry(int $id, string $variant): Response
    {
        try {
            $cry = $this->catalog->pokemonCry($id, $variant);
        } catch (HttpExceptionInterface $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (ExceptionInterface) {
            return new JsonResponse(['error' => 'La source audio de PokéAPI est temporairement indisponible.'], 502);
        }

        return new Response($cry['audio'], Response::HTTP_OK, [
            'Content-Type' => $cry['mime'],
            'Content-Length' => (string) strlen($cry['audio']),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function language(Request $request): string
    {
        return $request->query->getString('lang') === 'en' ? 'en' : 'fr';
    }

    private function pagination(Request $request): array
    {
        return [
            max(1, min(100000, $request->query->getInt('page', 1))),
            max(1, min(100, $request->query->getInt('pageSize', 30))),
        ];
    }

    private function api(callable $load): JsonResponse
    {
        try {
            return new JsonResponse($load());
        } catch (HttpExceptionInterface $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getStatusCode());
        } catch (ExceptionInterface) {
            return new JsonResponse(['error' => 'Le service de détails est momentanément indisponible. Réessayez dans un instant.'], 502);
        }
    }
}
