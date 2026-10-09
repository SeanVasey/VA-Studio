[global]
pid = __DIR__/fpm-__NAME__.pid
error_log = __DIR__/fpm-__NAME__-error.log
daemonize = no

[m16]
user = root
group = root
listen = __DIR__/fpm-__NAME__.sock
pm = static
pm.max_children = 2
clear_env = yes
catch_workers_output = yes
request_terminate_timeout = 300
env[M16_ROOT] = __ROOT__
env[M16_CAPTURE_DIR] = __DIR__/capture
__EXTRA__
