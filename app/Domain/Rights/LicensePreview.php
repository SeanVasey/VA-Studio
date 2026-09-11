<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;

/** Offline, escaped review evidence. This is not a buyer contract or PDF renderer. */
final class LicensePreview
{
    public const VERSION = 'vasey-license-review-html-v1';

    public function render(LicenseVersion $version): array
    {
        app(LicenseContent::class)->validate($version->only(['authored_source', 'structured_terms', 'effective_from', 'effective_until']));
        if ($version->structured_terms['schema_version'] === 3) {
            return app(ScopedLicensePreview::class)->render($version);
        }
        if ($version->structured_terms['schema_version'] === 2) {
            return app(TypedLicensePreview::class)->render($version);
        }
        $escape = static fn (mixed $text): string => htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $template = $version->template()->firstOrFail();
        $items = implode('', array_map(fn (string $feature) => '<li>'.$escape($feature).'</li>', $version->features()));
        $roles = implode('', array_map(fn (string $role) => '<li>'.$escape($role).'</li>', $version->requiredAssetRoles()));
        $from = $version->effective_from?->utc()->format('Y-m-d\TH:i:s\Z') ?? 'No start specified';
        $until = $version->effective_until?->utc()->format('Y-m-d\TH:i:s\Z') ?? 'No end specified';
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>License review preview</title><style>html{color-scheme:light}body{font:16px/1.6 system-ui,sans-serif;color:#17242b;background:#fff;max-width:76ch;margin:2rem auto;padding:0 1.25rem}h1,h2{line-height:1.2}h1{font-size:1.8rem}h2{margin-top:2rem;font-size:1.3rem}pre{font:inherit;white-space:pre-wrap;overflow-wrap:anywhere;padding:1rem;background:#f2f5f6;border:1px solid #cbd5da}dd{margin:0 0 .75rem;overflow-wrap:anywhere}dt{font-weight:700}li{margin:.4rem 0}</style></head><body>'
            .'<h1>License review preview — nonbinding</h1><p>Review evidence only. No purchase, executed contract, legal qualification or rights grant is established by this preview.</p>'
            .'<h2>'.$escape($template->name).' · version '.$escape($version->version).'</h2>'
            .'<dl><dt>Template</dt><dd>'.$escape($template->slug).'</dd><dt>Type</dt><dd>'.$escape($template->type).'</dd><dt>Effective start (UTC)</dt><dd>'.$escape($from).'</dd><dt>Effective end (UTC, exclusive)</dt><dd>'.$escape($until).'</dd></dl>'
            .'<h2>Authored source (literal text)</h2><pre>'.$escape($version->authored_source).'</pre>'
            .'<h2>Feature summaries to review against the source</h2><ul>'.$items.'</ul><h2>Required delivery roles</h2><ul>'.$roles.'</ul>'
            .'<p>Renderer: '.self::VERSION.'. Feature prose requires human consistency review; this preview performs no legal interpretation or variable substitution.</p></body></html>';

        return ['html' => $html, 'sha256' => hash('sha256', $html), 'renderer_version' => self::VERSION];
    }
}
