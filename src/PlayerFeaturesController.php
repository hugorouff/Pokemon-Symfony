<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class PlayerFeaturesController
{
    private const KIND = 'decks|wishlist|offers|shelf|journal-[a-f0-9]{64}';
    private const TOKEN = '[a-f0-9]{32}';

    public function __construct(
        private readonly PlayerFeatures $features,
        private readonly CardTrades $trades,
        private readonly PostgresRomLibrary $databaseRoms,
        private readonly RomLibrary $localRoms,
        private readonly CloudRomStorage $bucket,
    ) {}

    #[Route('/api/player/{kind}', requirements: ['kind' => self::KIND], defaults: ['_private_account' => true], methods: ['GET', 'PUT'])]
    public function feature(Request $request, string $kind): JsonResponse
    {
        return $this->respond(function () use ($request, $kind) {
            $owner = $this->owner($request);
            if (str_starts_with($kind, 'journal-')) {
                $rom = substr($kind, 8);
                if ($this->databaseRoms->enabled()) {
                    if ($this->databaseRoms->find($rom) === null) {
                        $this->localRoms->file($rom);
                    }
                } elseif ($this->bucket->enabled()) {
                    $this->bucket->assertRom($rom);
                } else {
                    $this->localRoms->file($rom);
                }
            }
            if ($request->isMethod('GET')) {
                return $this->features->get($owner, $kind);
            }
            $payload = $this->body($request);
            if (!is_int($payload['version'] ?? null) || $payload['version'] < 0) {
                throw new BadRequestHttpException('Version de la modification invalide.');
            }

            return $this->features->put($owner, $kind, $payload['data'] ?? null, $payload['version']);
        });
    }

    #[Route('/api/shelf/{token}', requirements: ['token' => self::TOKEN], methods: ['GET'])]
    public function shelf(string $token): JsonResponse
    {
        return $this->respond(fn () => ['data' => $this->features->shelf($token)]);
    }

    #[Route('/api/trades', defaults: ['_private_account' => true], methods: ['GET', 'POST'])]
    public function trade(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $owner = $this->owner($request);
            if ($request->isMethod('GET')) {
                return ['data' => $this->trades->list($owner), 'offers' => $this->trades->offers($owner)];
            }
            $payload = $this->body($request);
            $friend = $payload['friend'] ?? null;
            if (!is_string($friend) || !preg_match('/^[a-f0-9]{32}$/D', $friend)) {
                throw new BadRequestHttpException('Code ami invalide.');
            }
            $this->trades->propose($owner, $friend, PlayerFeatures::cardId($payload['give'] ?? null), PlayerFeatures::cardId($payload['take'] ?? null));

            return ['ok' => true];
        });
    }

    #[Route('/api/trades/friend/{code}', requirements: ['code' => self::TOKEN], defaults: ['_private_account' => true], methods: ['GET'])]
    public function friend(Request $request, string $code): JsonResponse
    {
        return $this->respond(fn () => $this->trades->profile($this->owner($request), $code));
    }

    #[Route('/api/trades/{id}/{action}', requirements: ['id' => self::TOKEN, 'action' => 'accept|reject|cancel'], defaults: ['_private_account' => true], methods: ['POST'])]
    public function decide(Request $request, string $id, string $action): JsonResponse
    {
        return $this->respond(function () use ($request, $id, $action) {
            $this->trades->decide($this->owner($request), $id, $action);

            return ['ok' => true];
        });
    }

    private function owner(Request $request): string
    {
        return $request->attributes->getString('rom.owner');
    }

    private function body(Request $request): array
    {
        if (strlen($request->getContent()) > 2_000_000) {
            throw new BadRequestHttpException('Données trop volumineuses.');
        }

        return $request->toArray();
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
            return new JsonResponse(['error' => 'Cette fonction est momentanément indisponible.'], 503, $headers);
        }
    }
}
