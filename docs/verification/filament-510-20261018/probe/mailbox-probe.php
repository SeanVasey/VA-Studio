<?php
// Throwaway probe, not part of the repo: shows what Filament 5.10.2 writes to a database cache store.
require '/home/user/wt/m3/vendor/autoload.php';
$app = require '/home/user/wt/m3/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'database', 'session.driver' => 'array']);
Illuminate\Support\Facades\Cache::forgetDriver('database');
Illuminate\Support\Facades\Schema::create('cache', function ($t) { $t->string('key')->primary(); $t->mediumText('value'); $t->bigInteger('expiration')->index(); });
Illuminate\Support\Facades\Schema::create('cache_locks', function ($t) { $t->string('key')->primary(); $t->string('owner'); $t->bigInteger('expiration')->index(); });
$session = app('session.store'); $session->start();
Filament\Notifications\Notification::make()->danger()->title('PROBE-TITLE')->body('PROBE-BODY operator text')->send();
$box = app(Filament\Notifications\NotificationMailbox::class);
echo "pending in memory only, rows before flush: ", Illuminate\Support\Facades\DB::table('cache')->count(), "\n";
$box->flush();
foreach (Illuminate\Support\Facades\DB::table('cache')->get() as $r) {
    echo "key=", $r->key, "\nexpires_in_min=", round(($r->expiration - time()) / 60), "\nvalue=", $r->value, "\n";
}
echo "session keys: ", json_encode(array_keys($session->all())), "\n";
echo "session mailbox value: ", $session->get('filament.notifications.mailbox'), "\n";
echo "pull -> ", json_encode(array_map(fn ($n) => [$n['title'], $n['body']], $box->pull())), "\n";
echo "rows after pull: ", Illuminate\Support\Facades\DB::table('cache')->where('key', 'like', '%filament:notifications%')->count(), "\n";
// An abandoned mailbox: flush, then let it expire without a read.
Filament\Notifications\Notification::make()->title('ABANDONED')->body('never rendered')->send(); $box->flush();
Illuminate\Support\Facades\DB::table('cache')->update(['expiration' => time() - 10]);
echo "expired-but-unread rows still present: ", Illuminate\Support\Facades\DB::table('cache')->where('key', 'like', '%filament:notifications%')->count(), "\n";
