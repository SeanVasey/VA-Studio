<?php

namespace App\Domain\Services;

use App\Domain\ProductAuthoring\PrivateDraftManifest;

final class ServiceDraftManifest extends PrivateDraftManifest
{
    public function kind(): string
    {
        return 'service';
    }

    protected function content(array $input): array
    {
        $this->keys($input, ['title', 'description', 'brief_questions', 'scope', 'deposit', 'revisions', 'cancellation']);
        $questions = $input['brief_questions'];
        if (! is_array($questions) || ! array_is_list($questions) || count($questions) > 20) {
            $this->reject('brief_questions', 'Supply up to 20 brief questions in their intended order.');
        }
        $questions = array_map(fn ($question): string => $this->text($question, 500, 'brief_questions', true), $questions);
        if (count(array_unique($questions)) !== count($questions)) {
            $this->reject('brief_questions', 'Each brief question may appear only once.');
        }

        return ['title' => $this->text($input['title'], 180, 'title', true),
            'description' => $this->text($input['description'], 4000, 'description'), 'brief_questions' => $questions,
            'scope' => $this->declaration($input['scope'], 'scope'), 'deposit' => $this->declaration($input['deposit'], 'deposit'),
            'revisions' => $this->declaration($input['revisions'], 'revisions'), 'cancellation' => $this->declaration($input['cancellation'], 'cancellation')];
    }
}
