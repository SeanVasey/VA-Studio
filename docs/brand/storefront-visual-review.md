# Storefront visual review

Status: review brief for Claude Code. Date: 2026-11-09. Author direction: Sean Vasey, after looking at the current first-party renders.

This document describes visual problems and the intended correction. It is not an implementation, not a catalog import, and not permission to publish tracks, prices, or license terms.

Brand color authority remains [docs/brand/README.md](README.md). This brief changes how the existing type roles and surfaces are used. It does not add a color.

## What to review

Three problems make the new storefront feel flatter and smaller than the live BeatStars store:

1. The navbar, cart, and footer links are body type in sentence case. Headers that should read as signage do not use Bebas Neue.
2. The page sits on one deep-turquoise field. License levels in the same group share one background, so Standard, Premium, and Trackout do not separate.
3. The footer repeats the same three in-page jumps as the header. It does not offer a link to each public page.

Review the current storefront against this brief before editing. Implement on the real storefront (`resources/css/app.css`, `resources/js/components/SiteChrome.tsx`, published site content). Leave the design-preview study alone.

## Leave this study out of the product

A local design preview was used only to look at the direction. It is not the fix.

- `resources/js/test/preview.css` may contain temporary overrides (Bebas navbar, license card fills, closing band).
- `resources/js/test/DesignPreview.tsx` may rewrite the footer with `replaceChildren` and point About, Contact, Blog, Videos, Privacy, and Terms at existing home-page anchors.
- `resources/js/test/featured-look.ts`, `public/images/walkthrough-covers/`, and `public/media/walkthrough-preview.wav` are a visual catalog for screenshots. Titles and cover files came from the public BeatStars listing. Tempo, key, mood, and the shared preview tone are placeholders. License copy there is still the DEMO fixture.

Do not merge those files as published catalog, site content, or production CSS. Do not copy the footer DOM rewrite. The Laravel app on port 8000 was left on the original chrome on purpose.

The preview that showed the direction was `http://127.0.0.1:5174/?fixtures&embed`.

## Current evidence

Tokens in `public/brand/theme.css`:

| Token | Face or value | Intended job |
| --- | --- | --- |
| `--va-font-impact` | Noto Sans Display, extra-condensed, 900 | One poster headline |
| `--va-font-section` | Bebas Neue 400 | Short uppercase signage |
| `--va-font-body` | Reddit Sans 400–700 | Reading text, track titles, buttons |
| `--va-font-mono` | JetBrains Mono | BPM, key, time, price, eyebrows |
| `--va-canvas` | `#052E3A` deep turquoise | Page field |
| `--va-surface` | `#29363F` charcoal | Bands, player, panels |
| `--va-action` | `#00B8D9` turquoise | One accent: buttons, current mark, featured edge |
| `--va-action-hover` | `#397281` teal | Hover only |

`resources/css/fonts.css` loads Bebas Neue at weight 400 only. `font-synthesis` is `none` in `resources/css/app.css`, so `font-weight: 700` on Bebas does not create a bolder cut. Size and letter-spacing are the weights.

`resources/css/app.css` currently sets `h1, h2` to `--va-font-impact`. License card titles already use `--va-font-section`. Header and footer links do not. `.site-header nav a` and `.admin-link` are 13px at weight 500, which is the body face. `.cart-toggle` is 12px. `.site-footer nav a` is 11px. `.license-section` is already `background: var(--va-surface)`, and `.license-card` items are undivided columns on that same field, split by a hairline.

Default navigation in `resources/js/lib/site-content.ts` (`defaultSiteContent`) is three anchors: `/#catalog`, `/#licenses`, `/#studio`. `SiteFooter` in `resources/js/components/SiteChrome.tsx` prints that navigation, then any published `legal_links`, then Artist admin. With no published legal release, the footer has those three links plus Artist admin.

Routes that already exist and 404 until a site release publishes them:

- `GET /{section}/{slug?}` for `about`, `contact`, `blog`, `videos` (`routes/web.php`, `editorial.show`)
- `GET /legal/{document}` for privacy, terms, and licenses (`routes/legal-pages.php`), active published release only
- `GET /tracks/{slug}` (`tracks.show`), which renders only when that track is published

BeatStars lists a separate URL per beat, plus kits, services, memberships, and videos, because those records are published there. This app's Studio track list is empty until a real publication. Empty catalog remains the correct public state. Do not invent beats, prices, or terms to fill the footer.

## Correction 1 — Bebas signage, Noto poster

Use Bebas Neue 400, uppercase, for signage:

- Header links, Artist admin, and the cart label. About 22–26px, letter-spacing `0.08em`. Rendered labels: THE CATALOG, LICENSING, THE STUDIO, ARTIST ADMIN, CART. The logo image stays the existing PNG.
- Section titles (`#catalog`, `#licenses`, `#studio`) and license names (`.license-card h3`, `.detail-offer h3`).
- Footer page links, about 18–22px, same face and case.

Keep the hero `h1` (SOUND / WITH INTENT) on `--va-font-impact` (Noto Sans Display, extra-condensed, 900). Bebas at that size would make every section the same poster.

Keep Reddit Sans for track titles, body, buttons, and form fields (400 body, 600 titles, 700 buttons). Keep JetBrains Mono for BPM, key, duration, price, and eyebrows.

Buttons can stay sentence case. Signage is the navbar, section headers, license names, and footer labels.

## Correction 2 — Alternating surfaces inside one palette

Stay on the tokens above. Do not add purple, magenta, neon green, DIG|TAL yellow, or a brighter cyan. Teal `#397281` stays a hover color. Silver text on a solid teal card fails the contrast notes in `docs/brand/README.md`.

Page rhythm, top to bottom:

1. Hero remains `--va-canvas`, with the existing photo.
2. `.discipline-strip` uses `--va-surface`.
3. `#catalog` returns to `--va-canvas`. The current track row may stay `--va-surface`.
4. `#licenses` is a full-bleed `--va-surface` band.
5. `#studio` returns to `--va-canvas`.
6. `.closing-statement` uses `--va-surface`.
7. The player dock stays `--va-surface`.

Inside one group of options, alternate the card and mark a single featured option. For the three license levels, on the charcoal band:

- Odd cards (Standard, Trackout): fill `--va-canvas`, 1px `--va-border`, equal padding on every side.
- Even card (Premium): transparent on the charcoal band, with a 3px `--va-action` top edge so it is the card that looks chosen.

Apply the same odd/even rule to `.detail-offer` on a track page. Later membership or sound-kit tiers, once those products are approved to sell, use the same rule. The selected genre chip should fill `--va-surface` and use `--va-action` for its label.

Give every card the same padding. The current `.license-card:first-child` / `:last-child` padding reset would leave a filled card looking uneven.

## Correction 3 — One footer link per public page

The footer should list every page that is actually published:

- Home
- Catalog
- Licensing
- Studio
- About, Contact, Blog, and Videos, once an editorial release publishes them
- Privacy, Terms, and Licenses, once a legal release publishes them
- Account, only when customer accounts are enabled
- Artist admin

Build that list from published site content (`navigation` plus `legal_links`) and from routes that return 200. Do not hard-code links to pages that 404. Do not point About at `#studio` the way the preview study did. That was only so a screenshot could show the labels.

Publishing About, Contact, Blog, Videos, and the legal pages is a content release, not a CSS change. Follow the existing site-release and legal-publication path. Drafts in `docs/legal/` stay unapproved until Sean approves them.

Individual beat pages appear when tracks are published at `/tracks/{slug}`. They do not belong as a static footer list. The beats list and the player should keep using each track's own artwork once that artwork is a published asset.

Do not add BeatStars-only destinations (sound kits, services, memberships) until those products are approved to sell.

## Suggested order

1. Type and license-card surfaces in `resources/css/app.css`, using the existing tokens. Add or adjust a storefront test if one already covers header or license markup.
2. Footer link set in `SiteChrome` / published site content, limited to pages that resolve.
3. Editorial and legal publication as a separate content change, with Sean's approval for any legal text.

## Review checklist

- Header and footer labels render in Bebas Neue, uppercase, and still wrap on a narrow viewport without covering the logo or cart.
- Hero headline is still Noto Sans Display extra-condensed.
- License cards alternate canvas / featured-charcoal, and Premium is the card with the turquoise top edge. Track-detail offers follow the same pattern.
- Keyboard focus stays the 3px turquoise ring. Interactive targets stay at least 44px. Text contrast stays inside the ratios in `docs/brand/README.md`. `prefers-reduced-motion` still holds.
- Footer links all return a real page. No new link 404s.
- Public catalog is still empty until a real publication. No walkthrough cover, DEMO price, or placeholder tempo is served by the Laravel app.
- No new hex values outside `public/brand/theme.css`.
