<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function getCacheDir(): string
    {
        return sprintf('%s/var/cache/symfony-%d.%d/%s', $this->getProjectDir(), self::MAJOR_VERSION, self::MINOR_VERSION, $this->environment);
    }

    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
        $configured = $_SERVER['APP_SECRET'] ?? $_ENV['APP_SECRET'] ?? getenv('APP_SECRET');
        if (is_string($configured) && $configured !== '') {
            return;
        }

        $directory = $this->getProjectDir().'/var';
        (new Filesystem())->mkdir($directory);
        $file = fopen($directory.'/app-secret', 'c+b');
        if ($file === false) {
            throw new \RuntimeException('Le répertoire var/ doit être accessible en écriture.');
        }

        try {
            if (!flock($file, LOCK_EX)) {
                throw new \RuntimeException('Impossible de verrouiller le fichier de configuration local.');
            }
            $secret = stream_get_contents($file);
            if (!is_string($secret) || !preg_match('/^[a-f0-9]{64}$/D', $secret)) {
                $secret = bin2hex(random_bytes(32));
                rewind($file);
                ftruncate($file, 0);
                if (fwrite($file, $secret) !== strlen($secret) || !fflush($file)) {
                    throw new \RuntimeException('Impossible d’enregistrer la configuration locale.');
                }
            }
            $_SERVER['APP_SECRET'] = $secret;
        } finally {
            fclose($file);
        }
    }
}
