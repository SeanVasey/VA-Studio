<?php

namespace App\Domain\ProductAuthoring;

use App\Support\CanonicalJson;
use Illuminate\Validation\ValidationException;

/** Private descriptive evidence only. An authored declaration is never operational approval. */
abstract class PrivateDraftManifest
{
    abstract public function kind(): string;

    abstract protected function content(array $input): array;

    public function make(array $input): array
    {
        $manifest = ['schema' => $this->kind().'-private-draft-v1', 'canonicalization' => CanonicalJson::VERSION,
            'kind' => $this->kind(), ...$this->content($input)];
        if (strlen(CanonicalJson::encode($manifest)) > 65536) {
            $this->reject('title', 'Keep this private draft within 64 KiB. Split a larger definition into separate drafts.');
        }

        return $manifest;
    }

    public function verified(array $manifest): array
    {
        if (($manifest['schema'] ?? null) !== $this->kind().'-private-draft-v1'
            || ($manifest['canonicalization'] ?? null) !== CanonicalJson::VERSION || ($manifest['kind'] ?? null) !== $this->kind()) {
            $this->reject('title', 'This retained draft uses an unsupported evidence format. Preserve it for investigation.');
        }
        $input = array_diff_key($manifest, array_flip(['schema', 'canonicalization', 'kind']));
        $verified = $this->make($input);
        if (CanonicalJson::encode($verified) !== CanonicalJson::encode($manifest)) {
            $this->reject('title', 'This retained draft has inconsistent content. Preserve it for investigation.');
        }

        return $verified;
    }

    protected function keys(array $input, array $expected, string $field = 'title'): void
    {
        if (count($input) !== count($expected) || array_diff(array_keys($input), $expected) !== []) {
            $this->reject($field, 'Supply only the fields shown in this private editor.');
        }
    }

    protected function text(mixed $value, int $limit, string $field, bool $required = false): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value) > $limit
            || trim($value) !== $value || ($required && $value === '')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value)) {
            $this->reject($field, 'Enter plain text within the displayed limit, without control characters or surrounding whitespace.');
        }

        return $value;
    }

    protected function reference(mixed $value, string $field): ?string
    {
        return $value === null ? null : $this->text($value, 500, $field, true);
    }

    protected function declaration(mixed $value, string $field): array
    {
        if (! is_array($value)) {
            $this->reject($field, 'Choose authored text or explicitly unresolved information.');
        }
        $this->keys($value, ['status', 'text', 'reason'], $field);
        if ($value['status'] === 'authored' && $value['reason'] === null) {
            return ['status' => 'authored', 'text' => $this->text($value['text'], 2000, $field, true), 'reason' => null];
        }
        if ($value['status'] === 'unresolved' && $value['text'] === null) {
            return ['status' => 'unresolved', 'text' => null, 'reason' => $this->text($value['reason'], 1000, $field, true)];
        }
        $this->reject($field, 'Authored text needs a supplied declaration; unresolved information needs a reason and no declaration.');
    }

    protected function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
