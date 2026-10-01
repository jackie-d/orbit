<?php

namespace Tests\Unit;

use App\Models\InteractionLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InteractionLinkHostTest extends TestCase
{
    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function urls(): array
    {
        return [
            'plain host' => ['https://bluebottlecoffee.com', 'bluebottlecoffee.com'],
            'strips www and lowercases' => ['https://www.Amazon.IT/dp/123?x=1', 'amazon.it'],
            'keeps other subdomains' => ['http://open.spotify.com/track/1', 'open.spotify.com'],
            'ignores port and credentials' => ['https://user:pw@shop.example.com:8443/', 'shop.example.com'],
            'null' => [null, null],
            'empty string' => ['', null],
            'not a url' => ['just some text', null],
        ];
    }

    #[DataProvider('urls')]
    public function test_it_normalizes_the_host_of_a_url(?string $url, ?string $expected): void
    {
        $this->assertSame($expected, InteractionLink::hostFromUrl($url));
    }
}
