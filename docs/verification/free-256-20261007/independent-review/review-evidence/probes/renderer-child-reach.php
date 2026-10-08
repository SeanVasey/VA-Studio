<?php

// Review probe child: run under the exact ini flags and environment the 256 renderer gives its child, to record what
// that sandbox can reach. It prints JSON only. Argument 1 is the project root; argument 2 a private-root file path.
$root = $argv[1] ?? '';
$private = $argv[2] ?? '';
$result = [
    'env_count' => count(getenv()),
    'env_app_key' => getenv('APP_KEY') !== false,
    'open_basedir' => ini_get('open_basedir'),
    'allow_url_fopen' => ini_get('allow_url_fopen'),
    'disable_functions' => ini_get('disable_functions'),
    'dotenv_readable' => @is_readable($root.'/.env'),
    'dotenv_bytes' => strlen((string) @file_get_contents($root.'/.env')) > 0,
    'private_file_readable_if_under_root' => $private !== '' && @is_readable($private),
    'etc_passwd_readable' => @is_readable('/etc/passwd'),
    'http_fetch' => @file_get_contents('http://127.0.0.1:1/') !== false,
    'fsockopen_callable' => is_callable('fsockopen'),
    'stream_socket_client_callable' => is_callable('stream_socket_client'),
    'mail_callable' => is_callable('mail'),
    'gethostbyname_callable' => is_callable('gethostbyname'),
    'dns_get_record_callable' => is_callable('dns_get_record'),
    'curl_init_callable' => is_callable('curl_init'),
    'proc_open_callable' => is_callable('proc_open'),
    'pcntl_exec_callable' => is_callable('pcntl_exec'),
    'putenv_callable' => is_callable('putenv'),
    'ini_set_open_basedir_widen' => @ini_set('open_basedir', '/') !== false && ini_get('open_basedir') === '/',
];
echo json_encode($result, JSON_UNESCAPED_SLASHES);
