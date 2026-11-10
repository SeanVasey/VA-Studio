<?php

namespace App\Domain\Rights;

use Illuminate\Validation\ValidationException;

/**
 * The sound-kit license kind (BL-66, Sean's decision of 2026-11-10): one generic license for sound kits, drum kits and
 * sample packs, whose kit name, purchase date, license fee and licensee are filled per purchase. It is a template KIND,
 * not a beat tier: a license template of type `sound-kit` can print only on a kit, and a beat offer can never carry one.
 *
 * The version's contract text is authored privately by staff, exactly like the six beat templates, and never enters Git.
 * This class holds only what the repository may know about it: the closed placeholder set and the check that a text declares
 * exactly that set. Pure and dependency-free (the sound-kit render profile pins it); the attachment guards are in
 * {@see SoundKitLicenseAttachment}.
 *
 * Placeholders (`{NAME}` in the contract text):
 * - KIT_NAME: the kit or pack as titled on the store;
 * - PURCHASE_DATE: the UTC calendar date of the purchase, `November 7, 2026`;
 * - LICENSE_FEE: the fee paid, from integer minor units and an explicit currency, `$25.00`;
 * - LICENSEE_NAME: the licensee's confirmed legal name;
 * - ORDER_REFERENCE: the store's order reference;
 * - LICENSOR_NAME: the licensor's display name, from configuration (`contracts.vasey_kit_license.licensor_name`).
 */
final class SoundKitLicenseKind
{
    /** The `license_templates.type` value of this kind. */
    public const TYPE = 'sound-kit';

    public const PLACEHOLDERS = ['KIT_NAME', 'PURCHASE_DATE', 'LICENSE_FEE', 'LICENSEE_NAME', 'ORDER_REFERENCE', 'LICENSOR_NAME'];

    /** True for a kit license template type. */
    public static function isKitType(mixed $type): bool
    {
        return $type === self::TYPE;
    }

    /**
     * The placeholders a contract text declares, in first-use order. Brace content that is not a well-formed placeholder
     * name is a refusal (`malformed`) and is never echoed.
     *
     * @return list<string>
     */
    public static function declared(string $text): array
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new \InvalidArgumentException('malformed');
        }
        $parts = preg_split('/\{([^{}]*)\}/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (! is_array($parts)) {
            throw new \InvalidArgumentException('malformed');
        }
        $names = [];
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                if (str_contains($part, '{') || str_contains($part, '}')) {
                    throw new \InvalidArgumentException('malformed');
                }

                continue;
            }
            $names[$part] = true;
        }

        return array_map('strval', array_keys($names));
    }

    /**
     * A kit license text must declare exactly the closed set: every placeholder at least once, and nothing else. An unknown
     * name, a missing one or a stray brace refuses, and nothing is substituted or defaulted silently.
     *
     * @return list<string> empty when the text declares exactly the set; otherwise the problems, as stable codes
     */
    public static function templateProblems(string $text): array
    {
        try {
            $declared = self::declared($text);
        } catch (\InvalidArgumentException) {
            return ['malformed_template'];
        }
        $problems = [];
        foreach (array_diff($declared, self::PLACEHOLDERS) as $name) {
            $problems[] = 'unknown_placeholder:'.(preg_match('/\A[A-Z0-9_]{1,64}\z/', $name) === 1 ? $name : 'redacted');
        }
        foreach (array_diff(self::PLACEHOLDERS, $declared) as $name) {
            $problems[] = 'missing_placeholder:'.$name;
        }

        return $problems;
    }

    public static function assertTemplate(string $text): void
    {
        if (self::templateProblems($text) !== []) {
            throw ValidationException::withMessages(['license' => 'A sound-kit license text must use exactly the kit placeholders: '.implode(', ', self::PLACEHOLDERS).'.']);
        }
    }
}
