<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;

/**
 * Tests for WC_Gateway_LinkMoney::get_saved_accounts().
 *
 * 3 tests total.
 */
class SavedAccountsTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney */
    private \WC_Gateway_LinkMoney $gateway;

    public function setUp(): void {
        parent::setUp();
        $this->setup_mock_http();

        update_option( 'woocommerce_linkmoney_settings', [
            'enabled'       => 'yes',
            'environment'   => 'sandbox',
            'client_id'     => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'merchant_id'   => 'test-merchant-id',
        ] );

        $this->gateway = new \WC_Gateway_LinkMoney();
    }

    public function tearDown(): void {
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * @test
     * get_saved_accounts() should return only ACTIVE+preferred accounts from the API.
     */
    public function test_returns_active_preferred_accounts(): void {
        $this->mock_token_response();
        $this->mock_accounts_response( [
            [
                'accountId'                => 'acct-active',
                'accountStatus'            => 'ACTIVE',
                'preferred'                => true,
                'financialInstitutionName' => 'Good Bank',
                'accountLastFourDigits'    => '5678',
                'iconUrl'                  => 'https://example.com/good.png',
            ],
            [
                'accountId'                => 'acct-inactive',
                'accountStatus'            => 'INACTIVE',
                'preferred'                => true,
                'financialInstitutionName' => 'Old Bank',
                'accountLastFourDigits'    => '0000',
                'iconUrl'                  => '',
            ],
            [
                'accountId'                => 'acct-not-preferred',
                'accountStatus'            => 'ACTIVE',
                'preferred'                => false,
                'financialInstitutionName' => 'Other Bank',
                'accountLastFourDigits'    => '9999',
                'iconUrl'                  => '',
            ],
        ] );

        $accounts = $this->gateway->get_saved_accounts( 'cust-test-001' );

        $this->assertCount( 1, $accounts );
        $this->assertSame( 'acct-active', $accounts[0]['accountId'] );
        $this->assertSame( 'Good Bank', $accounts[0]['bankName'] );
        $this->assertSame( '5678', $accounts[0]['last4'] );
    }

    /**
     * @test
     * get_saved_accounts() should return an empty array when customer_id is empty.
     * No HTTP call should be made.
     */
    public function test_empty_customer_id(): void {
        // No mocks needed — should bail early.
        $result = $this->gateway->get_saved_accounts( '' );
        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }

    /**
     * @test
     * get_saved_accounts() should return an empty array when the API returns an error.
     */
    public function test_api_failure(): void {
        $this->mock_token_response();
        $this->mock_http_response( 'api.link-sandbox.money/v1/customers/', 500, [ 'error' => 'server error' ] );

        $result = $this->gateway->get_saved_accounts( 'cust-test-fail' );

        $this->assertIsArray( $result );
        $this->assertEmpty( $result );
    }

    /**
     * @test
     * A successful saved-accounts fetch MUST be stored in a transient keyed
     * by md5(customer_id), holding the normalized account array.
     */
    public function test_accounts_cached_after_first_call(): void {
        $this->mock_token_response();
        $this->mock_accounts_response();

        $accounts = $this->gateway->get_saved_accounts( 'cust-test-cache-001' );

        $key    = 'linkmoney_accounts_' . md5( 'cust-test-cache-001' );
        $cached = get_transient( $key );

        $this->assertNotFalse( $cached, 'Accounts should be cached after first call.' );
        $this->assertEquals( $accounts, $cached, 'Cached value must match returned value.' );
    }

    /**
     * @test
     * If an accounts transient exists, get_saved_accounts() MUST NOT make any
     * HTTP call. We register no mocks; if either token or accounts endpoints
     * are hit, MockHttpTrait returns 400 and the result becomes [].
     */
    public function test_accounts_cache_hit_skips_http(): void {
        $cached_accounts = [
            [
                'accountId' => 'acct-from-cache',
                'bankName'  => 'Cached Bank',
                'last4'     => '4321',
                'logoUrl'   => 'https://example.com/cached.png',
            ],
        ];
        $key = 'linkmoney_accounts_' . md5( 'cust-test-cache-002' );
        set_transient( $key, $cached_accounts, 60 * 10 );

        // No mocks registered.
        $accounts = $this->gateway->get_saved_accounts( 'cust-test-cache-002' );

        $this->assertEquals( $cached_accounts, $accounts );
    }

    /**
     * @test
     * Empty customer_id MUST short-circuit before any cache or HTTP work.
     * No transient should be written for an empty key.
     */
    public function test_empty_customer_id_does_not_cache(): void {
        $this->gateway->get_saved_accounts( '' );

        $key = 'linkmoney_accounts_' . md5( '' );
        $this->assertFalse( get_transient( $key ) );
    }
}
