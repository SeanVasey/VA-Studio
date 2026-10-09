<?php
$h = fopen($argv[1], 'c+b'); var_dump(flock($h, LOCK_EX | LOCK_NB)); fflush(STDOUT);
// Simulate an echo blocked on SAPI backpressure: a blocking write to a socket whose peer never reads.
[$a, $b] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
stream_set_blocking($a, true);
while (true) { fwrite($a, str_repeat('x', 65536)); }
