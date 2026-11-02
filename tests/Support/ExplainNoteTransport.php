<?php

namespace Tests\Support;

/** Closed test-only operations; no caller SQL, callback, configuration or production provider. */
interface ExplainNoteTransport
{
    public function emulated(): mixed;

    public function setEmulated(bool $value): bool;

    public function finishOffender(): bool;

    public function warningCount(): int;

    public function warningRows(): iterable;

    public function prepareWarningRows(): iterable;

    public function closeDiagnostic(): bool;

    public function closeOffender(): bool;
}
