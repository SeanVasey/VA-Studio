#!/usr/bin/env python3
"""Run a reviewed test subset. Focused feedback never establishes merge acceptance.

Selections come only from FOCUSED_SUITE and FOCUSED_ENGINE enums, not shell code,
paths, filters or forwarded CLI arguments. PHPUnit configs remain beside phpunit.xml
so its bootstrap/source/environment paths keep their meaning. Full CI is separate.
"""
from __future__ import annotations

import argparse
from copy import deepcopy
from dataclasses import dataclass
import json
import os
from pathlib import Path
import re
import subprocess
import sys
from typing import Mapping
import xml.etree.ElementTree as ET


PHP_TARGETS = {
    "unit": (
        "tests/Unit/BrowserLoginRateLimitFixtureTest.php",
        "tests/Unit/CatalogDryRunTest.php", "tests/Unit/CatalogDryRunCommandTest.php",
        "tests/Unit/AllocateDiscountTest.php", "tests/Unit/CommerceGuardBytesTest.php", "tests/Unit/ContractTextTest.php",
        "tests/Unit/EconomicLicenseTermsTest.php", "tests/Unit/FileUploadPathGuardTest.php",
        "tests/Unit/MediaEvidenceValuesTest.php", "tests/Unit/MinorUnitsTest.php",
        "tests/Unit/RelatedTrackBrowserFixtureGuardTest.php",
        "tests/Unit/PricingPolicyTest.php", "tests/Unit/PromotionPolicyTest.php",
        "tests/Unit/ScopedLicenseTermsTest.php", "tests/Unit/SiteImageGuardBytesTest.php",
        "tests/Unit/StripeCheckoutGatewayTest.php", "tests/Unit/StripePaymentGatewayTest.php",
        "tests/Unit/StripeFinancialInspectionGatewayTest.php",
        "tests/Unit/TcpdfContractRendererTest.php", "tests/Unit/TypedLicenseTermsTest.php",
    ),
    "media": (
        "tests/Feature/SoundKitUploadsTest.php", "tests/Feature/SoundKitUploadHttpTest.php",
        "tests/Feature/SoundKitUploadMigrationTest.php", "tests/Feature/SoundKitUploadsConcurrencyTest.php",
        "tests/Feature/SoundKitIntakeTest.php", "tests/Feature/SoundKitRecoveryTest.php",
        "tests/Feature/SoundKitMigrationTest.php", "tests/Feature/SoundKitDraftAdminTest.php",
        "tests/Feature/SoundKitConcurrencyTest.php",
        "tests/Unit/FileUploadPathGuardTest.php", "tests/Unit/MediaEvidenceValuesTest.php",
        "tests/Unit/SiteImageGuardBytesTest.php", "tests/Feature/InstallationReportTest.php",
        "tests/Feature/MalwareScannerTest.php", "tests/Feature/MediaProcessingTest.php",
        "tests/Feature/MediaWriterAuthorityTest.php", "tests/Feature/MediaWriterConcurrencyTest.php",
        "tests/Feature/MediaWorkerIdentityTest.php", "tests/Feature/MediaUploadCommitRecoveryTest.php",
        "tests/Feature/ResumableMediaUploadsTest.php", "tests/Feature/ResumableMediaUploadsConcurrencyTest.php",
        "tests/Feature/ResumableMediaUploadHttpTest.php",
        "tests/Feature/MediaWorkflowBudgetTest.php", "tests/Feature/PrivateMediaRevisionRootsTest.php",
        "tests/Feature/SiteImageLibraryTest.php", "tests/Feature/SiteImageHttpTest.php", "tests/Feature/StemsArchivePolicyTest.php",
        "tests/Feature/StemsArchiveTest.php",
    ),
    "commerce": (
        "tests/Unit/StripeFinancialInspectionGatewayTest.php", "tests/Feature/TestPaymentFinancialObservationTest.php",
        "tests/Feature/TestPaymentExceptionOperationsTest.php", "tests/Feature/TestPaymentExceptionOperationsConcurrencyTest.php",
        "tests/Feature/CommerceAuditActorTest.php", "tests/Feature/CommerceAuditActorConcurrencyTest.php",
        "tests/Unit/AllocateDiscountTest.php", "tests/Unit/MinorUnitsTest.php",
        "tests/Unit/PricingPolicyTest.php", "tests/Unit/PromotionPolicyTest.php",
        "tests/Unit/StripeCheckoutGatewayTest.php", "tests/Unit/StripePaymentGatewayTest.php",
        "tests/Feature/QuoteHttpTest.php", "tests/Feature/QuotePricingHttpTest.php",
        "tests/Feature/QuotePricingConcurrencyTest.php", "tests/Feature/HostedCheckoutTest.php",
        "tests/Feature/HostedCheckoutConcurrencyTest.php", "tests/Feature/StripeWebhookTest.php",
        "tests/Feature/StripeWebhookConcurrencyTest.php",
        "tests/Feature/HashByteGuardMigrationTest.php", "tests/Feature/UuidByteGuardMigrationTest.php",
        "tests/Feature/OwnedTestOrderHistoryTest.php", "tests/Feature/SyntheticPrivateRestoreTest.php",
        "tests/Feature/HostedCheckoutMigrationTest.php", "tests/Feature/OrderPreparationMigrationTest.php",
        "tests/Feature/PromotionMigrationTest.php", "tests/Feature/QuotePricingMigrationTest.php",
        "tests/Feature/SharedInventoryMigrationTest.php", "tests/Feature/TestOrderFinalizationMigrationTest.php",
        "tests/Feature/TestPaymentEvidenceMigrationTest.php",
    ),
    "financial": (
        "tests/Feature/TestRefundResolutionTest.php", "tests/Feature/TestRefundResolutionMigrationTest.php",
        "tests/Feature/TestRefundResolutionConcurrencyTest.php", "tests/Feature/TestRefundResolutionResourceTest.php",
        "tests/Feature/TestUnpaidReleaseTest.php", "tests/Feature/TestUnpaidReleaseMigrationTest.php",
        "tests/Feature/TestUnpaidReleaseConcurrencyTest.php", "tests/Feature/TestUnpaidOrderResourceTest.php",
        "tests/Feature/TestPaymentFinancialObservationTest.php", "tests/Feature/TestPaymentExceptionOperationsTest.php",
        "tests/Feature/TestPaymentExceptionOperationsConcurrencyTest.php",
        "tests/Feature/TestPaymentProcessingTest.php", "tests/Feature/TestOrderFinalizationTest.php",
    ),
    "customer": (
        "tests/Feature/InquiryBrowserCatalogCleanupTest.php",
        "tests/Feature/OrderInquiryTest.php", "tests/Feature/OrderInquiryHttpTest.php",
        "tests/Feature/OrderInquiryMigrationTest.php", "tests/Feature/OrderInquiryConcurrencyTest.php",
        "tests/Feature/OrderInquiryStaffContextTest.php",
        "tests/Feature/CustomerRefundResolutionTest.php", "tests/Feature/CustomerRefundResolutionHttpTest.php",
        "tests/Feature/CustomerPurchaseClaimTest.php", "tests/Feature/CustomerPurchaseClaimMigrationTest.php",
        "tests/Feature/CustomerPurchaseClaimConcurrencyTest.php",
        "tests/Feature/CustomerIdentityTest.php", "tests/Feature/CustomerIdentityHttpTest.php",
        "tests/Feature/CustomerIdentityMigrationTest.php", "tests/Feature/CustomerIdentityConcurrencyTest.php",
        "tests/Feature/CustomerAccountAccessTest.php", "tests/Feature/CustomerAccountCommerceTest.php",
        "tests/Feature/CustomerAccountConcurrencyTest.php", "tests/Feature/CustomerAccountMigrationTest.php",
        "tests/Feature/CustomerSessionHttpTest.php", "tests/Feature/OwnedTestOrderHistoryTest.php",
        "tests/Feature/CustomerOrderReferenceTest.php",
        "tests/Feature/CustomerOrderItemsTest.php",
        "tests/Feature/TestOwnerDeliveryHttpTest.php", "tests/Feature/TestOwnerDeliveryProjectionTest.php",
    ),
    "seller": (
        "tests/Feature/OrderInquiryStaffContextTest.php",
        "tests/Feature/InquiryHistoryTest.php", "tests/Feature/InquiryHistoryHttpTest.php",
        "tests/Feature/InquiryConversationTest.php", "tests/Feature/InquiryConversationHttpTest.php",
        "tests/Feature/InquiryConversationAdminTest.php", "tests/Feature/InquiryConversationConcurrencyTest.php",
        "tests/Feature/InquiryMessageMigrationTest.php",
        "tests/Feature/ProductDraftTest.php", "tests/Feature/ProductDraftEditorTest.php",
        "tests/Feature/ProductDraftMigrationTest.php", "tests/Feature/ProductDraftConcurrencyTest.php",
        "tests/Feature/ProductMemberRefreshTest.php", "tests/Feature/ProductMemberRefreshEditorTest.php",
        "tests/Feature/ProductMemberRefreshConcurrencyTest.php",
        "tests/Feature/CustomerInquiryHttpTest.php", "tests/Feature/CustomerInquiryMigrationTest.php",
        "tests/Feature/CustomerInquiryAdminTest.php", "tests/Feature/CustomerInquiryConcurrencyTest.php",
        "tests/Feature/PublicTrackEmbedTest.php", "tests/Feature/PublicTrackEmbedRouteCacheTest.php",
        "tests/Feature/SiteEditorialHttpTest.php", "tests/Feature/SiteEditorialContentTest.php",
        "tests/Feature/PublicLicenseDisclosureTest.php", "tests/Feature/SiteContentUnavailableHttpTest.php",
        "tests/Feature/PublicCatalogRelatedLinksTest.php", "tests/Feature/SiteRelatedTrackContentTest.php",
        "tests/Feature/SiteRelatedTrackDamageTest.php", "tests/Feature/SiteRelatedTrackEditorTest.php",
        "tests/Feature/SiteRelatedTrackHttpTest.php", "tests/Feature/SiteRelatedTrackImageMigrationTest.php",
        "tests/Feature/SiteContentConcurrencyTest.php",
    ),
    "licensing": (
        "tests/Feature/ReviewedLicenseDraftTest.php", "tests/Feature/ReviewedLicenseDraftConcurrencyTest.php",
        "tests/Feature/LicenseDraftAuthoringActionTest.php",
        "tests/Feature/LicenseTemplateAuthoringTest.php", "tests/Feature/LicenseTemplateAuthoringActionTest.php",
        "tests/Feature/LicenseTemplateAuthoringConcurrencyTest.php",
        "tests/Feature/LicensingAdminTest.php", "tests/Feature/LicenseWriterAuthorityTest.php",
        "tests/Feature/LicenseWriterConcurrencyTest.php", "tests/Feature/LicenseEvidenceTest.php",
        "tests/Feature/OfferRevisionTest.php",
    ),
    "publication": (
        "tests/Feature/TrackPublicationApplyTest.php", "tests/Feature/TrackPublicationApplyConcurrencyTest.php",
        "tests/Feature/TrackPublicationManifestEditorTest.php",
        "tests/Feature/TrackPublicationManifestTest.php", "tests/Feature/TrackPublicationManifestConcurrencyTest.php",
        "tests/Feature/TrackPublicationGuardTest.php", "tests/Feature/TrackPublicationGuardConcurrencyTest.php",
    ),
    "track-concurrency": (
        "tests/Feature/TrackMetadataConcurrencyTest.php",
        "tests/Feature/StemsRecordingConcurrencyTest.php",
        "tests/Feature/StemsRecordingPreviewConcurrencyTest.php",
        "tests/Feature/StemsRecordingTest.php",
        "tests/Feature/MediaWriterAuthorityTest.php",
    ),
    "operator": (
        "tests/Feature/FoundationTest.php", "tests/Feature/OperatorSetupTest.php",
        "tests/Feature/OperatorAuthorityTest.php", "tests/Feature/OperatorMfaTest.php",
        "tests/Feature/TrackMetadataTest.php", "tests/Feature/LicensingAdminTest.php",
        "tests/Feature/TrackMetadataPresetsTest.php", "tests/Feature/TrackMetadataPresetsConcurrencyTest.php",
        "tests/Feature/TrackMetadataPresetsMigrationTest.php",
        "tests/Feature/BulkTrackTagsTest.php", "tests/Feature/BulkTrackTagsConcurrencyTest.php",
        "tests/Feature/BulkUpdateTrackMetadataTest.php", "tests/Feature/BulkUpdateTrackMetadataConcurrencyTest.php",
        "tests/Feature/PrivateTrackReviewTest.php", "tests/Feature/PrivateTrackReviewPrivacyTest.php",
        "tests/Feature/TrackPublicationGuardTest.php", "tests/Feature/TrackPublicationGuardMigrationTest.php",
        "tests/Feature/TrackPublicationGuardConcurrencyTest.php",
        "tests/Feature/TrackPublicationManifestTest.php", "tests/Feature/TrackPublicationManifestConcurrencyTest.php",
        "tests/Feature/TrackPublicationManifestEditorTest.php",
        "tests/Feature/RightsEvidenceGuardTest.php", "tests/Feature/RightsEvidenceGuardMigrationTest.php",
        "tests/Feature/RightsEvidenceGuardConcurrencyTest.php",
        "tests/Feature/RightsDeclarationWriterTest.php", "tests/Feature/RightsDeclarationWriterActionTest.php",
        "tests/Feature/RightsDeclarationWriterConcurrencyTest.php", "tests/Feature/OfferWriterAuthorityTest.php",
        "tests/Feature/CatalogWriterAuthorityTest.php",
        "tests/Feature/ExclusiveWriterAuthorityTest.php",
        "tests/Feature/LicenseWriterAuthorityTest.php", "tests/Feature/RightsScopeWriterAuthorityTest.php",
    ),
}
FRONTEND_TARGETS = (
    "tests/frontend/order-inquiry.test.tsx",
    "tests/frontend/customer-exception-resolution.test.tsx",
    "tests/frontend/inquiry-history.test.tsx",
    "tests/frontend/customer-purchase-claim.test.tsx",
    "tests/frontend/customer-identity.test.tsx", "tests/frontend/resumable-kit-upload.test.ts",
    "tests/frontend/inquiry-conversation.test.tsx",
    "tests/frontend/customer-account.test.tsx",
    "tests/frontend/customer-order-reference.test.tsx",
    "tests/frontend/order-items.test.tsx",
    "tests/frontend/audio.test.tsx", "tests/frontend/catalog-pagination.test.tsx",
    "tests/frontend/catalog.test.ts", "tests/frontend/checkout-return.test.tsx",
    "tests/frontend/cms-navigation.test.tsx", "tests/frontend/editorial-content.test.tsx",
    "tests/frontend/license-disclosure.test.tsx", "tests/frontend/metadata.test.tsx",
    "tests/frontend/order-preparation.test.tsx", "tests/frontend/order-recovery.test.tsx",
    "tests/frontend/quote-review.test.tsx", "tests/frontend/site-content.test.tsx",
    "tests/frontend/site-images.test.tsx", "tests/frontend/storefront.test.tsx",
    "tests/frontend/test-checkout.test.tsx", "tests/frontend/test-owner-delivery.test.tsx",
    "tests/frontend/track-detail.test.tsx",
    "tests/frontend/editorial-video.test.tsx", "tests/frontend/owned-order-history.test.tsx",
    "tests/frontend/contact-inquiry.test.tsx",
    "tests/frontend/editorial-related-tracks.test.tsx",
    "tests/frontend/resumable-media-upload.test.ts",
)
BROWSER_TARGETS = (
    "tests/browser/license-template-authoring.spec.ts",
    "tests/browser/customer-library-browsing.spec.ts",
    "tests/browser/customer-purchase-claim.spec.ts", "tests/browser/test-refunded-exception-resolution.spec.ts",
    "tests/browser/customer-identity.spec.ts", "tests/browser/resumable-kit-upload.spec.ts",
    "tests/browser/test-unpaid-release.spec.ts",
    "tests/browser/inquiry-conversation.spec.ts",
    "tests/browser/customer-account.spec.ts",
    "tests/browser/customer-order-reference.spec.ts",
    "tests/browser/product-member-refresh.spec.ts",

    "tests/browser/customer-order-items.spec.ts",
    "tests/browser/storefront.spec.ts", "tests/browser/operator.spec.ts",
    "tests/browser/test-checkout.spec.ts", "tests/browser/test-owner-delivery.spec.ts",
    "tests/browser/player-controls.spec.ts", "tests/browser/owned-order-history.spec.ts",
    "tests/browser/editorial-video-consent.spec.ts", "tests/browser/site-release-menu-readiness.spec.ts",
    "tests/browser/contact-inquiry.spec.ts", "tests/browser/contact-inquiry-persistence.spec.ts",
    "tests/browser/public-track-embed.spec.ts",
    "tests/browser/track-bulk-tags.spec.ts",
    "tests/browser/track-metadata-presets.spec.ts",
    "tests/browser/bulk-track-metadata.spec.ts",
    "tests/browser/private-track-review.spec.ts",
    "tests/browser/track-publication-guard.spec.ts",
    "tests/browser/editorial-content.spec.ts", "tests/browser/site-content.spec.ts", "tests/browser/site-schedule.spec.ts",
    "tests/browser/resumable-media-upload.spec.ts",
)
SUITES = (*PHP_TARGETS, "frontend", "browser")
ENGINES = ("sqlite", "mysql")
MAX_FILES = 32
EVIDENCE = "focused-ci-evidence.json"
RESULTS = "focused-ci-results.xml"
CONFIGURATION = "phpunit-focused.xml"
NOTICE = "FOCUSED FEEDBACK ONLY: this subset is not full CI or merge/release acceptance."


class FocusedError(Exception):
    pass


@dataclass(frozen=True)
class Selection:
    suite: str
    engine: str
    kind: str
    files: tuple[str, ...]


def selection(env: Mapping[str, str]) -> Selection:
    suite, engine = env.get("FOCUSED_SUITE", ""), env.get("FOCUSED_ENGINE", "")
    if suite not in SUITES:
        raise FocusedError("FOCUSED_SUITE must be one of: " + ", ".join(SUITES))
    if engine not in ENGINES:
        raise FocusedError("FOCUSED_ENGINE must be sqlite or mysql")
    if suite in PHP_TARGETS:
        return Selection(suite, engine, "php", PHP_TARGETS[suite])
    if engine != "sqlite":
        raise FocusedError("Frontend/browser modes require engine=sqlite; frontend has no database and the browser wrapper isolates SQLite")
    return Selection(suite, "none" if suite == "frontend" else "sqlite", suite,
                     FRONTEND_TARGETS if suite == "frontend" else BROWSER_TARGETS)


def validate_files(root: Path, selected: Selection) -> None:
    if not selected.files or len(selected.files) > MAX_FILES or len(set(selected.files)) != len(selected.files):
        raise FocusedError("Empty, oversized or duplicate focused selection")
    root = root.resolve(strict=True)
    prefix = "tests/" + ("frontend/" if selected.kind == "frontend" else "browser/" if selected.kind == "browser" else "")
    for name in selected.files:
        path = Path(name)
        if path.is_absolute() or ".." in path.parts or name != path.as_posix() or not name.startswith(prefix):
            raise FocusedError("Invalid allowlisted target: " + name)
        try:
            resolved = (root / path).resolve(strict=True)
        except OSError as error:
            raise FocusedError("Missing allowlisted target: " + name) from error
        if not resolved.is_relative_to(root) or not resolved.is_file():
            raise FocusedError("Allowlisted target escapes the repository or is not a file: " + name)


def php_configuration(root: Path, selected: Selection) -> Path:
    source = ET.parse(root / "phpunit.xml")
    if source.getroot().tag != "phpunit" or selected.kind != "php":
        raise FocusedError("Unsupported PHPUnit configuration or selection")
    result = deepcopy(source)
    suites = result.getroot().find("testsuites")
    if suites is None:
        raise FocusedError("PHPUnit configuration has no testsuites")
    suites.clear()
    suite = ET.SubElement(suites, "testsuite", {"name": "Focused-" + selected.suite})
    for file in selected.files:
        ET.SubElement(suite, "file").text = file
    ET.register_namespace("xsi", "http://www.w3.org/2001/XMLSchema-instance")
    output = root / CONFIGURATION
    result.write(output, encoding="utf-8", xml_declaration=True)
    return output


def commands(selected: Selection) -> list[list[str]]:
    if selected.kind == "php":
        return [["php", "vendor/bin/phpunit", "--configuration=" + CONFIGURATION,
                 "--log-junit=" + RESULTS, "--fail-on-empty-test-suite",
                 "--fail-on-phpunit-warning", "--display-warnings"]]
    if selected.kind == "frontend":
        return [["npm", "test", "--", *selected.files, "--reporter=default", "--reporter=junit", "--outputFile=" + RESULTS],
                ["npm", "run", "build"], ["python3", "scripts/ci/scan-client-bundle.py"]]
    return [["npm", "run", "test:browser", "--", *selected.files, "--reporter=line,junit"]]


def source_identity(root: Path, env: Mapping[str, str]) -> dict[str, object]:
    identity = {}
    for key, target in (("checked_out_commit", "HEAD"), ("checked_out_tree", "HEAD^{tree}")):
        result = subprocess.run(["git", "rev-parse", "--verify", target], cwd=root,
                                text=True, capture_output=True, check=False, timeout=10)
        value = result.stdout.strip()
        if result.returncode != 0 or not re.fullmatch(r"[a-f0-9]{40}|[a-f0-9]{64}", value):
            raise FocusedError("Cannot record the checked-out commit/tree")
        identity[key] = value
    expected = env.get("GITHUB_SHA")
    if expected and identity["checked_out_commit"] != expected:
        raise FocusedError("Checked-out commit differs from the workflow event SHA")
    tracked = subprocess.run(["git", "diff", "--quiet", "HEAD", "--"], cwd=root,
                             text=True, capture_output=True, check=False, timeout=10)
    if tracked.returncode != 0:
        raise FocusedError("Commit tracked changes before recording focused source evidence")
    identity["tracked_worktree_clean"] = True
    return identity


def junit_counts(path: Path) -> dict[str, int]:
    root = ET.parse(path).getroot()
    if root.tag not in {"testsuites", "testsuite"}:
        raise FocusedError("Unknown JUnit report shape")
    cases = list(root.iter("testcase"))
    if not cases:
        raise FocusedError("Focused runner reported no test cases")
    skipped = sum(case.find("skipped") is not None for case in cases)
    try:
        values = [int(case.get("assertions", "0")) for case in cases]
    except ValueError as error:
        raise FocusedError("Invalid JUnit assertion count") from error
    if any(value < 0 for value in values):
        raise FocusedError("Invalid JUnit assertion count")
    return {"reported_cases": len(cases), "executed_cases": len(cases) - skipped,
            "skipped_cases": skipped, "failures": sum(case.find("failure") is not None for case in cases),
            "errors": sum(case.find("error") is not None for case in cases), "assertions": sum(values)}


def runner_environment(selected: Selection, env: Mapping[str, str]) -> dict[str, str]:
    result = dict(env)
    if selected.kind == "php":
        result.update(APP_ENV="testing", APP_DEBUG="false", DB_CONNECTION=selected.engine, DB_URL="",
                      DB_DATABASE="vaseyaudio_focused" if selected.engine == "mysql" else ":memory:",
                      DB_HOST="127.0.0.1", DB_PORT="3306", DB_USERNAME="root", DB_PASSWORD="ci-only-password",
                      CACHE_STORE="array", SESSION_DRIVER="array", QUEUE_CONNECTION="sync", MAIL_MAILER="array")
    elif selected.kind == "browser":
        result["PLAYWRIGHT_JUNIT_OUTPUT_FILE"] = RESULTS
    return result


def save_evidence(root: Path, evidence: dict) -> None:
    (root / EVIDENCE).write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n")


def report(evidence: dict, summary: str | None = None) -> None:
    text = (NOTICE + "\n" + json.dumps(evidence, indent=2, sort_keys=True) + "\n")
    print(text, flush=True)
    if summary:
        with Path(summary).open("a") as handle:
            handle.write("### Focused development feedback\n\n" + NOTICE + "\n\n```json\n" + json.dumps(evidence, indent=2, sort_keys=True) + "\n```\n")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--plan", action="store_true", help="Validate and list the fixed selection without running tests")
    args = parser.parse_args(argv)
    root = Path(__file__).resolve().parents[2]
    selected = selection(os.environ)
    validate_files(root, selected)
    evidence = {"schema_version": 1, "purpose": "focused-feedback-not-merge-acceptance", "suite": selected.suite,
                "engine": selected.engine, "selected_files": list(selected.files), "commands": commands(selected),
                "event": os.environ.get("GITHUB_EVENT_NAME", "local"), "status": "planned",
                **source_identity(root, os.environ)}
    save_evidence(root, evidence)
    if args.plan:
        output = os.environ.get("GITHUB_OUTPUT")
        if output:
            # Only validated enum values reach the environment-file protocol.
            with Path(output).open("a") as handle:
                handle.write(f"suite={selected.suite}\nengine={selected.engine}\nkind={selected.kind}\n")
        report(evidence, os.environ.get("GITHUB_STEP_SUMMARY"))
        return 0
    evidence.update(status="running", command_results=[])
    save_evidence(root, evidence)
    report(evidence)
    try:
        if selected.kind == "php":
            php_configuration(root, selected)
        results = root / RESULTS
        results.unlink(missing_ok=True)
        environment = runner_environment(selected, os.environ)
        if selected.kind == "browser":
            environment["PLAYWRIGHT_JUNIT_OUTPUT_FILE"] = str(results)
        for index, command in enumerate(evidence["commands"]):
            result = subprocess.run(command, cwd=root, env=environment, shell=False, check=False,
                                    timeout=900 if selected.kind == "php" else 720 if selected.kind == "browser" else 300)
            evidence["command_results"].append({"argv": command, "exit_code": result.returncode})
            if index == 0 and results.is_file():
                evidence["test_counts"] = junit_counts(results)
            if result.returncode != 0:
                evidence["status"] = "failed"
                return result.returncode if result.returncode > 0 else 1
            if index == 0:
                counts = evidence.get("test_counts")
                if not counts or counts["executed_cases"] == 0 or counts["failures"] or counts["errors"]:
                    raise FocusedError("Successful process did not prove a nonempty passing focused test run")
        evidence["status"] = "passed"
        return 0
    except (OSError, subprocess.TimeoutExpired, ET.ParseError, FocusedError) as error:
        evidence.update(status="failed", error=str(error))
        return 1
    finally:
        save_evidence(root, evidence)
        report(evidence, os.environ.get("GITHUB_STEP_SUMMARY"))


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (FocusedError, OSError, ET.ParseError) as error:
        print("Focused selection failed: " + str(error), file=sys.stderr)
        raise SystemExit(1)
