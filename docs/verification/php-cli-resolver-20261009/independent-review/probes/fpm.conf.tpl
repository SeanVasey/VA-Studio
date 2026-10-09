[global]
pid = __RUN__/fpm.pid
error_log = __RUN__/fpm-error.log
daemonize = no

[review]
user = root
group = root
listen = __RUN__/fpm.sock
pm = static
pm.max_children = __MAXC__
clear_env = yes
catch_workers_output = yes
request_terminate_timeout = 300
env[REVIEW_ROOT] = __ROOT__
env[REVIEW_CAPTURE_DIR] = __CAPTURE__
; Hostile inherited values the probe/renderer environment scrub must remove before any child starts.
env[PHPRC] = /nonexistent-review-phprc
env[PHP_INI_SCAN_DIR] = /nonexistent-review-scan
env[LD_PRELOAD] = /nonexistent-review-preload.so
env[LD_LIBRARY_PATH] = /nonexistent-review-libs
env[REVIEW_SECRET_MARKER] = review-secret-value
__EXTRA__
