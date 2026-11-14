<?php

namespace App\Domain\Commerce\ContractIdentity;

/**
 * The legal-name confirmation statements approved by Sean on 2026-11-07 (TERMS appendix A.10), one per licensee type. The
 * buyer ticks the statement for their type; its version and the SHA-256 of its exact text are frozen into the order. A new
 * wording is a new version, never an edit: frozen evidence keeps naming the text the buyer saw.
 */
final class ContractIdentityStatements
{
    public const TEXTS = [
        'individual' => [
            'statement_version' => 'individual-legal-name-confirmation-v1',
            'text' => 'I confirm that this is my full legal name and that the license should be issued to me under it.',
        ],
        'business' => [
            'statement_version' => 'business-legal-name-confirmation-v1',
            'text' => 'I confirm that this is the full legal name of the business, that the license should be issued to the business '
                .'under that name, and that I am authorized to accept it on the business\'s behalf.',
        ],
    ];

    /** @return array<string, array{statement_version: string, statement_hash: string}> the map ContractIdentityDeclarationV2 takes */
    public static function current(): array
    {
        $statements = [];
        foreach (self::TEXTS as $type => $statement) {
            $statements[$type] = ['statement_version' => $statement['statement_version'], 'statement_hash' => hash('sha256', $statement['text'])];
        }

        return $statements;
    }

    /**
     * What the order review shows next to the tick. The client echoes `statementVersion` back; `statementHash` (SHA-256 of
     * the exact text) is there for display only.
     *
     * @return array<string, array{statementVersion: string, statementHash: string, text: string}>
     */
    public static function offered(): array
    {
        $offered = [];
        foreach (self::TEXTS as $type => $statement) {
            $offered[$type] = ['statementVersion' => $statement['statement_version'], 'statementHash' => hash('sha256', $statement['text']),
                'text' => $statement['text']];
        }

        return $offered;
    }
}
