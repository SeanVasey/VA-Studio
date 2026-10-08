<?php
// Use the current worktree's generated Composer autoload, not the symlinked main checkout's.
$GLOBALS['_composer_autoload_path'] = getcwd().'/vendor/autoload.php';
require getcwd().'/vendor/phpunit/phpunit/phpunit';
