# Correct crawler exclusion for contact inquiries

PR #23 emitted `Disallow: /inquiries`, but the registered private inquiry routes
use `/contact/inquiries`. Production robots now advises crawlers to avoid that
actual prefix, covering intake, order setup/context, history/cursor and
conversation/reply while leaving the public `/contact` page crawlable. This
advice does not replace server ownership, authorization or private response
headers.

The executable is `99478464f9b8dd89c5595ab15b75466f90ea6555`, tree
`16e480cbd797ad1884dd262124e42e4cdd8dfbe2`, based on integrated
`717f0866444701637e7c396b4376891aa5dd995d`. Only the discovery controller and its
feature test changed. The metadata child adds this evidence directory alone;
shared README, work package, status and control records remain owned by the
integration agent.

The new regression reads actual registered routes named `contact.inquiries` or
`inquiries.*`, asserts all eight are covered by the emitted crawler prefixes,
and confirms `/contact` remains allowed and the sitemap index contains no
inquiry paths. It failed against the original controller at `contact.inquiries`
before the fix; retained `regression-before-fix` artifacts show that failure.
The existing page sitemap test verifies its exact public URLs include `/contact`
without any private inquiry paths. Existing inquiry HTTP cases verify real
owner reads, foreign/lost/rotated session refusal, private/no-store/noindex
headers, strict transport and CSRF behavior unchanged.

PHP 8.4.26 ran in `/workspace/VA-Studio-crawler-routes` using the already prepared
`/workspace/.va-studio-toolchain/bin/php`. Its vendor directory was copied from
the existing installation, then Composer autoload was regenerated for this
worktree without scripts or installation. Reflection resolved the controller
and test to this checkout; all 158 installed package versions and source
references match the lock. No dependency graph, credentials, runtime profile,
production policy or provider configuration changed.

Focused verification passed **185 tests / 4,218 assertions**, with zero errors,
failures or skips, after committing the executable:

```sh
/workspace/.va-studio-toolchain/bin/php vendor/bin/phpunit \
  tests/Feature/PublicDiscoveryIndexTest.php \
  tests/Feature/PublicPagesSitemapTest.php \
  tests/Feature/CustomerInquiryHttpTest.php \
  tests/Feature/InquiryConversationHttpTest.php \
  tests/Feature/InquiryHistoryHttpTest.php \
  tests/Feature/OrderInquiryHttpTest.php \
  --log-junit docs/verification/crawler-route-correction-20261007/focused.xml
/workspace/.va-studio-toolchain/bin/php vendor/bin/pint --test \
  app/Http/Controllers/PublicDiscoveryController.php \
  tests/Feature/PublicDiscoveryIndexTest.php
git diff --check
```

Both Pint and whitespace checks passed. `focused.txt` and `focused.xml` retain
the fresh results, `registered-inquiry-routes.json` captures the eight real
routes, and `source-runtime.json` binds source and dependency digests. Existing
canonical-origin, body/query refusal, response-header and nonproduction
regressions pass in the same selection. Prior evidence is preserved; this
record makes no recovery claim for missing unpublished predecessor outputs.

No hosted CI, native MySQL, browser matrix, deployed crawl or full Foundation
acceptance ran. Track discovery, legacy source redirects, broader FP-014/T36
SEO acceptance, production commerce, launch and cutover remain open.
