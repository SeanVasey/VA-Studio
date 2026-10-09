#!/bin/bash
# Compares the CLI and FPM binaries' build identity: version line, built-in modules under -n, extension build IDs,
# and the linked zlib (gzcompress output feeds the PDF bytes).
for bin in /usr/bin/php8.4 /usr/sbin/php-fpm8.4 /usr/bin/php8.3; do
    echo "== $bin"
    "$bin" -v 2>/dev/null | head -1
    "$bin" -n -m 2>/dev/null | tr '\n' ' '
    echo
    "$bin" -n -i 2>/dev/null | grep -E '^(PHP API|PHP Extension Build|Zend Extension Build|Linked Version|ZLib Version|Thread Safety|Debug Build) =>'
done
echo "== ldd zlib"
ldd /usr/bin/php8.4 | grep -E 'libz\.'
ldd /usr/sbin/php-fpm8.4 | grep -E 'libz\.'
