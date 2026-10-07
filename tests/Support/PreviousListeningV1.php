<?php

namespace Tests\Support;

use RuntimeException;

/** Execute the pinned actual pre-notes reader, renaming only its class to avoid a collision. */
final class PreviousListeningV1
{
    public const SOURCE = 'ca3b1fa7eb60edd2228b73742da33179228a277e';

    public const SHA256 = '30aca52bd3d15e314bdfa6bad146870991a1269a4178cd64d9c27b7e376aa2a7';

    public static function reader(): object
    {
        $source = file_get_contents(__DIR__.'/../Fixtures/customer-listening-v1/ListeningLibrary.php.txt');
        if (! is_string($source) || hash('sha256', $source) !== self::SHA256) {
            throw new RuntimeException('Pinned predecessor reader fixture changed.');
        }
        $class = 'App\\Domain\\Customers\\Listening\\PreviousListeningLibrary';
        if (! class_exists($class, false)) {
            // Trusted pinned test source only; no product/user input is evaluated.
            eval(str_replace('final class ListeningLibrary', 'final class PreviousListeningLibrary', substr($source, 5)));
        }

        return new $class;
    }
}
