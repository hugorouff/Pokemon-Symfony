<?php

declare(strict_types=1);

namespace App;

use Aws\Exception\AwsException;
use Aws\S3\S3Client;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class CloudRomStorage
{
    private const CHUNK = 32 * 1024 * 1024;
    private const PENDING = 'rom.cloud_uploads';
    private ?S3Client $client = null;

    public function __construct(private readonly RomLibrary $roms, private readonly RomSaves $saves) {}

    public function enabled(): bool
    {
        return $this->setting('ROM_S3_BUCKET') !== '';
    }

    public function list(?string $romId = null): array
    {
        $prefix = $romId === null ? '' : $this->prefix().'saves/'.$romId.'/';
        $files = [];
        foreach ($this->s3()->getPaginator('ListObjectsV2', $this->bucket() + ['Prefix' => $prefix]) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $key = $object['Key'];
                $size = (int) $object['Size'];
                $modified = $object['LastModified']->getTimestamp();
                if ($romId === null) {
                    $rom = $this->romKey($key);
                    if ($rom !== null && $size > 0) {
                        [$id, $name] = $rom;
                        $files[$id] = $this->roms->describe($name, $size, $modified, $id);
                    }
                } elseif (preg_match('/^([a-f0-9]{32})\/([^\/]+)$/D', substr($key, strlen($prefix)), $match)) {
                    $files[] = ['id' => $match[1], 'name' => $match[2], 'size' => $size, 'modified' => $modified];
                }
            }
        }
        usort($files, static fn (array $a, array $b) => $romId === null ? strnatcasecmp($a['name'], $b['name'])
            : (($b['modified'] <=> $a['modified']) ?: strcmp($a['id'], $b['id'])));

        return $files;
    }

    public function allowOrigin(string $origin): void
    {
        $client = $this->s3();
        try {
            $rules = $client->getBucketCors($this->bucket())['CORSRules'] ?? [];
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() !== 'NoSuchCORSConfiguration') {
                throw $exception;
            }
            $rules = [];
        }
        $id = 'atlas-roms-'.substr(hash('sha256', $origin), 0, 16);
        $rules = array_values(array_filter($rules, static fn (array $rule): bool => ($rule['ID'] ?? null) !== $id));
        $rules[] = [
            'ID' => $id,
            'AllowedOrigins' => [$origin],
            'AllowedMethods' => ['GET', 'HEAD'],
            'AllowedHeaders' => ['Range'],
            'ExposeHeaders' => ['Content-Range', 'Last-Modified', 'ETag'],
            'MaxAgeSeconds' => 3600,
        ];
        $client->putBucketCors($this->bucket() + ['CORSConfiguration' => ['CORSRules' => $rules]]);
    }

    public function assertRom(string $romId): void
    {
        $this->findRom($romId);
    }

    public function download(string $id, bool $attachment, ?string $save = null): string
    {
        $romKey = $this->findRom($id);
        $key = $save === null ? $romKey : $this->find($this->prefix().'saves/'.$id.'/'.$save.'/');
        $options = ['ResponseCacheControl' => 'private, no-store'];
        if ($attachment) {
            $options['ResponseContentDisposition'] = (new ResponseHeaderBag())->makeDisposition(
                ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                basename($key),
                'fichier.'.preg_replace('/[^a-z\d]/i', '', pathinfo($key, PATHINFO_EXTENSION)),
            );
        }

        return $this->sign('GetObject', $key, $options, '+30 minutes');
    }

    public function deleteSave(string $rom, string $id): void
    {
        $key = $this->find($this->prefix().'saves/'.$rom.'/'.$id.'/');
        $this->s3()->deleteObject($this->bucket() + ['Key' => $key]);
    }

    public function begin(SessionInterface $session, string $name, int $size, ?string $romId): array
    {
        if (!$this->enabled()) {
            throw new HttpException(409, 'Le stockage en ligne n’est pas configuré.');
        }
        if ($romId === null || !preg_match('/^[a-f0-9]{64}$/D', $romId)) {
            throw new BadRequestHttpException('L’import est réservé aux sauvegardes de jeux existants.');
        }
        $this->assertRom($romId);
        $name = $this->saves->importName($name, $size);
        $uploads = array_filter($session->get(self::PENDING, []), static fn (array $upload): bool => $upload['expires'] >= time());
        if (count($uploads) >= 4) {
            throw new HttpException(429, 'Trop d’envois en cours dans cette session.');
        }

        $token = bin2hex(random_bytes(16));
        $key = $this->prefix().'saves/'.$romId.'/'.$token.'/'.$name;
        $multipart = $this->s3()->createMultipartUpload($this->bucket() + [
            'Key' => $key, 'ContentType' => 'application/octet-stream',
            'CacheControl' => 'private, no-store',
        ]);
        $uploads[$token] = [
            'key' => $key, 'uploadId' => (string) $multipart['UploadId'], 'size' => $size,
            'count' => (int) ceil($size / self::CHUNK), 'expires' => time() + 86400,
            'name' => $name, 'id' => $token, 'romId' => $romId,
        ];
        $session->set(self::PENDING, $uploads);

        return ['token' => $token, 'chunkSize' => self::CHUNK, 'parts' => $uploads[$token]['count']];
    }

    public function part(SessionInterface $session, string $token, int $part): string
    {
        $upload = $this->pending($session, $token);
        if ($part < 1 || $part > $upload['count']) {
            throw new BadRequestHttpException('Numéro de morceau invalide.');
        }

        return $this->sign('UploadPart', $upload['key'], [
            'UploadId' => $upload['uploadId'], 'PartNumber' => $part,
            'ContentLength' => $this->partSize($upload['size'], $part),
        ], '+20 minutes');
    }

    public function finish(SessionInterface $session, string $token): array
    {
        $upload = $this->pending($session, $token);
        $params = $this->bucket() + ['Key' => $upload['key'], 'UploadId' => $upload['uploadId']];
        try {
            $head = $this->s3()->headObject($this->bucket() + ['Key' => $upload['key']]);
            if ((int) $head['ContentLength'] !== $upload['size']) {
                throw new HttpException(409, 'La taille du fichier stocké est différente de celle attendue.');
            }
        } catch (AwsException $error) {
            if ($error->getStatusCode() !== 404) {
                throw $error;
            }
            $parts = [];
            foreach ($this->s3()->getPaginator('ListParts', $params) as $page) {
                foreach ($page['Parts'] ?? [] as $part) {
                    $number = (int) $part['PartNumber'];
                    if ($number !== count($parts) + 1 || $number > $upload['count'] || (int) $part['Size'] !== $this->partSize($upload['size'], $number)) {
                        throw new HttpException(409, 'Un morceau est manquant ou incomplet.');
                    }
                    $parts[] = ['ETag' => $part['ETag'], 'PartNumber' => $number];
                }
            }
            if (count($parts) !== $upload['count']) {
                throw new HttpException(409, 'Le transfert est incomplet.');
            }
            $this->s3()->completeMultipartUpload($params + ['MultipartUpload' => ['Parts' => $parts]]);
        }
        $this->forget($session, $token);

        return ['id' => $upload['id'], 'name' => $upload['name'], 'size' => $upload['size'], 'modified' => time()];
    }

    public function abort(SessionInterface $session, string $token): void
    {
        $upload = $this->pending($session, $token);
        try {
            $this->s3()->abortMultipartUpload($this->bucket() + ['Key' => $upload['key'], 'UploadId' => $upload['uploadId']]);
        } catch (AwsException $error) {
            if ($error->getStatusCode() !== 404) {
                throw $error;
            }
        }
        $this->forget($session, $token);
    }

    private function partSize(int $size, int $part): int
    {
        return min(self::CHUNK, $size - ($part - 1) * self::CHUNK);
    }

    private function sign(string $operation, string $key, array $options, string $expiry): string
    {
        $client = $this->s3();
        $command = $client->getCommand($operation, $this->bucket() + ['Key' => $key] + $options);

        return (string) $client->createPresignedRequest($command, $expiry)->getUri();
    }

    private function pending(SessionInterface $session, string $token): array
    {
        $upload = $session->get(self::PENDING, [])[$token] ?? null;
        if ($upload === null || $upload['expires'] < time() || $upload['romId'] === null) {
            throw new GoneHttpException('Cet envoi a expiré. Relancez l’import.');
        }

        return $upload;
    }

    private function forget(SessionInterface $session, string $token): void
    {
        $uploads = $session->get(self::PENDING, []);
        unset($uploads[$token]);
        $session->set(self::PENDING, $uploads);
    }

    private function romKey(string $key): ?array
    {
        if (preg_match('~^pokemon/roms/([a-f0-9]{64})/([^/]+)$~D', $key, $match)) {
            [, $id, $name] = $match;
        } elseif (!str_contains($key, '/')) {
            $name = $key;
            $id = hash('sha256', $key);
        } else {
            return null;
        }

        return array_key_exists(strtolower(pathinfo($name, PATHINFO_EXTENSION)), $this->roms->cores()) ? [$id, $name] : null;
    }

    private function findRom(string $id): string
    {
        foreach ($this->s3()->getPaginator('ListObjectsV2', $this->bucket()) as $page) {
            foreach ($page['Contents'] ?? [] as $object) {
                $key = $object['Key'];
                if (($this->romKey($key)[0] ?? null) === $id && (int) $object['Size'] > 0) {
                    return $key;
                }
            }
        }

        throw new NotFoundHttpException('Jeu introuvable dans le stockage privé.');
    }

    private function find(string $prefix): string
    {
        $result = $this->s3()->listObjectsV2($this->bucket() + ['Prefix' => $prefix, 'MaxKeys' => 2]);
        foreach ($result['Contents'] ?? [] as $object) {
            $key = $object['Key'];
            $name = substr($key, strlen($prefix));
            if ($name !== '' && !str_contains($name, '/')) {
                return $key;
            }
        }
        throw new NotFoundHttpException('Fichier introuvable dans le stockage privé.');
    }

    private function s3(): S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $endpoint = $this->setting('ROM_S3_ENDPOINT') ?: $this->setting('AWS_ENDPOINT_URL_S3');
        $region = $this->setting('ROM_S3_REGION') ?: $this->setting('AWS_REGION');
        $key = $this->setting('ROM_S3_ACCESS_KEY') ?: $this->setting('AWS_ACCESS_KEY_ID');
        $secret = $this->setting('ROM_S3_SECRET_KEY') ?: $this->setting('AWS_SECRET_ACCESS_KEY');
        if (!$region || !$key || !$secret) {
            throw new HttpException(503, 'La configuration du stockage en ligne est incomplète.');
        }

        $options = [
            'version' => 'latest', 'region' => $region,
            'credentials' => ['key' => $key, 'secret' => $secret],
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'http' => ['connect_timeout' => 5, 'timeout' => 25],
        ];
        if ($endpoint !== '') {
            $host = parse_url($endpoint, PHP_URL_HOST);
            if (!str_starts_with($endpoint, 'https://') && !in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true)) {
                throw new HttpException(503, 'Le stockage en ligne doit utiliser une adresse HTTPS.');
            }
            $options['endpoint'] = $endpoint;
            $options['use_path_style_endpoint'] = true;
        }

        return $this->client = new S3Client($options);
    }

    private function bucket(): array
    {
        return ['Bucket' => $this->setting('ROM_S3_BUCKET')];
    }

    private function prefix(): string
    {
        return 'pokemon/';
    }

    private function setting(string $key): string
    {
        return Postgres::setting($key);
    }
}
