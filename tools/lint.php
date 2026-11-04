<?php

declare(strict_types=1);

$directories = ['src', 'config', 'database', 'tests', 'tools', 'routes'];
$failed = false;

foreach ($directories as $directory) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../'.$directory)
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()), $status);
        $failed = $failed || $status !== 0;
    }
}

exit($failed ? 1 : 0);
