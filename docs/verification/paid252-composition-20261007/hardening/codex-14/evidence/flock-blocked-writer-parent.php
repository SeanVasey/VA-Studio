<?php
$lock = __DIR__.'/slot.lock'; @unlink($lock);
$proc = proc_open([PHP_BINARY, __DIR__.'/child.php', $lock], [1 => ['pipe', 'w']], $pipes);
echo 'child locked: ', trim(fgets($pipes[1])), "\n";
sleep(2);
$h = fopen($lock, 'c+b');
echo 'parent while child blocked in write: ', var_export(flock($h, LOCK_EX | LOCK_NB), true), "\n";
$pid = proc_get_status($proc)['pid'];
echo 'child state: ', trim((string) shell_exec("ps -o stat=,wchan= -p $pid")), "\n";
posix_kill($pid, 9); usleep(300000);
echo 'parent after SIGKILL: ', var_export(flock($h, LOCK_EX | LOCK_NB), true), "\n";
