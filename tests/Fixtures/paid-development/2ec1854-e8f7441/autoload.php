<?php

spl_autoload_register(static function (string $class): void {
    foreach (['App\\' => 'app/', 'Tests\\' => 'tests/'] as $prefix => $folder) {
        if (str_starts_with($class, $prefix)) {
            $file = __DIR__.'/'.$folder.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
            if (is_file($file)) {
                require_once $file;

                return;
            }
        }
    }
}, true, true);
