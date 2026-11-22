<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/collection', defaults: ['_private_account' => true])]
final class CollectionController
{
    public function __construct(private readonly AccountCollection $collection) {}

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->collection->list(
            $this->owner($request), $request->query->has('id') ? $request->query->getString('id') : null
        )]);
    }

    #[Route('', methods: ['POST'])]
    public function merge(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $payload = $this->payload($request);
            $cards = $payload['cards'] ?? null;
            $mode = $payload['mode'] ?? null;
            if (!is_array($cards) || !array_is_list($cards) || count($cards) > 40000
                || !in_array($mode, ['add', 'import'], true) || ($mode === 'add' && count($cards) !== 1)) {
                throw new BadRequestHttpException('Liste de cartes invalide.');
            }
            $this->collection->merge($this->owner($request), $cards, $mode === 'add');

            return ['ok' => true];
        });
    }

    #[Route('', methods: ['PATCH'])]
    public function change(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request): array {
            $payload = $this->payload($request);
            if (!is_string($payload['id'] ?? null) || strlen($payload['id']) > 103
                || !is_int($payload['version'] ?? null) || !array_key_exists('card', $payload)
                || ($payload['card'] !== null && !is_array($payload['card']))) {
                throw new BadRequestHttpException('Modification de carte invalide.');
            }
            $this->collection->change($this->owner($request), $payload['id'], $payload['version'], $payload['card']);

            return ['ok' => true];
        });
    }

    #[Route('/limits', methods: ['GET'])]
    public function limits(Request $request): JsonResponse
    {
        return $this->respond(fn () => $this->collection->remaining($this->owner($request)));
    }

    private function payload(Request $request): array
    {
        if (strlen($request->getContent()) > 15_000_000) {
            throw new BadRequestHttpException('Le fichier dépasse 15 Mo.');
        }

        return $request->toArray();
    }

    private function owner(Request $request): string
    {
        return $request->attributes->getString('rom.owner');
    }

    private function respond(callable $action): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        try {
            return new JsonResponse($action(), 200, $headers);
        } catch (HttpExceptionInterface $error) {
            return new JsonResponse(['error' => $error->getMessage()], $error->getStatusCode(), $headers);
        } catch (\Symfony\Component\HttpFoundation\Exception\JsonException) {
            return new JsonResponse(['error' => 'Données JSON invalides.'], 400, $headers);
        } catch (\PDOException) {
            return new JsonResponse(['error' => 'La collection en ligne est momentanément indisponible.'], 503, $headers);
        }
    }
}
