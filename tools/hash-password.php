#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "À utiliser en ligne de commande uniquement.\n");
    exit(1);
}

$password = $argv[1] ?? null;
if ($password === null) {
    $hidden = function_exists('posix_isatty') && posix_isatty(STDIN);
    if ($hidden) {
        echo 'Mot de passe : ';
        system('stty -echo');
        $password = trim((string) fgets(STDIN));
        system('stty echo');
        echo PHP_EOL;
    } else {
        $password = trim((string) fgets(STDIN));
    }
}

if ($password === '') {
    fwrite(STDERR, "Mot de passe vide.\n");
    exit(1);
}

echo password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
