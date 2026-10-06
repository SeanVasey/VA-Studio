<?php

namespace App\Domain\Merch;

use App\Domain\ProductAuthoring\PrivateDraftManifest;

final class MerchDraftManifest extends PrivateDraftManifest
{
    public function kind(): string
    {
        return 'merch';
    }

    protected function content(array $input): array
    {
        $this->keys($input, ['title', 'description', 'variants', 'source', 'shipping', 'returns']);
        if (! is_array($input['variants']) || ! array_is_list($input['variants'])
            || count($input['variants']) < 1 || count($input['variants']) > 50) {
            $this->reject('variants', 'Supply between 1 and 50 variants in their intended order.');
        }
        $variants = [];
        $ids = [];
        foreach ($input['variants'] as $variant) {
            if (! is_array($variant)) {
                $this->reject('variants', 'Every variant needs its own stable identity and descriptive fields.');
            }
            $this->keys($variant, ['id', 'label', 'size', 'color', 'source_reference', 'availability'], 'variants');
            if (! is_string($variant['id']) || ! preg_match('/\A[a-z0-9][a-z0-9_-]{0,63}\z/D', $variant['id'])
                || in_array($variant['id'], $ids, true)) {
                $this->reject('variants', 'Use a unique stable lowercase identity of up to 64 letters, digits, hyphens or underscores per variant.');
            }
            $ids[] = $variant['id'];
            $variants[] = ['id' => $variant['id'], 'label' => $this->text($variant['label'], 180, 'variants', true),
                'size' => $this->text($variant['size'], 80, 'variants'), 'color' => $this->text($variant['color'], 80, 'variants'),
                'source_reference' => $this->reference($variant['source_reference'], 'variants'),
                'availability' => $this->declaration($variant['availability'], 'variants')];
        }

        return ['title' => $this->text($input['title'], 180, 'title', true),
            'description' => $this->text($input['description'], 4000, 'description'), 'variants' => $variants,
            'source' => $this->declaration($input['source'], 'source'), 'shipping' => $this->declaration($input['shipping'], 'shipping'),
            'returns' => $this->declaration($input['returns'], 'returns')];
    }
}
