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
    private const KIND = 'decks|wishlist|shelf|(?:journal|prefs)-[a-f0-9]{64}';
    private const TOKEN = '[a-f0-9]{32}';

    public function __construct(
        private readonly PlayerFeatures $features,
        private readonly PostgresRomLibrary $databaseRoms,
        private readonly RomLibrary $localRoms,
        private readonly CloudRomStorage $bucket,
    ) {}

    #[Route('/api/player/{kind}', requirements: ['kind' => self::KIND], defaults: ['_private_account' => true], methods: ['GET', 'PUT'])]
    public function feature(Request $request, string $kind): JsonResponse
    {
        return $this->respond(function () use ($request, $kind) {
            $owner = $this->owner($request);
            if (str_contains($kind, '-')) {
                $rom = substr($kind, strpos($kind, '-') + 1);
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
        } catch (\PDOException $error) {
            $state = $error->errorInfo[0] ?? (string) $error->getCode();
            error_log('Fonctionnalités joueur indisponibles (SQLSTATE '.$state.').');
            if (in_array($state, ['42P01', '42703'], true)) {
                return new JsonResponse(['error' => 'La base de données doit être mise à jour pour ouvrir cet onglet.'], 503, $headers);
            }

            return new JsonResponse(['error' => 'Cette fonction est momentanément indisponible.'], 503, $headers);
        }
    }
}
