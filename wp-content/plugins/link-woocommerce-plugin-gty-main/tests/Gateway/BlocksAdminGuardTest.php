<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;

/**
 * Tests for the admin/non-AJAX guard in
 * WC_Gateway_LinkMoney_Blocks::get_payment_method_script_handles().
 *
 * In the block editor (admin, non-AJAX), no live API call should fire and the
 * inline savedAccounts data should be empty. On the frontend, behavior is
 * unchanged: the API is called and the inline data is populated.
 *
 * Inline script content is captured via wp_scripts()->get_data($handle, 'before').
 *
 * 2 tests total.
 */
class BlocksAdminGuardTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney_Blocks */
    private \WC_Gateway_LinkMoney_Blocks $blocks_integration;

    /** @var int Test user id with a customer_id meta set. */
    private int $user_id;

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

        // Create a logged-in user with a Link Money customer_id so the API path
        // is reachable when the guard is NOT active.
        $this->user_id = self::factory()->user->create();
        wp_set_current_user( $this->user_id );
        update_user_meta( $this->user_id, \WC_Gateway_LinkMoney::META_CUSTOMER_ID, 'cust-blocks-001' );

        $this->blocks_integration = new \WC_Gateway_LinkMoney_Blocks();
        $this->blocks_integration->initialize();
    }

    public function tearDown(): void {
        // Deregister our handle so subsequent tests start clean.
        wp_deregister_script( 'linkmoney-block-checkout' );
        // Reset admin context so it doesn't leak to other test classes.
        set_current_screen( 'front' );
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * Read the 'before' inline script attached to the linkmoney block handle.
     */
    private function get_inline_data(): string {
        $data = wp_scripts()->get_data( 'linkmoney-block-checkout', 'before' );
        if ( is_array( $data ) ) {
            return implode( '', $data );
        }
        return (string) $data;
    }

    /**
     * @test
     * In an admin (non-AJAX) context, no API call should fire and the inline
     * savedAccounts data must be an empty array.
     */
    public function test_admin_non_ajax_skips_api_call(): void {
        // Force admin context for this test only.
        set_current_screen( 'edit-post' );
        $this->assertTrue( is_admin() );
        $this->assertFalse( wp_doing_ajax() );

        // No HTTP mocks — any call would return MockHttpTrait's 400 fallback.
        $this->blocks_integration->get_payment_method_script_handles();

        $inline = $this->get_inline_data();
        $this->assertStringContainsString( '"savedAccounts":[]', $inline );
    }

    /**
     * @test
     * On the frontend, the existing behavior must hold: the API is called
     * and savedAccounts are populated.
     */
    public function test_frontend_calls_api(): void {
        // Ensure NOT in admin.
        set_current_screen( 'front' );
        $this->assertFalse( is_admin() );

        $this->mock_token_response();
        $this->mock_accounts_response( [
            [
                'accountId'                => 'acct-frontend',
                'accountStatus'            => 'ACTIVE',
                'preferred'                => true,
                'financialInstitutionName' => 'Frontend Bank',
                'accountLastFourDigits'    => '7777',
                'iconUrl'                  => 'https://example.com/fb.png',
            ],
        ] );

        $this->blocks_integration->get_payment_method_script_handles();

        $inline = $this->get_inline_data();
        $this->assertStringContainsString( '"acct-frontend"', $inline );
        $this->assertStringContainsString( '"Frontend Bank"', $inline );
    }
}
