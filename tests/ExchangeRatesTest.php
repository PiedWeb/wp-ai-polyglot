<?php

/**
 * @group exchange-rates
 */
class ExchangeRatesTest extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();
        add_filter('polyglot_fx_target_currencies', static fn () => ['DKK']);
    }

    public function testStoresNumericRatesAndTheirPublicationDate(): void
    {
        $this->respondWith($this->xml('7.4567894'));

        $data = polyglot_update_exchange_rates();

        $this->assertSame('EUR', $data['base']);
        $this->assertSame('2026-10-09', $data['date']);
        $this->assertSame(['EUR' => 1.0, 'DKK' => 7.456789], $data['rates']);
        $this->assertSame($data, get_option(POLYGLOT_FX_OPTION));
    }

    /** @dataProvider invalidResponses */
    public function testInvalidResponsesPreserveThePreviousSnapshot(string $body, int $status, string $code): void
    {
        $previous = polyglot_fx_snapshot(['DKK' => 7.45], '2026-10-08');
        update_option(POLYGLOT_FX_OPTION, $previous);
        $this->respondWith($body, $status);

        $result = polyglot_update_exchange_rates();

        $this->assertWPError($result);
        $this->assertSame($code, $result->get_error_code());
        $this->assertSame($previous, get_option(POLYGLOT_FX_OPTION));
    }

    public static function invalidResponses(): array
    {
        return [
            'http failure' => ['unavailable', 503, 'polyglot_fx_http'],
            'empty response' => ['', 200, 'polyglot_fx_empty'],
            'invalid XML' => ['<broken', 200, 'polyglot_fx_parse'],
            'missing currency' => [self::xml('7.45', 'USD'), 200, 'polyglot_fx_missing'],
            'text rate' => [self::xml('invalid'), 200, 'polyglot_fx_invalid'],
            'zero rate' => [self::xml('0'), 200, 'polyglot_fx_invalid'],
            'negative rate' => [self::xml('-7.45'), 200, 'polyglot_fx_invalid'],
        ];
    }

    public function testMissingDateUsesToday(): void
    {
        $this->respondWith(str_replace(' time="2026-10-09"', '', self::xml('7.45')));

        $this->assertSame(gmdate('Y-m-d'), polyglot_update_exchange_rates()['date']);
    }

    public function testNoTargetCurrenciesDoesNotFetch(): void
    {
        add_filter('polyglot_fx_target_currencies', static fn () => [], 20);
        add_filter('pre_http_request', function () {
            $this->fail('No ECB request is needed without foreign currencies.');
        });

        $this->assertSame(['EUR' => 1.0], polyglot_update_exchange_rates()['rates']);
    }

    public function testNumericFilterValuesAreUsed(): void
    {
        update_option(POLYGLOT_FX_OPTION, polyglot_fx_snapshot(['DKK' => 7.45], '2026-10-09'));
        add_filter('polyglot_fx_markup', static fn () => '0.05');
        add_filter('polyglot_fx_round', static fn () => '78.25');

        $this->assertSame(0.05, polyglot_fx_markup('DKK'));
        $this->assertSame(78.25, polyglot_convert_price(10.0, 'DKK'));
    }

    public function testInvalidFilterValuesKeepTheComputedPrice(): void
    {
        update_option(POLYGLOT_FX_OPTION, polyglot_fx_snapshot(['DKK' => 7.45], '2026-10-09'));
        add_filter('polyglot_fx_markup', static fn () => []);
        add_filter('polyglot_fx_round', static fn () => new stdClass());

        $this->assertSame(0.0, polyglot_fx_markup('DKK'));
        $this->assertSame(74.5, polyglot_convert_price(10.0, 'DKK'));
    }

    private function respondWith(string $body, int $status = 200): void
    {
        add_filter('pre_http_request', function ($response, $args, $url) use ($body, $status): array {
            $this->assertSame(POLYGLOT_FX_ECB_URL, $url);

            return ['response' => ['code' => $status], 'body' => $body];
        }, 10, 3);
    }

    private static function xml(string $rate, string $currency = 'DKK'): string
    {
        return '<Envelope xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">'
            .'<Cube><Cube time="2026-10-09"><Cube currency="'.$currency.'" rate="'.$rate.'" />'
            .'</Cube></Cube></Envelope>';
    }
}
