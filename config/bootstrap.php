<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$root = dirname(__DIR__);
foreach (['.env', '.env.local'] as $file) {
    if (is_file($root.'/'.$file)) {
        (new Dotenv())->load($root.'/'.$file);
    }
}
