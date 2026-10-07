<?php

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\Free\FreeGrantPdfRenderer;

// Protocol-only child: deliberately no Laravel application, .env loading, DB or provider client.
require __DIR__.'/contract-renderer-autoload.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\Domain\\Grants\\Free\\';
    $name = substr($class, strlen($prefix));
    if (str_starts_with($class, $prefix) && in_array($name, ['FreeGrantRenderProfile', 'FreeGrantText', 'FreeGrantPdfRenderer'], true)) {
        require dirname(__DIR__).'/app/Domain/Grants/Free/'.$name.'.php';
    }
});

try {
    $raw = stream_get_contents(STDIN, 1048577);
    if (! is_string($raw) || strlen($raw) > 1048576) {
        throw new RuntimeException;
    }
    $payload = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
    if (! is_array($payload) || array_diff(array_keys($payload), ['input', 'profile']) !== []
        || ! is_array($payload['input'] ?? null) || ! is_array($payload['profile'] ?? null)) {
        throw new RuntimeException;
    }
    $rendered = (new FreeGrantPdfRenderer(dirname(__DIR__)))->render($payload['input'], $payload['profile']);
    if ($rendered->sizeBytes > 16777216 || $rendered->pageCount > 100) {
        throw new RuntimeException;
    }
    $encoded = json_encode(['pdf_base64' => base64_encode($rendered->pdfBytes), 'sha256' => $rendered->sha256,
        'size_bytes' => $rendered->sizeBytes, 'page_count' => $rendered->pageCount,
        'text_digest' => $rendered->textDigest, 'profile_hash' => $rendered->profileHash], JSON_THROW_ON_ERROR);
    if (strlen($encoded) > 24117248) {
        throw new RuntimeException;
    }
    $offset = 0;
    while ($offset < strlen($encoded)) {
        $written = fwrite(STDOUT, substr($encoded, $offset, 1048576));
        if (! is_int($written) || $written < 1) {
            throw new RuntimeException;
        }
        $offset += $written;
    }
    exit(0);
} catch (ContractIssuanceException $error) {
    $reason = in_array($error->reason, ['profile_changed', 'unsupported_input', 'invalid_pdf', 'render_failed'], true)
        ? $error->reason : 'render_failed';
    fwrite(STDOUT, json_encode(['error' => $reason], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable) {
    // No input, path, provider data or error text may cross the failure boundary.
    exit(1);
}
