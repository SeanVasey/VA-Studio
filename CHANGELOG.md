# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). There are no releases yet, so changes collect under Unreleased until the first version is tagged.

Work merged before this file existed is recorded PR by PR in [docs/development-order.md](docs/development-order.md).

## [Unreleased]

### Added

- Scheduled site publication (D-24): staff schedule one saved site release for a whole UTC minute, cancel it, and review the retained schedule history. A minute scheduler command publishes it through the same locked, audited path as Publish release. Staff publication supersedes a pending schedule, and a schedule expires unpublished 60 minutes after its time. Production needs a `schedule:run` cron entry; `vasey:doctor` warns when a schedule is overdue.
- Vasey Multimedia Engineering Standard v3.1.0: `CLAUDE.md` with project notes, `LICENSE`, `CHANGELOG.md`, `CODE_OF_CONDUCT.md` (Contributor Covenant 2.1) and `.editorconfig`.
- CI fails when the built client bundle contains a server secret name from `.env.example` or a Stripe secret, restricted or webhook key prefix.

### Changed

- `SECURITY.md` names `support@vasey.audio` as the reporting channel and records supported versions and audit exceptions.
- `.gitignore` covers every `.env*` file except `.env.example`, and `CLAUDE.local.md`.

[Unreleased]: https://github.com/VASEYDEV/VASEYAUDIO/commits/main
