<?php

declare(strict_types=1);

namespace App;

use Aws\Exception\AwsException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/roms', defaults: ['_private_rom_library' => true])]
final class RomController
{
    private const HEADERS = ['Cache-Control' => 'private, no-store'];

    public function __construct(
        private readonly RomLibrary $roms,
        private readonly RomSaves $saves,
        private readonly CloudRomStorage $cloud,
        private readonly PostgresRomSaves $databaseSaves,
        private readonly PostgresRomLibrary $databaseRoms,
    ) {}

    #[Route('', methods: ['GET'])]
    public function roms(): Response
    {
        return $this->respond(function () {
            $postgres = $this->databaseRoms->enabled();
            $cloud = $this->usesBucket();
            $games = $cloud ? $this->cloud->list() : $this->roms->list();
            if ($postgres) {
                $byId = array_column($games, null, 'id');
                foreach ($this->databaseRoms->list() as $game) {
                    $byId[$game['id']] = $game;
                }
                $games = array_values($byId);
                usort($games, static fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
            }

            return new JsonResponse([
                'cloud' => $cloud,
                'postgres' => $postgres,
                'cloudSaves' => $cloud && !$this->databaseSaves->enabled(),
                'data' => $games,
            ], 200, self::HEADERS);
        });
    }

    #[Route('/{id}', requirements: ['id' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function playRom(string $id, Request $request): Response
    {
        return $this->respond(function () use ($id, $request) {
            if ($this->databaseRoms->enabled() && ($game = $this->databaseRoms->find($id)) !== null) {
                return $this->databaseRoms->response($game, $request);
            }
            $download = $request->query->getBoolean('download');
            if ($this->usesBucket()) {
                return new RedirectResponse($this->cloud->download($id, $download), 302, self::HEADERS);
            }
            $file = $this->roms->file($id);
            $response = $this->binary($file);
            if ($download) {
                $this->attachment($response, basename($file), 'jeu.'.pathinfo($file, PATHINFO_EXTENSION));
            }

            return $response;
        });
    }

    #[Route('/{id}/sauvegardes', requirements: ['id' => '[a-f0-9]{64}'], methods: ['GET'])]
    public function saves(string $id, Request $request): Response
    {
        return $this->respond(function () use ($id, $request) {
            $this->assertRom($id);
            $saves = $this->databaseSaves->enabled() ? $this->databaseSaves->list($id, $request->attributes->getString('rom.owner')) :
                ($this->usesBucket() ? $this->cloud->list($id) : $this->saves->list($id));

            return new JsonResponse(['data' => $saves], 200, self::HEADERS);
        });
    }

    #[Route('/{id}/sauvegardes', requirements: ['id' => '[a-f0-9]{64}'], methods: ['POST'])]
    public function save(string $id, Request $request): Response
    {
        return $this->respond(function () use ($id, $request) {
            $this->assertRom($id);
            if ($this->usesBucket() && !$this->databaseSaves->enabled()) {
                throw new BadRequestHttpException('Utilisez l’envoi direct au stockage en ligne.');
            }
            $file = $this->upload($request, 'save');
            $save = $this->databaseSaves->enabled() ? $this->databaseSaves->upload($id, $request->attributes->getString('rom.owner'), $file) : $this->saves->upload($id, $file);

            return new JsonResponse($save, 201, self::HEADERS);
        });
    }

    #[Route('/{id}/sauvegardes/{save}', requirements: ['id' => '[a-f0-9]{64}', 'save' => '[a-f0-9]{32}'], methods: ['GET'])]
    public function downloadSave(string $id, string $save, Request $request): Response
    {
        return $this->respond(function () use ($id, $save, $request) {
            if ($this->databaseSaves->enabled()) {
                $this->assertRom($id);
                [$content, $name] = $this->databaseSaves->read($id, $request->attributes->getString('rom.owner'), $save);
                $response = new Response($content, 200, self::HEADERS + ['Content-Type' => 'application/octet-stream']);
                $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, 'sauvegarde.'.pathinfo($name, PATHINFO_EXTENSION)
                ));

                return $response;
            }
            if ($this->usesBucket()) {
                return new RedirectResponse($this->cloud->download($id, true, $save), 302, self::HEADERS);
            }
            $this->roms->file($id);
            [$file, $name] = $this->saves->file($id, $save);
            $response = $this->binary($file);
            $this->attachment($response, $name, 'sauvegarde.'.pathinfo($name, PATHINFO_EXTENSION));

            return $response;
        });
    }

    #[Route('/transferts', methods: ['POST'])]
    public function beginUpload(Request $request): Response
    {
        return $this->respond(function () use ($request) {
            if ($this->databaseSaves->enabled()) {
                throw new BadRequestHttpException('Les sauvegardes sont envoyées à PostgreSQL.');
            }
            $data = $request->getPayload();
            $upload = $this->cloud->begin($request->getSession(), $data->getString('name'), $data->getInt('size'), $data->has('romId') ? $data->getString('romId') : null);

            return new JsonResponse($upload, 201, self::HEADERS);
        });
    }

    #[Route('/transferts/{token}/morceaux/{part}', requirements: ['token' => '[a-f0-9]{32}', 'part' => '\d{1,4}'], methods: ['POST'])]
    public function signPart(string $token, int $part, Request $request): Response
    {
        return $this->respond(fn () => new JsonResponse(['url' => $this->cloud->part($request->getSession(), $token, $part)], 200, self::HEADERS));
    }

    #[Route('/transferts/{token}/terminer', requirements: ['token' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function finishUpload(string $token, Request $request): Response
    {
        return $this->respond(fn () => new JsonResponse($this->cloud->finish($request->getSession(), $token), 201, self::HEADERS));
    }

    #[Route('/transferts/{token}/annuler', requirements: ['token' => '[a-f0-9]{32}'], methods: ['POST'])]
    public function abortUpload(string $token, Request $request): Response
    {
        return $this->respond(function () use ($token, $request) {
            $this->cloud->abort($request->getSession(), $token);

            return new JsonResponse(['cancelled' => true], 200, self::HEADERS);
        });
    }

    private function usesBucket(): bool
    {
        return !$this->databaseRoms->enabled() && $this->cloud->enabled();
    }

    private function assertRom(string $id): void
    {
        if ($this->databaseRoms->enabled() && $this->databaseRoms->find($id) !== null) {
            return;
        }
        if ($this->usesBucket()) {
            $this->cloud->assertRom($id);

            return;
        }
        $this->roms->file($id);
    }

    private function binary(string $file): BinaryFileResponse
    {
        return new BinaryFileResponse($file, Response::HTTP_OK, self::HEADERS + [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
        ], false);
    }

    private function attachment(BinaryFileResponse $response, string $name, string $fallback): void
    {
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, $fallback);
    }

    private function upload(Request $request, string $name): UploadedFile
    {
        $file = $request->files->get($name);
        if (!$file instanceof UploadedFile) {
            throw new BadRequestHttpException('Aucun fichier reçu. Vérifiez sa taille et la limite d’envoi du serveur.');
        }

        return $file;
    }

    private function respond(callable $work): Response
    {
        try {
            return $work();
        } catch (HttpExceptionInterface $e) {
            return new JsonResponse(['error' => $e->getMessage()], $e->getStatusCode(), self::HEADERS);
        } catch (\PDOException) {
            return new JsonResponse(['error' => 'Le stockage PostgreSQL est momentanément indisponible.'], 503, self::HEADERS);
        } catch (AwsException) {
            return new JsonResponse(['error' => 'Le stockage privé ne répond pas. Vérifiez le bucket et ses autorisations.'], 502, self::HEADERS);
        }
    }
}
