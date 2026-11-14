<?php

namespace App\Http\Responses;

use Symfony\Component\HttpFoundation\StreamedResponse;

/** Marks the only delivery response allowed to keep Accept-Ranges and its derived ETag (v2 transfer, FP-077). */
final class TestDeliveryTransferResponse extends StreamedResponse {}
