<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;

/** Pinned v4 review output; actual buyer identity and contract fulfillment belong to WP-08. */
final class EconomicLicensePreview
{
    public const VERSION = 'vasey-license-review-html-v4';

    public function render(LicenseVersion $version): array
    {
        $content = app(LicenseContent::class)->validate($version->only(['authored_source', 'structured_terms', 'effective_from', 'effective_until']));
        $statements = app(EconomicLicenseTerms::class)->statements($content['structured_terms']);
        $values = app(EconomicLicenseTerms::class)->sourceValues($content['structured_terms']);
        $rendered = app(LicenseSourceVariables::class)->render($content['authored_source'], $content['structured_terms']);
        $escape = fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $template = $version->template()->firstOrFail();
        $rows = implode('', array_map(fn ($key, $value) => '<tr><td>'.$escape('{{'.$key.'}}').'</td><td>'.$escape($value).'</td></tr>', array_keys($values), array_values($values)));
        $items = implode('', array_map(fn ($value) => '<li>'.$escape($value).'</li>', array_values($statements)));
        $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>License economic policy review</title><style>body{font:16px/1.6 system-ui,sans-serif;color:#17242b;background:#fff;max-width:84ch;margin:2rem auto;padding:0 1.25rem}pre{font:inherit;white-space:pre-wrap;overflow-wrap:anywhere;padding:1rem;background:#f2f5f6}table{width:100%;border-collapse:collapse}td,th{text-align:left;padding:.5rem;border:1px solid #cbd5da;overflow-wrap:anywhere}h1,h2{line-height:1.2}</style></head><body>'
            .'<h1>License economic policy review — nonbinding</h1><p>Review evidence only. No purchase, executed contract or rights grant is established.</p>'
            .'<h2>'.$escape($template->name).' · version '.$escape($version->version).'</h2>'
            .'<p>Type: '.$escape($template->type).'. Offer availability UTC interval: '.$escape($content['effective_from']?->format('Y-m-d\TH:i:s\Z') ?? 'No start specified').' to '.$escape($content['effective_until']?->format('Y-m-d\TH:i:s\Z') ?? 'No end specified').' (end exclusive). These dates do not start or end the licensed-use duration.</p>'
            .'<h2>Authored source (literal text)</h2><pre>'.$escape($content['authored_source']).'</pre>'
            .'<h2>Source with terms substituted</h2><pre>'.$escape($rendered).'</pre>'
            .'<h2>Generated license-card summaries</h2><ul>'.$items.'</ul>'
            .'<p>Duration starts only at a rights grant. Fixed months use the original grant timestamp in UTC, clamping its day to the final day of the target month when needed. This review creates no grant or expiry timestamp. Territory describes permitted use; it is not buyer location or automatic geographic enforcement.</p>'
            .'<p>Ownership is declared in the retained policies, not inferred from exclusivity or an income share. Publishing income, composition ownership and recording royalties are distinct. This model does not allocate collaborator sale proceeds, infer the remaining interests, verify chain of title or calculate payouts. Each policy identity is scoped to this license version and its captured text hash. Complete policy text below is part of the source evidence; cards display the corresponding references.</p>'
            .'<h2>Variable consistency</h2><table><thead><tr><th>Source variable</th><th>Generated source value</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p>Renderer: '.self::VERSION.'. Substitution verifies supported values and coverage. A separate reviewer must assess the surrounding prose and actual rights policy; contradictory legal meaning cannot be inferred by this renderer.</p></body></html>';

        return ['html' => $html, 'sha256' => hash('sha256', $html), 'renderer_version' => self::VERSION];
    }
}
