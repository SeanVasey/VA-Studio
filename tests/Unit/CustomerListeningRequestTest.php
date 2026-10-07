<?php

namespace Tests\Unit;

use App\Http\Requests\CustomerListeningRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomerListeningRequestTest extends TestCase
{
    public static function refused(): array
    {
        return [
            ['{"action":"save-track","action":"remove-saved-track","version":0,"trackId":"1"}'],
            ['{"action":"save-track","\\u0061ction":"remove-saved-track","version":0,"trackId":"1"}'],
            ['{"action":"create-playlist","version":0,"name":"A","name":"B"}'],
            ['{"action":"create-playlist","version":0,"name":{"name":"private"}}'],
            ['{"action":"reorder-playlist","version":0,"trackIds":[{"private":"value"}]}'],
            ['{"action":"reorder-playlist","version":0,"trackIds":[1]}'],
            ['{"action":"create-playlist",'],
            ['[]'], ['null'], ['"private"'],
        ];
    }

    #[DataProvider('refused')]
    public function test_duplicate_escaped_keys_and_nested_private_payloads_refuse_without_reflection(string $raw): void
    {
        $request = Request::create('/account/listening-library', 'POST');
        $request->attributes->set('_customer_body', $raw);
        try {
            CustomerListeningRequest::body($request);
            $this->fail('Ambiguous command accepted.');
        } catch (ValidationException $error) {
            $this->assertSame(['library' => ['Choose a valid saved-track or playlist action.']], $error->errors());
        }
    }

    public function test_text_colons_and_escaped_quotes_are_values_and_ordered_string_list_is_retained(): void
    {
        $body = ['action' => 'create-playlist', 'version' => 0, 'name' => 'Mix: "action": sounds'];
        $request = Request::create('/account/listening-library', 'POST');
        $request->attributes->set('_customer_body', json_encode($body, JSON_THROW_ON_ERROR));
        $this->assertSame($body, CustomerListeningRequest::body($request));
        $body = ['action' => 'reorder-playlist', 'version' => 7, 'playlistId' => 'original', 'trackIds' => ['3', '1']];
        $request->attributes->set('_customer_body', json_encode($body, JSON_THROW_ON_ERROR));
        $this->assertSame($body, CustomerListeningRequest::body($request));
    }
}
