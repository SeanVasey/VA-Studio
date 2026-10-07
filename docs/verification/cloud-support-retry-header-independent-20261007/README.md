# Support attachment private retry headers: independent approval

Approved exact executable `35b3d98bb2aec3366d8e8719e2b10e746c804bd8`, reviewed on documentation-only `e2ca76c0d19c55e504108831edfb1666fc07e6aa`. The bounded delta is three runtime response paths plus their regression file; attachment/schema/owner/scanner/provider and database behavior are unchanged.

Both the private middleware's HTTP-exception catch and the global private exception projection pass headers through the same sanitizer. It permits only Retry-After and the three X-RateLimit headers, with an integer/decimal string of one to twenty ASCII digits, or exactly one such indexed array value. Negative, exponential, oversized, multi-value, non-string/non-integer and arbitrary redirect/cookie/exception headers are refused. No Stringable conversion or overloaded offset executes. Private generic bodies, no-store/sandbox headers and existing status projection remain intact; this metadata supplies retry timing, not access or action authority.

Independent actual registered HTTP/projection selection passed **3 cases / 141 assertions**, zero failures/errors/skips, in 2.200 seconds. It exercised the real page60 and action10 throttles, retained safe retry metadata on both 429 paths, discarded arbitrary exception Location/Set-Cookie/private values, and rejected ambiguous/malformed numeric metadata. Scoped Pint passed four files. Console/JUnit and exact source hashes are retained here. No native repetition was needed for this pure response projection.

Verified all four root source hashes and seventeen artifact digests. Original published6a regression2/88 with two genuine failures stays preserved; root corrected registered12/405 and unchanged original2/136 are root executions, not mine. Existing native attachment/source evidence stays attributed to its unchanged source. No hosted CI, live delivery, production feature activation or full release acceptance occurred.

Command with the existing locked PHP toolchain and a synthetic application key:

```sh
php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- -c phpunit.xml tests/Feature/SupportAttachmentRegistrationTest.php --filter 'test_actual_page_and_action_throttles_keep_safe_retry_headers|test_private_http_exception_headers_cannot_disclose|test_private_error_projection_refuses_nonfinite' --log-junit docs/verification/cloud-support-retry-header-independent-20261007/own-sqlite.xml
```
