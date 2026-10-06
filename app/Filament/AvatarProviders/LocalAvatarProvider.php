<?php

namespace App\Filament\AvatarProviders;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

final class LocalAvatarProvider implements AvatarProvider
{
    public function get(Model|Authenticatable $record): string
    {
        $initials = '';
        foreach (preg_split('/\s+/u', Filament::getNameForDefaultAvatar($record), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $segment) {
            if (preg_match('/[\p{L}\p{N}]/u', $segment, $match) === 1) {
                $initials .= mb_substr(mb_strtoupper($match[0]), 0, 1);
            }
            if (mb_strlen($initials) === 2) {
                break;
            }
        }

        $text = htmlspecialchars($initials === '' ? '?' : $initials, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
        $background = htmlspecialchars(Color::convertToHex(FilamentColor::getColor('gray')[950] ?? Color::Gray[950]), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="'.$background.'"/>'
            .'<text x="32" y="32" text-anchor="middle" dominant-baseline="central" font-family="system-ui, sans-serif" font-size="26" fill="#FFFFFF">'.$text.'</text></svg>';

        // Keep the fallback inside the authenticated page; no identity-bearing image request.
        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
