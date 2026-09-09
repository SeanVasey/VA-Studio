<?php

namespace Tests\Support;

/** Runtime synthetic ZIPs, including structures normal ZIP writers refuse to emit. */
class StemsFixtures
{
    public static function zip(array $members): string
    {
        $local = $central = '';
        foreach ($members as $member) {
            $name = $member['name'];
            $bytes = $member['bytes'] ?? MediaFixtures::wav(0.2);
            $method = $member['method'] ?? 0;
            $data = $method === 8 ? gzdeflate($bytes) : $bytes;
            $size = $member['size'] ?? strlen($bytes);
            $crc = $member['crc'] ?? crc32($bytes);
            $flags = $member['flags'] ?? 0;
            $attributes = ($member['mode'] ?? 0100600) << 16;
            $offset = strlen($local);
            $local .= "PK\x03\x04".pack('vvvvvVVVvv', 20, $flags, $method, 0, 0, $crc, strlen($data), $size, strlen($name), 0).$name.$data;
            $central .= "PK\x01\x02".pack('vvvvvvVVVvvvvvVV', (3 << 8) | 20, 20, $flags, $method, 0, 0, $crc, strlen($data), $size, strlen($name), 0, 0, 0, 0, $attributes, $offset).$name;
        }

        return $local.$central."PK\x05\x06".pack('vvvvVVv', 0, 0, count($members), count($members), strlen($central), strlen($local), 0);
    }
}
