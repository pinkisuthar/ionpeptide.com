<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;

/**
 * Tests for token caching in WC_Gateway_LinkMoney::get_access_token().
 *
 * Caching behavior is observed indirectly: the only public path that exercises
 * get_access_token() in a self-contained way is get_saved_accounts() (which
 * uses scope=Link-Core) and process_saved_payment() (Link-Payment scope).
 * For scope-isolation we use reflection to call the protected method directly.
 *
 * Cache hits are proven by NOT registering a token mock — MockHttpTrait returns
 * 400 "unmocked-request" for unmatched URLs, so a leaked HTTP call would make
 * get_access_token return WP_Error and get_saved_accounts return [].
 *
 * 5 tests total.
 */
class TokenCacheTest extends \WP_UnitTestCase {

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
     * Build the expected token cache key for the standard test settings.
     */
    private function token_key( string $environment, string $scope ): string {
        return 'linkmoney_token_' . $environment . '_' . $scope . '_' . md5( 'test-client-id' );
    }

    /**
     * Invoke the protected get_access_token() method via reflection.
     */
    private function invoke_get_access_token( string $scope ): string {
        $method = new \ReflectionMethod( $this->gateway, 'get_access_token' );
        $method->setAccessible( true );
        $result = $method->invoke( $this->gateway, $scope );
        $this->assertIsString( $result, 'Expected token string, got: ' . var_export( $result, true ) );
        return $result;
    }

    /**
     * @test
     * After a successful token fetch, the token MUST be stored in a transient
     * keyed by environment + scope + md5(client_id).
     */
    public function test_token_cached_after_first_call(): void {
        $this->mock_token_response( 'token-from-api' );
        $this->mock_accounts_response();

        $this->gateway->get_saved_accounts( 'cust-test-001' );

        $value = get_transient( $this->token_key( 'sandbox', 'Link-Core' ) );

        $this->assertSame( 'token-from-api', $value );
    }

    /**
     * @test
     * If a token transient exists, get_access_token() MUST NOT make an HTTP call.
     * We register only the accounts mock; if the token endpoint is hit, the
     * MockHttpTrait fallback returns 400 and get_saved_accounts returns [].
     */
    public function test_token_cache_hit_skips_http(): void {
        set_transient( $this->token_key( 'sandbox', 'Link-Core' ), 'cached-token-xyz', 60 * 50 );

        // No token mock registered — only accounts.
        $this->mock_accounts_response();

        $accounts = $this->gateway->get_saved_accounts( 'cust-test-002' );

        $this->assertNotEmpty( $accounts, 'Cache hit failed — token endpoint was hit and returned an error.' );
    }

    /**
     * @test
     * The cache key MUST include the environment so a sandbox token is never
     * served to a production-environment call.
     */
    public function test_token_cache_key_includes_environment(): void {
        $this->mock_token_response( 'sandbox-token' );
        $this->mock_accounts_response();

        $this->gateway->get_saved_accounts( 'cust-test-003' );

        $this->assertSame( 'sandbox-token', get_transient( $this->token_key( 'sandbox', 'Link-Core' ) ) );
        $this->assertFalse( get_transient( $this->token_key( 'production', 'Link-Core' ) ) );
    }

    /**
     * @test
     * The cache key MUST include the scope so a Link-Core token is not served
     * to a Link-Payment caller.
     */
    public function test_token_cache_key_includes_scope(): void {
        $this->mock_token_response( 'scope-test-token' );

        $this->invoke_get_access_token( 'Link-Core' );
        $this->invoke_get_access_token( 'Link-Payment' );

        $core_key    = $this->token_key( 'sandbox', 'Link-Core' );
        $payment_key = $this->token_key( 'sandbox', 'Link-Payment' );

        $this->assertSame( 'scope-test-token', get_transient( $core_key ) );
        $this->assertSame( 'scope-test-token', get_transient( $payment_key ) );
        $this->assertNotSame( $core_key, $payment_key, 'Scopes must produce distinct cache keys.' );
    }

    /**
     * @test
     * A failed token fetch (non-200) MUST NOT write the transient.
     */
    public function test_token_error_not_cached(): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            401,
            [ 'error' => 'invalid_client' ]
        );

        // get_saved_accounts catches the WP_Error and returns [].
        $this->gateway->get_saved_accounts( 'cust-test-004' );

        $this->assertFalse( get_transient( $this->token_key( 'sandbox', 'Link-Core' ) ), 'Failed token fetch must not be cached.' );
    }
}
