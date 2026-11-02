<?php

namespace LinkMoney\Tests\Helpers;

/**
 * Trait MockHttpTrait
 *
 * Intercepts WordPress HTTP calls (wp_remote_post, wp_remote_get, etc.) via
 * the pre_http_request filter. Tests register mock responses by URL pattern;
 * the filter returns a mocked WP HTTP response array without hitting the network.
 *
 * Usage in a test class:
 *   use MockHttpTrait;
 *
 *   public function setUp(): void {
 *       parent::setUp();
 *       $this->setup_mock_http();
 *   }
 *
 *   public function tearDown(): void {
 *       $this->teardown_mock_http();
 *       parent::tearDown();
 *   }
 */
trait MockHttpTrait {

    /** @var array<array{pattern: string, status: int, body: array}> */
    private array $mock_responses = [];

    /** @var callable|null The registered filter callback, stored for removal. */
    private $mock_http_callback = null;

    /** @var array<array{url: string, body: mixed}> Requests seen by the mock. */
    private array $captured_requests = [];

    /**
     * Register a mock HTTP response for a URL pattern.
     *
     * @param string $url_pattern Substring to match against the request URL.
     * @param int    $status      HTTP response code.
     * @param array  $body        Response body (will be JSON-encoded).
     */
    protected function mock_http_response( string $url_pattern, int $status, array $body ): void {
        $this->mock_responses[] = [
            'pattern' => $url_pattern,
            'status'  => $status,
            'body'    => $body,
        ];
    }

    /**
     * Shortcut: mock a successful OAuth token response.
     *
     * Matches: https://api.link-sandbox.money/v1/tokens
     */
    protected function mock_token_response( string $token = 'test-token' ): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            200,
            [ 'access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 3600 ]
        );
    }

    /**
     * Shortcut: mock a successful session creation response.
     *
     * Matches: https://api.link-sandbox.money/v2/sessions
     */
    protected function mock_session_response( string $session_url = 'https://link.money/session/test' ): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v2/sessions',
            200,
            [ 'sessionUrl' => $session_url, 'sessionId' => 'sess-test-123' ]
        );
    }

    /**
     * Shortcut: mock a successful payment response.
     *
     * Matches: https://api.link-sandbox.money/v1/payments
     *
     * @param string $payment_id    The paymentId to return.
     * @param string $status        The paymentStatus to return (e.g. 'AUTHORIZED', 'PENDING', 'TERMINAL_FAILED').
     */
    protected function mock_payment_response( string $payment_id, string $status = 'AUTHORIZED' ): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/payments',
            200,
            [
                'paymentId'         => $payment_id,
                'paymentStatus'     => $status,
                'paymentStatusCode' => 0,
            ]
        );
    }

    /**
     * Shortcut: mock a Link Money transaction-lookup response.
     *
     * Matches: https://api.link-sandbox.money/v1/transactions/{payment_id}
     *
     * @param string $payment_id          The transactionId to mock.
     * @param string $client_reference_id The clientReferenceId to return (typically the WC order ID as string).
     * @param string $status              The transactionStatus to return (AUTHORIZED, PENDING, TERMINAL_FAILED, etc.).
     * @param string $txn_type            DEBIT or CREDIT.
     * @param array  $extra               Optional extra fields to merge into the body (e.g., transactionFailureReason).
     */
    protected function mock_transaction_response(
        string $payment_id,
        string $client_reference_id,
        string $status = 'AUTHORIZED',
        string $txn_type = 'DEBIT',
        array  $extra = []
    ): void {
        $body = array_merge( [
            'transactionId'     => $payment_id,
            'transactionStatus' => $status,
            'transactionType'   => $txn_type,
            'clientReferenceId' => $client_reference_id,
        ], $extra );

        $this->mock_http_response(
            'api.link-sandbox.money/v1/transactions/' . $payment_id,
            200,
            $body
        );
    }

    /**
     * Shortcut: mock a 404 response for a specific transaction-lookup call.
     */
    protected function mock_transaction_not_found( string $payment_id ): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/transactions/' . $payment_id,
            404,
            [ 'error' => 'transaction not found' ]
        );
    }

    /**
     * Shortcut: mock a successful refund response.
     *
     * Matches: https://api.link-sandbox.money/v1/refunds
     *
     * @param string $payment_id The paymentId returned in the refund response body.
     */
    protected function mock_refund_response( string $payment_id ): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/refunds',
            200,
            [
                'paymentId' => $payment_id,
                'status'    => 'REFUNDED',
            ]
        );
    }

    /**
     * Shortcut: mock a successful get-accounts response.
     *
     * Matches: https://api.link-sandbox.money/v1/customers/ (prefix match)
     *
     * @param array $accounts Array of account detail objects to embed in accountDetails.
     *                        Defaults to one ACTIVE preferred account.
     */
    protected function mock_accounts_response( array $accounts = [] ): void {
        if ( empty( $accounts ) ) {
            $accounts = [
                [
                    'accountId'                  => 'acct-001',
                    'accountStatus'              => 'ACTIVE',
                    'preferred'                  => true,
                    'financialInstitutionName'   => 'Test Bank',
                    'accountLastFourDigits'      => '1234',
                    'iconUrl'                    => 'https://example.com/bank.png',
                ],
            ];
        }
        $this->mock_http_response(
            'api.link-sandbox.money/v1/customers/',
            200,
            [ 'accountDetails' => $accounts ]
        );
    }

    /**
     * Hook the pre_http_request filter to intercept all WP HTTP requests.
     * Call this in setUp().
     */
    protected function setup_mock_http(): void {
        $this->mock_responses = [];

        $this->mock_http_callback = function( $preempt, $parsed_args, $url ) {
            $this->captured_requests[] = [
                'url'  => $url,
                'body' => isset( $parsed_args['body'] ) ? json_decode( $parsed_args['body'], true ) : null,
            ];
            foreach ( $this->mock_responses as $mock ) {
                if ( strpos( $url, $mock['pattern'] ) !== false ) {
                    return $this->build_http_response( $mock['status'], $mock['body'] );
                }
            }
            // Unmatched request: return a generic error response instead of
            // letting the real HTTP call through (which would fail in CI).
            return $this->build_http_response( 400, [ 'error' => 'unmocked-request', 'url' => $url ] );
        };

        add_filter( 'pre_http_request', $this->mock_http_callback, 10, 3 );
    }

    /**
     * Remove the pre_http_request filter. Call this in tearDown().
     */
    protected function teardown_mock_http(): void {
        if ( $this->mock_http_callback !== null ) {
            remove_filter( 'pre_http_request', $this->mock_http_callback, 10 );
            $this->mock_http_callback = null;
        }
        $this->mock_responses    = [];
        $this->captured_requests = [];
    }

    /**
     * Decoded request bodies whose URL contains $url_pattern, in call order.
     *
     * @return array<int, mixed>
     */
    protected function captured_request_bodies( string $url_pattern ): array {
        $out = [];
        foreach ( $this->captured_requests as $req ) {
            if ( strpos( $req['url'], $url_pattern ) !== false ) {
                $out[] = $req['body'];
            }
        }
        return $out;
    }

    /**
     * Build a well-formed WP HTTP response array.
     *
     * @param int   $status HTTP status code.
     * @param array $body   Body data, will be JSON-encoded.
     * @return array WordPress HTTP response array.
     */
    private function build_http_response( int $status, array $body ): array {
        $body_json = wp_json_encode( $body );
        return [
            'headers'  => [],
            'body'     => $body_json,
            'response' => [
                'code'    => $status,
                'message' => get_status_header_desc( $status ),
            ],
            'cookies'  => [],
            'filename' => null,
        ];
    }
}
