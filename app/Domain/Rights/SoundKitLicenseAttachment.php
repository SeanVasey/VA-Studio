<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseVersion;
use Illuminate\Validation\ValidationException;

/**
 * The two attachment guards of the sound-kit license kind ({@see SoundKitLicenseKind}): a license template of type
 * `sound-kit` is not a beat tier, so a beat offer can never carry one, and a kit can carry nothing else. Kept apart from the
 * kind so the render profile, which pins the kind, does not pin the license models.
 */
final class SoundKitLicenseAttachment
{
    /** A beat offer can never carry a kit license. */
    public static function assertBeatOfferable(?LicenseVersion $version): void
    {
        if ($version !== null && SoundKitLicenseKind::isKitType($version->template?->type)) {
            throw ValidationException::withMessages(['license_version_id' => 'A sound-kit license is not a beat license and cannot be attached to a beat offer.']);
        }
    }

    /** A kit can carry only a sound-kit license; a beat template (any other type) refuses. */
    public static function assertKitAttachable(?LicenseVersion $version): void
    {
        if ($version === null || ! SoundKitLicenseKind::isKitType($version->template?->type)) {
            throw ValidationException::withMessages(['license_version_id' => 'A sound kit can carry only a sound-kit license. Beat license templates cannot be attached to a kit.']);
        }
    }
}
