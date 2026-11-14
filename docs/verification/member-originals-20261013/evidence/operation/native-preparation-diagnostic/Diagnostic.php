<?php
namespace Tests\Support;
final class MemberOriginalNativeDiagnostic {
    private static ?int $started = null;
    public static function emit(string $phase, ?int $deadline = null, ?\Throwable $error = null): void {
        $now = \hrtime(true); self::$started ??= $now;
        $record = ['phase' => $phase, 'elapsed_ms' => round(($now-self::$started)/1_000_000, 3),
            'remaining_ms' => $deadline === null ? null : round(($deadline-$now)/1_000_000, 3)];
        if ($error !== null) {
            $record['exception_class'] = get_class($error);
            $record['reason'] = property_exists($error, 'reason') ? $error->reason : null;
            $record['site'] = basename($error->getFile()).':'.$error->getLine();
            $record['frames'] = array_map(static fn (array $f): string => ($f['class'] ?? '').($f['type'] ?? '').($f['function'] ?? ''), array_slice($error->getTrace(), 0, 10));
        }
        \fwrite(\STDERR, 'MEMBER_DIAGNOSTIC '.json_encode($record, JSON_UNESCAPED_SLASHES)."\n");
    }
}
