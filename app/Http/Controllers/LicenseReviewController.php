<?php

namespace App\Http\Controllers;

use App\Domain\Rights\LicensePreview;
use App\Domain\Rights\Models\LicenseVersion;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class LicenseReviewController extends Controller
{
    public function __invoke(LicenseVersion $license): Response
    {
        try {
            $html = app(LicensePreview::class)->render($license)['html'];
        } catch (ValidationException $exception) {
            $html = view('admin.license-retained-source', ['license' => $license, 'errors' => $exception->validator->errors()->all()])->render();
        }

        return response($html)->withHeaders([
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",
        ]);
    }
}
