# Disposable developer PHP runtime

This helper is for the temporary `work/dev-php-runtime` branch only. It is not a release dependency, deployment image or replacement for the full repository CI gates. The job reads candidate `5f660db780e58cae4d0b2b773d4b9a93390fcc92` and installs its existing Composer lock without project scripts or plugins.

The bundle contains PHP 8.4, selected extensions, non-glibc shared libraries, qpdf/Poppler command-line binaries and the exact locked `vendor/` tree. It excludes the application source, environment files, storage, caches, Composer home/authentication and runner configuration. PHP and native dependencies have a relative ELF RPATH. The host's glibc and loader remain untouched.

The build reports its manifest SHA-256 in the authenticated job log. It emits a canonical JSON manifest, per-part/archive hashes, an internal file-by-file hash inventory and a deterministic tar header layout. Compressed data is capped at 128 MiB and split into at most six 22 MiB parts; each part has a separate artifact with a three-day retention period. Expanded files are capped at 1 GiB. Actual sizes are reported by the job; no estimate is acceptance evidence.

Download the manifest/installer and all numbered part artifacts from that one workflow run. Put `manifest.json` and `part-01.bin`, etc. in one scratch directory. Compare the manifest hash with the job log before installation. The installer reconstructs and checks the archive hash, rejects absolute/traversal/duplicate/symlink/special entries, checks every extracted file, and refuses existing destination directories.

```sh
python3 scripts/dev-runtime/install.py --parts /absolute/path/to/parts --destination /absolute/path/to/new-runtime --verify-only
```

To enable native child processes, use a fresh destination and the installation mode below in this disposable Ubuntu 24.04 x86_64 root workspace. This refuses any preexisting `/etc/php` configuration and never replaces host shared libraries. It creates only `/etc/php/8.4/cli/php.ini` with explicit references to the extracted extensions. If validation fails, it removes only the configuration it just created. No wrapper or inherited `PHPRC`/`LD_LIBRARY_PATH` is required; the real `PHP_BINARY` child is tested with an empty environment and must load the exact bundled binary/configuration/extensions and SQLite.

```sh
python3 scripts/dev-runtime/install.py --parts /absolute/path/to/parts --destination /absolute/path/to/new-runtime --project /absolute/path/to/candidate-checkout --install
```

The optional `--project` argument verifies that checkout's Composer lock, refuses an existing `vendor`, moves the verified vendor tree into the checkout, and verifies Laravel autoloading. It moves rather than symlinks because Composer resolves the project root relative to the vendor directory. Candidate application changes can then be checked with the same locked dependencies. Creating a local `.env`, generating a disposable key and running package discovery are separate, explicit local development steps.

Use `/absolute/path/to/new-runtime/native/bin/php` for syntax checks and targeted SQLite tests. Put that directory first in the command's `PATH` to use its qpdf/Poppler tools. Full MySQL process races and complete CI remain required. The bundle does not claim portability beyond the matched Ubuntu/glibc/PHP ABI, or acceptance of the isolated renderer until its real application tests pass.

Local helper validation:

```sh
python3 scripts/dev-runtime/test-install.py
python3 -m py_compile scripts/dev-runtime/package.py scripts/dev-runtime/install.py
```
