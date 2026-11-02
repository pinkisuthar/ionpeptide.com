# Integration Test Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Full integration test suite with 54 tests covering all Link Money gateway flows, running in GitHub Actions CI.

**Architecture:** PHPUnit 10 with WordPress test framework (wp-phpunit) + WooCommerce 10.6.x. External API calls mocked via pre_http_request filter. MySQL service container in CI.

**Tech Stack:** PHP 8.2, PHPUnit 10, yoast/phpunit-polyfills, WordPress test suite, WooCommerce 10.6.2, GitHub Actions

---

## Source Truth — Key Facts From Reading the Code

Before implementing, note these exact details sourced from `includes/class-wc-gateway-linkmoney.php`:

**Constants:**
- `WC_Gateway_LinkMoney::META_CUSTOMER_ID` = `'_linkmoney_customer_id'`
- `WC_Gateway_LinkMoney::META_PAYMENT_ID` = `'_linkmoney_payment_id'`
- `WC_Gateway_LinkMoney::META_EVER_PROCESSING` = `'_linkmoney_ever_processing'`

**API endpoints (sandbox base = `https://api.link-sandbox.money`):**
- Token: `POST {base}/v1/tokens` (NOT `/oauth/token`)
- Session: `POST {base}/v2/sessions`
- Payments: `POST {base}/v1/payments`
- Refunds: `POST {base}/v1/refunds`
- Accounts: `GET {base}/v1/customers/{customer_id}/accounts`

**Token request:** form-encoded body with `client_id`, `client_secret`, `grant_type=client_credentials`, `scope`. Expects JSON response with `access_token`.

**Session request:** Basic Auth header (`Authorization: Basic base64(client_id:client_secret)`), JSON body. Expects `sessionUrl` in response.

**`get_access_token`:** protected method. Takes `$scope = 'Link-Payment'`. Returns string token or `WP_Error`.

**`process_payment`:** If `$_POST['linkmoney_saved_account']` is set and not `'__new'`, delegates to `process_saved_payment`. Otherwise calls `create_session`, updates order to `checkout-draft`, returns `['result'=>'success','redirect'=>$sessionUrl]`.

**`process_saved_payment`:** Duplicate guard checks `META_PAYMENT_ID` OR `META_EVER_PROCESSING` — if either is set and order is not failed, returns failure. On PENDING paymentStatus, sets on-hold. On failed order with `order_ever_was_processing`, sets completed. Otherwise sets processing + `META_EVER_PROCESSING`.

**`store_api_process_linkmoney`:** When `selected === '__new'` or `selected === ''`, the method calls `create_session()`, sets order to `checkout-draft`, and sets `$result->set_redirect_url($sessionUrl)`. When a valid account ID is selected, calls payments API. Status logic: PENDING → on-hold, failed+ever_processing → completed, otherwise → processing. Triggers emails, empties cart, calls `$result->set_status('success')` and `$result->set_redirect_url(...)`.

**`handle_wc_api_callback`:** Validates order_id + order_key from `$_GET`. If `status >= '204'`, redirects to checkout with `lm_restore_order` params. Otherwise processes `paymentStatusCode`: 0=processing+emails, 1=failed+restore cart, 2=on-hold (if pending/draft). All paths call `wp_safe_redirect()` + `exit`.

**`linkmoney_handle_return`:** Called via `woocommerce_thankyou_{linkmoney}` and `woocommerce_receipt_{linkmoney}`. Checks `$_GET['status']` and `$_GET['paymentStatusCode']`. Status >= 204 = redirect. Same status code logic as callback. Does NOT call `wp_safe_redirect` for status code 2 (falls through, no redirect for on-hold).

**`process_refund`:** Returns `WP_Error` if no order, no `META_PAYMENT_ID`, token failure, or API error. Returns `true` on success. Adds order note on success.

**`handle_webhook`:** Static method. Reads `linkmoney_webhook_secret` option. Signature = `base64(hmac-sha512(unique_id.timestamp.body, secret))`. Returns `WP_Error` for missing secret (403), missing headers (400), bad signature (403), missing `metadata.resourceId` (400), order not found (404). Events: `payment.authorized` → processing + emails, `payment.succeeded` → order note only, `payment.failed` → failed. Returns `rest_ensure_response(['success'=>true])` on success.

**`maybe_restore_cart_from_order`:** Public. Reads `$_GET['lm_restore_order']` and `$_GET['lm_restore_key']`. Validates order key. Sets `store_api_draft_order` session to 0, deletes order, empties cart, re-adds items.

**`restore_cart_from_order`:** Private. Skips if order is processing/completed. Skips if cart is not empty. Adds order items back.

**`order_ever_was_processing`:** Private static. Returns bool based on `META_EVER_PROCESSING` meta.

**`trigger_awaiting_payment_emails`:** Private static. Triggers `WC_Email_New_Order` email via `$mailer->emails['WC_Email_New_Order']->trigger($order->get_id())`. Adds order note.

---

## Task 1: Infrastructure

Create `composer.json`, `phpunit.xml`, `bin/install-wp-tests.sh`, `.github/workflows/tests.yml`.

- [ ] Create `composer.json` at the plugin root:

```json
{
    "name": "link-financial/link-woocommerce-plugin",
    "description": "WooCommerce payment gateway plugin for Link Money",
    "require": {},
    "require-dev": {
        "phpunit/phpunit": "^10.0",
        "yoast/phpunit-polyfills": "^2.0"
    },
    "autoload": {
        "psr-4": {}
    },
    "autoload-dev": {
        "psr-4": {
            "LinkMoney\\Tests\\": "tests/"
        }
    },
    "scripts": {
        "test": "vendor/bin/phpunit"
    },
    "config": {
        "allow-plugins": {
            "dealerdirect/phpcodesniffer-composer-installer": true
        }
    }
}
```

- [ ] Create `phpunit.xml` at the plugin root:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit
    xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
    xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
    bootstrap="tests/bootstrap.php"
    colors="true"
    verbose="true"
    stopOnFailure="false"
>
    <testsuites>
        <testsuite name="Link Money Integration Tests">
            <directory suffix="Test.php">tests/Gateway</directory>
        </testsuite>
    </testsuites>
    <coverage>
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

- [ ] Create `bin/install-wp-tests.sh`. This is the standard WordPress plugin test installer. Make it executable with `chmod +x bin/install-wp-tests.sh`:

```bash
#!/usr/bin/env bash
# Standard WordPress test suite installer.
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]

if [ $# -lt 3 ]; then
    echo "usage: $0 <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-database-creation]"
    exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATE=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo $TMPDIR | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress}

download() {
    if [ `which curl` ]; then
        curl -s "$1" > "$2";
    elif [ `which wget` ]; then
        wget -nv -O "$2" "$1"
    fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
    WP_TESTS_TAG="branches/$WP_VERSION"
elif [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0]$ ]]; then
        # version x.x.0 (e.g. 6.0.0) is not tagged, use the "x.x" branch
        WP_TESTS_TAG="branches/${WP_VERSION%??}"
    else
        WP_TESTS_TAG="tags/$WP_VERSION"
    fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
    WP_TESTS_TAG="trunk"
else
    # http: //api.wordpress.org/core/version-check/1.7/ always returns a list of latest
    download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
    LATEST_VERSION=$(grep -o '"version":"[^"]*' /tmp/wp-latest.json | sed 's/"version":"//') 
    if [[ -z "$LATEST_VERSION" ]]; then
        echo "Latest WordPress version could not be found"
        exit 1
    fi
    WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

set -ex

install_wp() {
    if [ -d $WP_CORE_DIR ]; then
        return;
    fi

    mkdir -p $WP_CORE_DIR

    if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
        mkdir -p $TMPDIR/wordpress-nightly
        download https://wordpress.org/nightly-builds/wordpress-latest.zip $TMPDIR/wordpress-nightly/wordpress-nightly.zip
        unzip -q $TMPDIR/wordpress-nightly/wordpress-nightly.zip -d $TMPDIR/wordpress-nightly/
        mv $TMPDIR/wordpress-nightly/wordpress/* $WP_CORE_DIR
    else
        if [ $WP_VERSION == 'latest' ]; then
            local ARCHIVE_NAME='latest'
        elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+ ]]; then
            # https://wordpress.org/wordpress-5.5.zip
            local ARCHIVE_NAME="wordpress-$WP_VERSION"
        fi
        download https://wordpress.org/wordpress-latest.zip $TMPDIR/wordpress.zip
        unzip -q $TMPDIR/wordpress.zip -d $TMPDIR/
        mv $TMPDIR/wordpress/* $WP_CORE_DIR
        rm $TMPDIR/wordpress.zip
    fi

    download https://raw.github.com/markoheijnen/wp-mysqli/master/db.php $WP_CORE_DIR/wp-content/db.php
}

install_test_suite() {
    # portable in-place argument for both GNU sed and Mac OSX sed
    if [[ $(uname -s) == 'Darwin' ]]; then
        local ioption='-i.bak'
    else
        local ioption='-i'
    fi

    # set up testing suite if it doesn't yet exist
    if [ ! -d $WP_TESTS_DIR ]; then
        mkdir -p $WP_TESTS_DIR

        svn co --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/ $WP_TESTS_DIR/includes
        svn co --quiet --ignore-externals https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/ $WP_TESTS_DIR/data
    fi

    if [ ! -f wp-tests-config.php ]; then
        download https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"
        # remove all forward slashes in the end
        WP_CORE_DIR=$(echo $WP_CORE_DIR | sed "s:/\+$::")
        sed $ioption "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR/wp-tests-config.php"
        sed $ioption "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
        sed $ioption "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
        sed $ioption "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
        sed $ioption "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
    fi
}

install_db() {
    if [ ${SKIP_DB_CREATE} = "true" ]; then
        return 0
    fi

    # parse DB_HOST for port or socket references
    local PARTS=(${DB_HOST//\:/ })
    local DB_HOSTNAME=${PARTS[0]};
    local DB_SOCK_OR_PORT=${PARTS[1]};
    local EXTRA=""

    if ! [ -z $DB_SOCK_OR_PORT ] ; then
        if [ $(echo $DB_SOCK_OR_PORT | grep -e '^[0-9]\{1,\}$') ]; then
            EXTRA=" --host=$DB_HOSTNAME --port=$DB_SOCK_OR_PORT --protocol=tcp"
        else
            EXTRA=" --socket=$DB_SOCK_OR_PORT"
        fi
    elif ! [ -z $DB_HOSTNAME ] ; then
        EXTRA=" --host=$DB_HOSTNAME --protocol=tcp"
    fi

    mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS"$EXTRA
}

install_wp
install_test_suite
install_db
```

- [ ] Create `.github/workflows/tests.yml`:

```yaml
name: Integration Tests

on:
  push:
    branches:
      - main
      - 'fix/**'
      - 'feature/**'
  pull_request:
    branches:
      - main
      - 'fix/**'
      - 'feature/**'

jobs:
  test:
    runs-on: ubuntu-latest

    services:
      mysql:
        image: mysql:8.0
        env:
          MYSQL_ROOT_PASSWORD: root
          MYSQL_DATABASE: wp_tests
        ports:
          - 3306:3306
        options: >-
          --health-cmd="mysqladmin ping"
          --health-interval=10s
          --health-timeout=5s
          --health-retries=3

    env:
      DB_NAME: wp_tests
      DB_USER: root
      DB_PASS: root
      DB_HOST: 127.0.0.1
      WP_VERSION: latest
      WC_VERSION: 10.6.2

    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Setup PHP 8.2
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
          extensions: mbstring, intl, pdo, pdo_mysql, mysqli
          coverage: none

      - name: Install Composer dependencies
        run: composer install --prefer-dist --no-interaction --no-progress

      - name: Make install script executable
        run: chmod +x bin/install-wp-tests.sh

      - name: Install WordPress test suite
        run: bash bin/install-wp-tests.sh $DB_NAME $DB_USER $DB_PASS $DB_HOST $WP_VERSION

      - name: Download WooCommerce ${{ env.WC_VERSION }}
        run: |
          WC_ZIP_URL="https://github.com/woocommerce/woocommerce/releases/download/$WC_VERSION/woocommerce.zip"
          WP_PLUGINS_DIR="/tmp/wordpress/wp-content/plugins"
          mkdir -p "$WP_PLUGINS_DIR"
          curl -sL "$WC_ZIP_URL" -o /tmp/woocommerce.zip
          unzip -q /tmp/woocommerce.zip -d "$WP_PLUGINS_DIR"

      - name: Copy plugin to WordPress plugins directory
        run: |
          WP_PLUGINS_DIR="/tmp/wordpress/wp-content/plugins"
          mkdir -p "$WP_PLUGINS_DIR/link-woocommerce-plugin-gty"
          rsync -a --exclude='.git' --exclude='vendor' --exclude='node_modules' \
            . "$WP_PLUGINS_DIR/link-woocommerce-plugin-gty/"

      - name: Run PHPUnit
        run: vendor/bin/phpunit --testdox
```

- [ ] Run `composer install` locally to verify `composer.json` is valid and dependencies resolve.

- [ ] Commit: `git add composer.json phpunit.xml bin/install-wp-tests.sh .github/workflows/tests.yml && git commit -m "test: add PHPUnit infrastructure, CI workflow, and WP test installer"`

---

## Task 2: Bootstrap + Test Helpers

Create `tests/bootstrap.php`, `tests/Helpers/MockHttpTrait.php`, `tests/Helpers/OrderHelper.php`.

- [ ] Create the directory structure:
  ```
  mkdir -p tests/Helpers tests/Gateway
  ```

- [ ] Create `tests/bootstrap.php`:

```php
<?php
/**
 * PHPUnit bootstrap for Link Money integration tests.
 *
 * Loads the WordPress test suite, activates WooCommerce and the Link Money
 * plugin, and requires the test helpers.
 */

// Composer autoloader (for PHPUnit polyfills and test classes).
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Locate the WordPress test suite. Allow override via env for CI.
$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
    $_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
    echo "Could not find WordPress test suite at '{$_tests_dir}'." . PHP_EOL;
    echo "Set WP_TESTS_DIR env or run bin/install-wp-tests.sh first." . PHP_EOL;
    exit( 1 );
}

// Locate WordPress core. Allow override via env.
$_wp_core_dir = getenv( 'WP_CORE_DIR' );
if ( ! $_wp_core_dir ) {
    $_wp_core_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress';
}

// Give tests access to the plugin directory constant (needed by linkmoney.php).
define( 'LINKMONEY_TEST_PLUGIN_DIR', dirname( __DIR__ ) . '/' );

// Hook to load our plugin AFTER WordPress + WooCommerce are set up.
tests_add_filter( 'muplugins_loaded', function() {
    // Activate WooCommerce.
    $wc_plugin = WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
    if ( ! file_exists( $wc_plugin ) ) {
        echo "WooCommerce plugin not found at '{$wc_plugin}'." . PHP_EOL;
        echo "Ensure WooCommerce is installed to the WordPress plugins directory." . PHP_EOL;
        exit( 1 );
    }
    require_once $wc_plugin;
} );

tests_add_filter( 'setup_theme', function() {
    // Activate Link Money plugin after WordPress and WooCommerce are loaded.
    require_once LINKMONEY_TEST_PLUGIN_DIR . 'linkmoney.php';
} );

// Load the WordPress test suite bootstrap. This sets up the DB and WP.
require $_tests_dir . '/includes/bootstrap.php';

// Load test helpers.
require_once __DIR__ . '/Helpers/MockHttpTrait.php';
require_once __DIR__ . '/Helpers/OrderHelper.php';
```

- [ ] Create `tests/Helpers/MockHttpTrait.php`:

```php
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
        $this->mock_responses = [];
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
            'headers'  => new \Requests_Utility_CaseInsensitiveDictionary( [
                'content-type' => 'application/json',
            ] ),
            'body'     => $body_json,
            'response' => [
                'code'    => $status,
                'message' => \WP_HTTP::get_status_header_desc( $status ),
            ],
            'cookies'  => [],
            'filename' => null,
        ];
    }
}
```

- [ ] Create `tests/Helpers/OrderHelper.php`:

```php
<?php

namespace LinkMoney\Tests\Helpers;

/**
 * Factory for creating WooCommerce test orders configured for Link Money.
 *
 * All created orders use payment_method=linkmoney and have standard billing
 * details populated. Use the return value directly in tests.
 */
class OrderHelper {

    /**
     * Create a pending WC_Order with Link Money as the payment method.
     *
     * @param array $overrides Key-value pairs to override default order data.
     * @return \WC_Order
     */
    public static function create_pending_order( array $overrides = [] ): \WC_Order {
        return self::create_order( array_merge( [ 'status' => 'pending' ], $overrides ) );
    }

    /**
     * Create a checkout-draft WC_Order with Link Money as the payment method.
     *
     * @param array $overrides Key-value pairs to override default order data.
     * @return \WC_Order
     */
    public static function create_checkout_draft_order( array $overrides = [] ): \WC_Order {
        return self::create_order( array_merge( [ 'status' => 'checkout-draft' ], $overrides ) );
    }

    /**
     * Create a processing WC_Order with a Link Money payment ID set in meta.
     *
     * @param string $payment_id The value for META_PAYMENT_ID.
     * @return \WC_Order
     */
    public static function create_processing_order( string $payment_id ): \WC_Order {
        $order = self::create_order( [ 'status' => 'processing' ] );
        $order->update_meta_data( \WC_Gateway_LinkMoney::META_PAYMENT_ID, $payment_id );
        $order->update_meta_data( \WC_Gateway_LinkMoney::META_EVER_PROCESSING, true );
        $order->save();
        return $order;
    }

    /**
     * Create a failed WC_Order, optionally with META_EVER_PROCESSING set.
     *
     * @param bool $ever_processing Whether to set META_EVER_PROCESSING on the order.
     * @return \WC_Order
     */
    public static function create_failed_order( bool $ever_processing = false ): \WC_Order {
        $order = self::create_order( [ 'status' => 'failed' ] );
        if ( $ever_processing ) {
            $order->update_meta_data( \WC_Gateway_LinkMoney::META_EVER_PROCESSING, true );
            $order->save();
        }
        return $order;
    }

    /**
     * Create a simple WC_Product for use as an order line item.
     *
     * @param float $price Product price.
     * @return \WC_Product_Simple
     */
    public static function create_simple_product( float $price = 20.00 ): \WC_Product_Simple {
        $product = new \WC_Product_Simple();
        $product->set_name( 'Test Product' );
        $product->set_regular_price( (string) $price );
        $product->set_price( (string) $price );
        $product->set_status( 'publish' );
        $product->set_manage_stock( false );
        $product->save();
        return $product;
    }

    /**
     * Internal factory used by all public create_* methods.
     *
     * @param array $args Overrides merged with defaults.
     * @return \WC_Order
     */
    private static function create_order( array $args = [] ): \WC_Order {
        $product = self::create_simple_product( 20.00 );

        $order = wc_create_order( [
            'status'         => $args['status'] ?? 'pending',
            'payment_method' => 'linkmoney',
            'payment_method_title' => 'Same-Day Bank Payment',
        ] );

        // Add a line item.
        $order->add_product( $product, 1 );

        // Set billing details.
        $order->set_billing_first_name( 'Jane' );
        $order->set_billing_last_name( 'Doe' );
        $order->set_billing_email( 'jane@example.com' );
        $order->set_billing_phone( '555-1234' );
        $order->set_billing_address_1( '123 Main St' );
        $order->set_billing_city( 'Anytown' );
        $order->set_billing_state( 'CA' );
        $order->set_billing_postcode( '90210' );
        $order->set_billing_country( 'US' );

        // Calculate and save totals.
        $order->calculate_totals();
        $order->save();

        return $order;
    }
}
```

- [ ] Commit: `git add tests/ && git commit -m "test: add bootstrap, MockHttpTrait, and OrderHelper"`

---

## Task 3: SessionTest + SavedAccountsTest (9 tests)

Create `tests/Gateway/SessionTest.php` and `tests/Gateway/SavedAccountsTest.php`.

**Key behaviors to test:**
- `create_session()`: POSTs to `https://api.link-sandbox.money/v2/sessions` with Basic Auth. Returns array with `sessionUrl` on success, `WP_Error` on HTTP 400+.
- `get_access_token()`: Protected. POSTs to `https://api.link-sandbox.money/v1/tokens` with form-encoded body. Returns token string or `WP_Error`.
- `get_saved_accounts()`: Returns empty array for empty/invalid customer_id. Calls accounts endpoint. Returns only ACTIVE+preferred accounts.

- [ ] Create `tests/Gateway/SessionTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::create_session() and get_access_token().
 *
 * 6 tests total.
 */
class SessionTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney */
    private \WC_Gateway_LinkMoney $gateway;

    public function setUp(): void {
        parent::setUp();
        $this->setup_mock_http();

        // Configure gateway settings so constructor reads them.
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
     * create_session() should return an array containing sessionUrl on success.
     */
    public function test_create_session_success(): void {
        $this->mock_session_response( 'https://link.money/session/abc123' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->create_session( $order );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'sessionUrl', $result );
        $this->assertSame( 'https://link.money/session/abc123', $result['sessionUrl'] );
    }

    /**
     * @test
     * create_session() payload should include correct billing data, amount, and redirectUrl.
     */
    public function test_create_session_payload(): void {
        // Capture the request args via pre_http_request before returning success.
        $captured_body = null;
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_body ) {
            if ( strpos( $url, 'v2/sessions' ) !== false ) {
                $captured_body = json_decode( $args['body'], true );
            }
            return $preempt; // Let MockHttpTrait handle the actual response.
        }, 5, 3 ); // Priority 5 runs before MockHttpTrait's priority-10 callback.

        $this->mock_session_response();

        $order  = OrderHelper::create_pending_order();
        $this->gateway->create_session( $order );

        $this->assertNotNull( $captured_body, 'Session request body was not captured.' );
        $this->assertSame( 'Jane', $captured_body['firstName'] );
        $this->assertSame( 'Doe', $captured_body['lastName'] );
        $this->assertSame( '123 Main St', $captured_body['billingAddress']['streetAddress'] );
        $this->assertArrayHasKey( 'redirectUrl', $captured_body );
        $this->assertStringContainsString( 'wc-api=linkmoney', $captured_body['redirectUrl'] );
        // Amount should be formatted as "20.00"
        $this->assertSame( '20.00', $captured_body['orderDetails']['totalAmount']['value'] );
    }

    /**
     * @test
     * create_session() should return WP_Error when the API returns HTTP 400+.
     */
    public function test_create_session_api_failure(): void {
        $this->mock_http_response( 'api.link-sandbox.money/v2/sessions', 400, [ 'error' => 'bad request' ] );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->create_session( $order );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * @test
     * get_access_token() should return a token string on success.
     * Uses ReflectionMethod because the method is protected.
     */
    public function test_get_access_token_success(): void {
        $this->mock_token_response( 'my-access-token' );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'get_access_token' );
        $method->setAccessible( true );

        $token = $method->invoke( $this->gateway, 'Link-Payment' );

        $this->assertIsString( $token );
        $this->assertSame( 'my-access-token', $token );
    }

    /**
     * @test
     * get_access_token() should return WP_Error when the API returns 401.
     */
    public function test_get_access_token_failure(): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            401,
            [ 'error' => 'invalid_client' ]
        );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'get_access_token' );
        $method->setAccessible( true );

        $result = $method->invoke( $this->gateway, 'Link-Payment' );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'linkmoney_token', $result->get_error_code() );
    }

    /**
     * @test
     * create_session() for a guest (not logged in) should use billing_email from the order.
     */
    public function test_guest_uses_order_email(): void {
        // Ensure no user is logged in.
        wp_set_current_user( 0 );

        $captured_body = null;
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_body ) {
            if ( strpos( $url, 'v2/sessions' ) !== false ) {
                $captured_body = json_decode( $args['body'], true );
            }
            return $preempt;
        }, 5, 3 );

        $this->mock_session_response();

        $order  = OrderHelper::create_pending_order();
        $this->gateway->create_session( $order );

        $this->assertNotNull( $captured_body );
        // Billing email set by OrderHelper::create_order is 'jane@example.com'.
        $this->assertSame( 'jane@example.com', $captured_body['email'] );
    }
}
```

- [ ] Create `tests/Gateway/SavedAccountsTest.php`:

```php
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
}
```

- [ ] Commit: `git add tests/Gateway/SessionTest.php tests/Gateway/SavedAccountsTest.php && git commit -m "test: add SessionTest and SavedAccountsTest (9 tests)"`

---

## Task 4: ProcessPaymentTest + ProcessSavedPaymentTest (10 tests)

Create `tests/Gateway/ProcessPaymentTest.php` and `tests/Gateway/ProcessSavedPaymentTest.php`.

**Key behaviors:**
- `process_payment()` with `$_POST['linkmoney_saved_account'] = '__new'` → calls `create_session`, updates order to `checkout-draft`, returns `['result'=>'success','redirect'=>$sessionUrl]`.
- `process_payment()` with saved account ID in `$_POST` → delegates to `process_saved_payment`.
- `process_saved_payment()` duplicate guard: if `META_PAYMENT_ID` OR `META_EVER_PROCESSING` is set and order is NOT in failed status → returns failure.
- `process_saved_payment()` retry: failed order with `META_EVER_PROCESSING` → sets completed.
- `process_saved_payment()` PENDING paymentStatus → sets on-hold.

- [ ] Create `tests/Gateway/ProcessPaymentTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::process_payment() — classic checkout entry point.
 *
 * 3 tests total.
 */
class ProcessPaymentTest extends \WP_UnitTestCase {

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

        // Start with clean $_POST.
        $_POST = [];
    }

    public function tearDown(): void {
        $_POST = [];
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * @test
     * When no saved account is selected (__new), process_payment() should create a
     * Link Money session, update the order to checkout-draft, and return a success
     * result redirecting to the sessionUrl.
     */
    public function test_new_account_creates_session_and_redirects(): void {
        $_POST['linkmoney_saved_account'] = '__new';

        $this->mock_session_response( 'https://link.money/session/new-flow' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_payment( $order->get_id() );

        $this->assertIsArray( $result );
        $this->assertSame( 'success', $result['result'] );
        $this->assertStringContainsString( 'https://link.money/session/new-flow', $result['redirect'] );

        // Order should now be in checkout-draft status.
        $order = wc_get_order( $order->get_id() ); // Refresh.
        $this->assertTrue( $order->has_status( 'checkout-draft' ) );
    }

    /**
     * @test
     * When the session API fails, process_payment() should return a failure result.
     */
    public function test_new_account_session_failure(): void {
        $_POST['linkmoney_saved_account'] = '__new';

        $this->mock_http_response( 'api.link-sandbox.money/v2/sessions', 500, [ 'error' => 'server error' ] );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_payment( $order->get_id() );

        $this->assertIsArray( $result );
        $this->assertSame( 'failure', $result['result'] );
    }

    /**
     * @test
     * When a saved account ID is in $_POST, process_payment() delegates to
     * process_saved_payment(), resulting in order → processing.
     */
    public function test_saved_account_delegates_to_process_saved_payment(): void {
        $_POST['linkmoney_saved_account'] = 'acct-saved-001';

        $this->mock_token_response();
        $this->mock_payment_response( 'pay-001', 'AUTHORIZED' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_payment( $order->get_id() );

        $this->assertIsArray( $result );
        $this->assertSame( 'success', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
    }
}
```

- [ ] Create `tests/Gateway/ProcessSavedPaymentTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::process_saved_payment().
 *
 * 7 tests total.
 */
class ProcessSavedPaymentTest extends \WP_UnitTestCase {

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
     * On a successful AUTHORIZED payment, the order becomes processing,
     * META_EVER_PROCESSING is set, and the result is success.
     */
    public function test_success_sets_processing(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-success-001', 'AUTHORIZED' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'success', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertNotEmpty( $order->get_meta( \WC_Gateway_LinkMoney::META_EVER_PROCESSING ) );
    }

    /**
     * @test
     * When paymentStatus is PENDING, the order should be set to on-hold.
     */
    public function test_pending_sets_on_hold(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-pending-001', 'PENDING' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'on-hold' ) );
    }

    /**
     * @test
     * When the payments API returns HTTP 4xx, the order should be set to failed.
     */
    public function test_declined_sets_failed(): void {
        $this->mock_token_response();
        $this->mock_http_response( 'api.link-sandbox.money/v1/payments', 402, [
            'errorMessage' => 'Insufficient funds.',
        ] );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * When paymentStatus is TERMINAL_FAILED, the order should be set to failed.
     */
    public function test_terminal_failed_sets_failed(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-terminal-001', 'TERMINAL_FAILED' );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * The duplicate guard should block resubmission when META_PAYMENT_ID is set
     * and the order is not in a failed status.
     */
    public function test_duplicate_guard_blocks_resubmit(): void {
        $this->mock_token_response();
        // No payment mock needed — method should bail before calling the API.

        $order = OrderHelper::create_processing_order( 'pay-existing-001' );
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );
    }

    /**
     * @test
     * A failed order that previously reached processing (META_EVER_PROCESSING = true)
     * should be set to completed when payment succeeds again.
     */
    public function test_retry_failed_order_sets_completed(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-retry-001', 'AUTHORIZED' );

        $order = OrderHelper::create_failed_order( true ); // ever_processing = true
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'success', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'completed' ) );
    }

    /**
     * @test
     * When get_access_token() fails, the order should be set to failed and
     * result should indicate failure.
     */
    public function test_token_failure(): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            401,
            [ 'error' => 'invalid_client' ]
        );

        $order  = OrderHelper::create_pending_order();
        $result = $this->gateway->process_saved_payment( $order, 'acct-001' );

        $this->assertSame( 'failure', $result['result'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }
}
```

- [ ] Commit: `git add tests/Gateway/ProcessPaymentTest.php tests/Gateway/ProcessSavedPaymentTest.php && git commit -m "test: add ProcessPaymentTest and ProcessSavedPaymentTest (10 tests)"`

---

## Task 5: StoreApiProcessTest (6 tests)

Create `tests/Gateway/StoreApiProcessTest.php`.

**Key behaviors from source code (fix/LM-6188 branch):**
- When `selected === '__new'` or `selected === ''`: calls `create_session()`, sets order to `checkout-draft`, sets `$result->set_redirect_url($sessionUrl)`. On session failure: throws `\Exception`.
- When a valid account ID is selected: calls payments API. PENDING → on-hold, failed+ever_processing → completed, otherwise → processing. Calls `$result->set_status('success')` and `$result->set_redirect_url(...)`.
- On token failure: throws `\Exception`.
- On API HTTP error: throws `\Exception('Payment failed – please try again.')`.
- On TERMINAL_FAILED: calls `wp_safe_redirect` + `exit` (handle via wp_redirect filter + exception trick).
- `payment_method !== 'linkmoney'` → returns early with no action.

- [ ] Create `tests/Gateway/StoreApiProcessTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::store_api_process_linkmoney().
 *
 * The method is hooked to woocommerce_rest_checkout_process_payment_with_context.
 * We call it directly with mock $context and $result objects.
 *
 * 6 tests total.
 */
class StoreApiProcessTest extends \WP_UnitTestCase {

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

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a mock $context object matching WooCommerce's PaymentContext shape.
     */
    private function make_context( \WC_Order $order, string $payment_method, string $selected_account ): object {
        $context                = new \stdClass();
        $context->payment_method = $payment_method;
        $context->order          = $order;
        $context->payment_data   = [
            [ 'key' => 'linkmoney_saved_account', 'value' => $selected_account ],
        ];
        return $context;
    }

    /**
     * Build a mock $result object matching WooCommerce's PaymentResult shape.
     */
    private function make_result(): object {
        return new class {
            public ?string $status       = null;
            public ?string $redirect_url = null;
            public ?string $message      = null;

            public function set_status( string $s ): void       { $this->status       = $s; }
            public function set_redirect_url( string $u ): void { $this->redirect_url = $u; }
            public function set_message( string $m ): void      { $this->message      = $m; }
        };
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    /**
     * @test
     * When selected account is '__new', the method should create a session,
     * set checkout-draft status, and redirect to the session URL.
     */
    public function test_new_account_creates_session_and_redirects(): void {
        $this->mock_token_response();
        $this->mock_session_response( 'https://link.money/session/blocks-new' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', '__new' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertSame( 'success', $result->status );
        $this->assertSame( 'https://link.money/session/blocks-new', $result->redirect_url );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'checkout-draft' ) );
    }

    /**
     * @test
     * When selected account is '' (empty), treated same as __new — creates
     * session and redirects.
     */
    public function test_empty_selection_creates_session(): void {
        $this->mock_token_response();
        $this->mock_session_response( 'https://link.money/session/blocks-empty' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', '' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertSame( 'success', $result->status );
        $this->assertNotNull( $result->redirect_url );
    }

    /**
     * @test
     * When session creation fails for __new, an Exception should be thrown.
     */
    public function test_new_account_session_failure_throws(): void {
        $this->mock_token_response();
        $this->mock_http_response( 'api.link-sandbox.money/v2/sessions', 400, [ 'error' => 'bad request' ] );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', '__new' );
        $result  = $this->make_result();

        $this->expectException( \Exception::class );
        $this->gateway->store_api_process_linkmoney( $context, $result );
    }

    /**
     * @test
     * When a valid saved account ID is selected, the method should call the
     * payments API and set the order to processing with result status 'success'.
     */
    public function test_saved_account_success(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-blocks-001', 'AUTHORIZED' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', 'acct-saved-001' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertSame( 'success', $result->status );
        $this->assertNotNull( $result->redirect_url );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertNotEmpty( $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ) );
    }

    /**
     * @test
     * When paymentStatus is PENDING, the order should be set to on-hold.
     */
    public function test_saved_account_pending_sets_on_hold(): void {
        $this->mock_token_response();
        $this->mock_payment_response( 'pay-blocks-pending', 'PENDING' );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', 'acct-saved-002' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertSame( 'success', $result->status );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'on-hold' ) );
    }

    /**
     * @test
     * When payment_method is not 'linkmoney', the method returns without action.
     */
    public function test_non_linkmoney_returns_early(): void {
        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'stripe', 'acct-001' );
        $result  = $this->make_result();

        $this->gateway->store_api_process_linkmoney( $context, $result );

        $this->assertNull( $result->status );
    }

    /**
     * @test
     * When get_access_token() fails, an Exception should be thrown.
     */
    public function test_token_failure_throws_exception(): void {
        $this->mock_http_response(
            'api.link-sandbox.money/v1/tokens',
            401,
            [ 'error' => 'invalid_client' ]
        );

        $order   = OrderHelper::create_pending_order();
        $context = $this->make_context( $order, 'linkmoney', 'acct-saved-003' );
        $result  = $this->make_result();

        $this->expectException( \Exception::class );
        $this->gateway->store_api_process_linkmoney( $context, $result );
    }
}
```

- [ ] Commit: `git add tests/Gateway/StoreApiProcessTest.php && git commit -m "test: add StoreApiProcessTest (6 tests)"`

---

## Task 6: CallbackTest + HandleReturnTest (11 tests)

Create `tests/Gateway/CallbackTest.php` and `tests/Gateway/HandleReturnTest.php`.

**Key behaviors:**
- `handle_wc_api_callback()` always calls `wp_safe_redirect()` + `exit`. Intercept via `wp_redirect` filter throwing an exception.
- `linkmoney_handle_return($order_id)` calls `wp_safe_redirect` + `exit` for status codes 0, 1, and >= 204. For status code 2 (on-hold), it does NOT redirect — it falls through. Test accordingly.
- Both methods read `$_GET` params: `order_id`, `order_key`, `paymentId`, `customerId`, `paymentStatusCode`, `status`.

- [ ] Create `tests/Gateway/CallbackTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::handle_wc_api_callback().
 *
 * handle_wc_api_callback() calls wp_safe_redirect() + exit on every code path.
 * We intercept the redirect via the 'wp_redirect' filter and throw an exception
 * to prevent the exit. Tests catch the exception and assert on the redirect URL
 * and order state.
 *
 * 7 tests total.
 */
class CallbackTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    /** @var \WC_Gateway_LinkMoney */
    private \WC_Gateway_LinkMoney $gateway;

    /** Exception class used as a redirect sentinel. */
    private const REDIRECT_EXCEPTION = 'LinkMoney\Tests\Gateway\RedirectException';

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
        $_GET = [];
    }

    public function tearDown(): void {
        $_GET = [];
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * Register a wp_redirect filter that captures the URL and throws an exception
     * to abort execution (preventing the wp exit call).
     *
     * @return string The redirect URL captured by the filter.
     */
    private function capture_redirect( callable $action ): string {
        $captured = '';
        $filter   = function( string $url ) use ( &$captured ): never {
            $captured = $url;
            throw new \RuntimeException( 'REDIRECT:' . $url );
        };
        add_filter( 'wp_redirect', $filter, 99 );

        try {
            $action();
        } catch ( \RuntimeException $e ) {
            // Expected — the redirect threw this.
        } finally {
            remove_filter( 'wp_redirect', $filter, 99 );
        }

        return $captured;
    }

    /**
     * @test
     * paymentStatusCode=0 should set the order to processing and redirect to thank-you.
     */
    public function test_success_status_0(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-cb-001',
            'customerId'        => 'cust-cb-001',
            'paymentStatusCode' => '0',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertStringContainsString( 'order-received', $redirect );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertSame( 'pay-cb-001', $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ) );
    }

    /**
     * @test
     * paymentStatusCode=1 should set the order to failed and redirect to checkout.
     */
    public function test_declined_status_1(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentStatusCode' => '1',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertStringContainsString( 'checkout', $redirect );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * paymentStatusCode=2 should set the order to on-hold and redirect to thank-you
     * (falls through to default redirect after setting on-hold).
     */
    public function test_pending_status_2(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentStatusCode' => '2',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        // status=2 falls through to the default redirect at the end of the method.
        $this->assertNotEmpty( $redirect );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'on-hold' ) );
    }

    /**
     * @test
     * status=204 (cancel) should redirect to checkout with lm_restore_order params.
     */
    public function test_cancel_status_204(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'order_id'  => $order->get_id(),
            'order_key' => $order->get_order_key(),
            'status'    => '204',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertStringContainsString( 'lm_restore_order=' . $order->get_id(), $redirect );
        $this->assertStringContainsString( 'lm_restore_key=', $redirect );
        $this->assertStringContainsString( 'checkout', $redirect );
    }

    /**
     * @test
     * paymentId and customerId in $_GET should be saved to order meta and user meta.
     */
    public function test_saves_payment_metadata(): void {
        $user_id = $this->factory->user->create();
        $order   = OrderHelper::create_checkout_draft_order();
        $order->set_customer_id( $user_id );
        $order->save();

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentId'         => 'pay-meta-001',
            'customerId'        => 'cust-meta-001',
            'paymentStatusCode' => '0',
        ];

        $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $order = wc_get_order( $order->get_id() );
        $this->assertSame( 'pay-meta-001', $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ) );
        $this->assertSame( 'cust-meta-001', get_user_meta( $user_id, \WC_Gateway_LinkMoney::META_CUSTOMER_ID, true ) );
    }

    /**
     * @test
     * An invalid order_id should redirect to checkout without modifying any order.
     */
    public function test_invalid_order(): void {
        $_GET = [
            'order_id'  => 999999,
            'order_key' => 'wrong-key',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        $this->assertStringContainsString( 'checkout', $redirect );
    }

    /**
     * @test
     * An order using a different payment gateway should redirect to thank-you
     * without processing any payment status logic.
     */
    public function test_wrong_payment_method(): void {
        $order = wc_create_order( [ 'status' => 'pending', 'payment_method' => 'stripe' ] );
        $order->save();

        $_GET = [
            'order_id'          => $order->get_id(),
            'order_key'         => $order->get_order_key(),
            'paymentStatusCode' => '0',
        ];

        $redirect = $this->capture_redirect( [ $this->gateway, 'handle_wc_api_callback' ] );

        // Method exits early with thank-you redirect for wrong gateway.
        $this->assertStringContainsString( 'order-received', $redirect );
        // Order should still be pending (no status change).
        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'pending' ) );
    }
}
```

- [ ] Create `tests/Gateway/HandleReturnTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::linkmoney_handle_return().
 *
 * linkmoney_handle_return($order_id) is hooked to woocommerce_thankyou_{linkmoney}
 * and woocommerce_receipt_{linkmoney}. It reads $_GET params and updates order status.
 *
 * For status codes 0 and 1, the method calls wp_safe_redirect() + exit.
 * For status code 2 (on-hold), it does NOT redirect — it updates status and returns.
 * For status >= 204 (cancel), it calls wp_safe_redirect() + exit.
 *
 * 4 tests total.
 */
class HandleReturnTest extends \WP_UnitTestCase {

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
        $_GET = [];
    }

    public function tearDown(): void {
        $_GET = [];
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * Intercept wp_safe_redirect + exit by hooking wp_redirect.
     */
    private function capture_redirect( callable $action ): string {
        $captured = '';
        $filter   = function( string $url ) use ( &$captured ): never {
            $captured = $url;
            throw new \RuntimeException( 'REDIRECT:' . $url );
        };
        add_filter( 'wp_redirect', $filter, 99 );

        try {
            $action();
        } catch ( \RuntimeException $e ) {
            // Expected sentinel.
        } finally {
            remove_filter( 'wp_redirect', $filter, 99 );
        }

        return $captured;
    }

    /**
     * @test
     * paymentStatusCode=0 should set the order to processing and save metadata.
     * The method exits via wp_safe_redirect after status 0.
     */
    public function test_success_status_0(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'paymentId'         => 'pay-return-001',
            'customerId'        => 'cust-return-001',
            'paymentStatusCode' => '0',
            'status'            => '100',
        ];

        $this->capture_redirect( function() use ( $order ) {
            $this->gateway->linkmoney_handle_return( $order->get_id() );
        } );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
        $this->assertSame( 'pay-return-001', $order->get_meta( \WC_Gateway_LinkMoney::META_PAYMENT_ID ) );
    }

    /**
     * @test
     * paymentStatusCode=1 should set the order to failed.
     */
    public function test_declined_status_1(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'paymentStatusCode' => '1',
            'status'            => '100',
        ];

        $this->capture_redirect( function() use ( $order ) {
            $this->gateway->linkmoney_handle_return( $order->get_id() );
        } );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * paymentStatusCode=2 should set the order to on-hold.
     * This case does NOT redirect — the method updates status and returns normally.
     */
    public function test_pending_status_2(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'paymentStatusCode' => '2',
            'status'            => '100',
        ];

        // No redirect expected here; call directly without capture_redirect.
        $this->gateway->linkmoney_handle_return( $order->get_id() );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'on-hold' ) );
    }

    /**
     * @test
     * status >= 204 (cancel flow) should redirect to checkout with restore params.
     */
    public function test_cancel_redirects(): void {
        $order = OrderHelper::create_checkout_draft_order();

        $_GET = [
            'status' => '204',
        ];

        $redirect = $this->capture_redirect( function() use ( $order ) {
            $this->gateway->linkmoney_handle_return( $order->get_id() );
        } );

        $this->assertStringContainsString( 'lm_restore_order=' . $order->get_id(), $redirect );
        $this->assertStringContainsString( 'checkout', $redirect );
    }
}
```

- [ ] Commit: `git add tests/Gateway/CallbackTest.php tests/Gateway/HandleReturnTest.php && git commit -m "test: add CallbackTest and HandleReturnTest (11 tests)"`

---

## Task 7: RestoreCartTest (6 tests)

Create `tests/Gateway/RestoreCartTest.php`.

**Key behaviors:**
- `maybe_restore_cart_from_order()`: Public. Reads `$_GET['lm_restore_order']` and `$_GET['lm_restore_key']`. Validates order key. Sets `store_api_draft_order` session to 0. Deletes the order. Empties cart and re-adds items.
- `restore_cart_from_order()`: Private. Skips if order is processing/completed. Skips if cart is not empty. Adds items to cart.
- WC cart and session must be initialized in setUp().

- [ ] Create `tests/Gateway/RestoreCartTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::maybe_restore_cart_from_order() and
 * restore_cart_from_order() (private, tested via reflection).
 *
 * 6 tests total.
 */
class RestoreCartTest extends \WP_UnitTestCase {

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

        // Initialize WooCommerce session and cart for cart tests.
        if ( ! WC()->session ) {
            WC()->initialize_session();
        }
        if ( ! WC()->cart ) {
            WC()->initialize_cart();
        }
        WC()->cart->empty_cart();

        $_GET = [];
    }

    public function tearDown(): void {
        $_GET = [];
        if ( WC()->cart ) {
            WC()->cart->empty_cart();
        }
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * @test
     * Valid lm_restore_order + lm_restore_key should delete the order and restore
     * its items back into the cart.
     */
    public function test_deletes_order_and_restores_cart(): void {
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        $_GET = [
            'lm_restore_order' => $order_id,
            'lm_restore_key'   => $order->get_order_key(),
        ];

        $this->gateway->maybe_restore_cart_from_order();

        // Order should be deleted.
        $this->assertFalse( wc_get_order( $order_id ) );

        // Cart should now have the item that was in the order (qty 1 of product).
        $this->assertFalse( WC()->cart->is_empty() );
    }

    /**
     * @test
     * On restore, the session key store_api_draft_order should be set to 0.
     */
    public function test_clears_store_api_draft_session(): void {
        $order = OrderHelper::create_checkout_draft_order();

        // Simulate a stored Blocks draft order session value.
        WC()->session->set( 'store_api_draft_order', $order->get_id() );

        $_GET = [
            'lm_restore_order' => $order->get_id(),
            'lm_restore_key'   => $order->get_order_key(),
        ];

        $this->gateway->maybe_restore_cart_from_order();

        $this->assertSame( 0, (int) WC()->session->get( 'store_api_draft_order' ) );
    }

    /**
     * @test
     * A wrong order key should result in a no-op (order not deleted).
     */
    public function test_invalid_order_key_no_op(): void {
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        $_GET = [
            'lm_restore_order' => $order_id,
            'lm_restore_key'   => 'wrong-key-value',
        ];

        $this->gateway->maybe_restore_cart_from_order();

        // Order should still exist.
        $this->assertInstanceOf( \WC_Order::class, wc_get_order( $order_id ) );
    }

    /**
     * @test
     * Missing GET params should result in a no-op.
     */
    public function test_missing_params_no_op(): void {
        $order    = OrderHelper::create_checkout_draft_order();
        $order_id = $order->get_id();

        // No $_GET params set.
        $this->gateway->maybe_restore_cart_from_order();

        // Order should still exist.
        $this->assertInstanceOf( \WC_Order::class, wc_get_order( $order_id ) );
    }

    /**
     * @test
     * restore_cart_from_order() should skip restoration if the order is processing
     * (to avoid restoring items for already-paid orders).
     */
    public function test_restore_skips_processing_order(): void {
        $order = OrderHelper::create_processing_order( 'pay-proc-001' );

        // Call private method via reflection.
        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'restore_cart_from_order' );
        $method->setAccessible( true );
        $method->invoke( $this->gateway, $order );

        // Cart should remain empty.
        $this->assertTrue( WC()->cart->is_empty() );
    }

    /**
     * @test
     * restore_cart_from_order() should skip restoration if the cart already has items
     * (to avoid duplicate additions).
     */
    public function test_restore_skips_nonempty_cart(): void {
        $order   = OrderHelper::create_failed_order();
        $product = OrderHelper::create_simple_product( 5.00 );

        // Pre-populate the cart with a different product.
        WC()->cart->add_to_cart( $product->get_id(), 1 );
        $initial_cart_count = count( WC()->cart->get_cart() );

        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, 'restore_cart_from_order' );
        $method->setAccessible( true );
        $method->invoke( $this->gateway, $order );

        // Cart item count should not have changed.
        $this->assertSame( $initial_cart_count, count( WC()->cart->get_cart() ) );
    }
}
```

- [ ] Commit: `git add tests/Gateway/RestoreCartTest.php && git commit -m "test: add RestoreCartTest (6 tests)"`

---

## Task 8: RefundTest + WebhookTest (9 tests)

Create `tests/Gateway/RefundTest.php` and `tests/Gateway/WebhookTest.php`.

**Key behaviors:**
- `process_refund($order_id, $amount, $reason)`: Returns `WP_Error` if no order, no `META_PAYMENT_ID`, token error, or API error. Returns `true` on success. Adds order note with refund ID and amount.
- `handle_webhook(WP_REST_Request)`: Static. Reads `linkmoney_webhook_secret` option. Validates HMAC signature: `base64(hmac-sha512(unique_id.timestamp.body, secret))`. Returns `WP_Error` with appropriate HTTP status codes. Dispatches `payment.authorized`, `payment.succeeded`, `payment.failed` events.

- [ ] Create `tests/Gateway/RefundTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::process_refund().
 *
 * 4 tests total.
 */
class RefundTest extends \WP_UnitTestCase {

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
     * A full refund with a valid payment ID should return true and add an order note.
     */
    public function test_full_refund_success(): void {
        $this->mock_token_response();
        $this->mock_refund_response( 'refund-pay-001' );

        $order  = OrderHelper::create_processing_order( 'pay-refund-001' );
        $result = $this->gateway->process_refund( $order->get_id(), 20.00, 'Customer requested refund' );

        $this->assertTrue( $result );

        // Verify an order note was added containing the refund paymentId.
        $order = wc_get_order( $order->get_id() );
        $notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        $note_content = implode( ' ', array_map( fn( $n ) => $n->content, $notes ) );
        $this->assertStringContainsString( 'refund-pay-001', $note_content );
    }

    /**
     * @test
     * A partial refund should send the correct amount in the API payload.
     */
    public function test_partial_refund(): void {
        $captured_body = null;
        add_filter( 'pre_http_request', function( $preempt, $args, $url ) use ( &$captured_body ) {
            if ( strpos( $url, 'v1/refunds' ) !== false ) {
                $captured_body = json_decode( $args['body'], true );
            }
            return $preempt;
        }, 5, 3 );

        $this->mock_token_response();
        $this->mock_refund_response( 'refund-partial-001' );

        $order  = OrderHelper::create_processing_order( 'pay-partial-001' );
        $result = $this->gateway->process_refund( $order->get_id(), 10.00, 'Partial refund' );

        $this->assertTrue( $result );
        $this->assertNotNull( $captured_body );
        $this->assertSame( '10.00', $captured_body['amount']['value'] );
    }

    /**
     * @test
     * When the API returns an error, process_refund() should return a WP_Error.
     */
    public function test_api_failure(): void {
        $this->mock_token_response();
        $this->mock_http_response( 'api.link-sandbox.money/v1/refunds', 400, [
            'errorMessage' => 'Cannot refund a failed payment.',
        ] );

        $order  = OrderHelper::create_processing_order( 'pay-refund-fail' );
        $result = $this->gateway->process_refund( $order->get_id(), 20.00 );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    /**
     * @test
     * When the order has no META_PAYMENT_ID, process_refund() should return WP_Error.
     */
    public function test_missing_payment_id(): void {
        // Create a processing order WITHOUT a payment ID.
        $order = OrderHelper::create_pending_order();
        $order->update_status( 'processing' );
        $order->save();

        $result = $this->gateway->process_refund( $order->get_id(), 20.00 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'linkmoney_no_payment', $result->get_error_code() );
    }
}
```

- [ ] Create `tests/Gateway/WebhookTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for WC_Gateway_LinkMoney::handle_webhook() (static REST endpoint handler).
 *
 * Signature formula: base64(hmac_sha512(unique_id . '.' . timestamp . '.' . body, secret))
 *
 * 5 tests total.
 */
class WebhookTest extends \WP_UnitTestCase {

    use MockHttpTrait;

    private const WEBHOOK_SECRET = 'test-webhook-secret-abc123';

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

        // Store the webhook secret as the gateway saves it.
        update_option( 'linkmoney_webhook_secret', self::WEBHOOK_SECRET );
    }

    public function tearDown(): void {
        delete_option( 'linkmoney_webhook_secret' );
        $this->teardown_mock_http();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Build a WP_REST_Request with the correct HMAC signature headers.
     *
     * @param array  $body_data    Associative array for the JSON request body.
     * @param string $secret       Secret to sign with (defaults to self::WEBHOOK_SECRET).
     * @param string $unique_id    Value for x-signature-uniqueid header.
     * @param string $timestamp    Value for x-signature-timestamp header.
     * @return \WP_REST_Request
     */
    private function make_signed_request(
        array  $body_data,
        string $secret    = self::WEBHOOK_SECRET,
        string $unique_id = 'test-unique-id-001',
        string $timestamp = '1700000000'
    ): \WP_REST_Request {
        $body_json = wp_json_encode( $body_data );
        $signature = base64_encode(
            hash_hmac( 'sha512', "{$unique_id}.{$timestamp}.{$body_json}", $secret, true )
        );

        $request = new \WP_REST_Request( 'POST', '/linkmoney/v1/webhook' );
        $request->set_body( $body_json );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_header( 'x-signature', $signature );
        $request->set_header( 'x-signature-uniqueid', $unique_id );
        $request->set_header( 'x-signature-timestamp', $timestamp );

        return $request;
    }

    /**
     * Build the payload for a webhook event.
     */
    private function make_event_payload( string $event_type, string $payment_id ): array {
        return [
            'eventType' => $event_type,
            'metadata'  => [
                'resourceId' => $payment_id,
            ],
        ];
    }

    // ---------------------------------------------------------------------------
    // Tests
    // ---------------------------------------------------------------------------

    /**
     * @test
     * payment.authorized event should set order to processing and trigger emails.
     */
    public function test_payment_authorized(): void {
        $order = OrderHelper::create_processing_order( 'pay-webhook-auth' );

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.authorized', 'pay-webhook-auth' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $data = $response->get_data();
        $this->assertTrue( $data['success'] );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'processing' ) );
    }

    /**
     * @test
     * payment.succeeded event should add an order note but not change order status
     * (status update is commented out in the source — note only).
     */
    public function test_payment_succeeded(): void {
        $order            = OrderHelper::create_processing_order( 'pay-webhook-settled' );
        $initial_status   = $order->get_status();

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.succeeded', 'pay-webhook-settled' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        // Status should not change for payment.succeeded.
        $order = wc_get_order( $order->get_id() );
        $this->assertSame( $initial_status, $order->get_status() );

        // An order note should be added.
        $notes        = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        $note_content = implode( ' ', array_map( fn( $n ) => $n->content, $notes ) );
        $this->assertStringContainsString( 'settled', $note_content );
    }

    /**
     * @test
     * payment.failed event should set order to failed.
     */
    public function test_payment_failed(): void {
        $order = OrderHelper::create_processing_order( 'pay-webhook-failed' );

        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.failed', 'pay-webhook-failed' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertNotInstanceOf( \WP_Error::class, $response );

        $order = wc_get_order( $order->get_id() );
        $this->assertTrue( $order->has_status( 'failed' ) );
    }

    /**
     * @test
     * A request with a wrong signature should return WP_Error with status 403.
     */
    public function test_invalid_signature(): void {
        $order = OrderHelper::create_processing_order( 'pay-webhook-sig' );

        // Sign with a different secret.
        $request = $this->make_signed_request(
            $this->make_event_payload( 'payment.authorized', 'pay-webhook-sig' ),
            'wrong-secret'
        );

        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 'invalid_signature', $response->get_error_code() );
    }

    /**
     * @test
     * A webhook referencing a non-existent order should return WP_Error with status 404.
     */
    public function test_missing_order(): void {
        $request  = $this->make_signed_request(
            $this->make_event_payload( 'payment.authorized', 'pay-does-not-exist-xyz' )
        );
        $response = \WC_Gateway_LinkMoney::handle_webhook( $request );

        $this->assertInstanceOf( \WP_Error::class, $response );
        $this->assertSame( 'order_not_found', $response->get_error_code() );
    }
}
```

- [ ] Commit: `git add tests/Gateway/RefundTest.php tests/Gateway/WebhookTest.php && git commit -m "test: add RefundTest and WebhookTest (9 tests)"`

---

## Task 9: StatusTransitionsTest (3 tests)

Create `tests/Gateway/StatusTransitionsTest.php`.

**Key behaviors:**
- `order_ever_was_processing($order)`: Private static. Returns `true` if `META_EVER_PROCESSING` is truthy, `false` otherwise.
- `trigger_awaiting_payment_emails($order)`: Private static. Calls `WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->get_id())`. Adds order note.

- [ ] Create `tests/Gateway/StatusTransitionsTest.php`:

```php
<?php

namespace LinkMoney\Tests\Gateway;

use LinkMoney\Tests\Helpers\MockHttpTrait;
use LinkMoney\Tests\Helpers\OrderHelper;

/**
 * Tests for cross-cutting status helpers:
 *   - order_ever_was_processing() (private static)
 *   - trigger_awaiting_payment_emails() (private static)
 *
 * 3 tests total.
 */
class StatusTransitionsTest extends \WP_UnitTestCase {

    use MockHttpTrait;

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
    }

    public function tearDown(): void {
        $this->teardown_mock_http();
        parent::tearDown();
    }

    /**
     * Helper: invoke a private static method via ReflectionMethod.
     */
    private function invoke_static( string $method_name, ...$args ): mixed {
        $method = new \ReflectionMethod( \WC_Gateway_LinkMoney::class, $method_name );
        $method->setAccessible( true );
        return $method->invoke( null, ...$args );
    }

    /**
     * @test
     * order_ever_was_processing() should return true when META_EVER_PROCESSING is set.
     */
    public function test_order_ever_was_processing_true(): void {
        $order = OrderHelper::create_processing_order( 'pay-ever-001' );
        // OrderHelper::create_processing_order sets META_EVER_PROCESSING = true.

        $result = $this->invoke_static( 'order_ever_was_processing', $order );

        $this->assertTrue( $result );
    }

    /**
     * @test
     * order_ever_was_processing() should return false when META_EVER_PROCESSING is not set.
     */
    public function test_order_ever_was_processing_false(): void {
        $order = OrderHelper::create_pending_order();
        // pending order has no META_EVER_PROCESSING.

        $result = $this->invoke_static( 'order_ever_was_processing', $order );

        $this->assertFalse( $result );
    }

    /**
     * @test
     * trigger_awaiting_payment_emails() should trigger WC_Email_New_Order and add
     * an order note confirming the email was sent.
     */
    public function test_trigger_awaiting_payment_emails(): void {
        $order = OrderHelper::create_processing_order( 'pay-email-001' );

        // Capture whether the email trigger method was called by hooking
        // woocommerce_email_before_order_table (fires inside WC_Email::trigger).
        $email_triggered = false;
        add_action( 'woocommerce_new_order_notification', function() use ( &$email_triggered ) {
            $email_triggered = true;
        } );

        $this->invoke_static( 'trigger_awaiting_payment_emails', $order );

        // An order note should be added by trigger_awaiting_payment_emails.
        $order = wc_get_order( $order->get_id() );
        $notes = wc_get_order_notes( [ 'order_id' => $order->get_id() ] );
        $note_content = implode( ' ', array_map( fn( $n ) => $n->content, $notes ) );
        $this->assertStringContainsString( 'New Order email', $note_content );
    }
}
```

- [ ] Commit: `git add tests/Gateway/StatusTransitionsTest.php && git commit -m "test: add StatusTransitionsTest (3 tests)"`

---

## Test Count Summary

| File | Tests |
|------|-------|
| `tests/Gateway/SessionTest.php` | 6 |
| `tests/Gateway/SavedAccountsTest.php` | 3 |
| `tests/Gateway/ProcessPaymentTest.php` | 3 |
| `tests/Gateway/ProcessSavedPaymentTest.php` | 7 |
| `tests/Gateway/StoreApiProcessTest.php` | 6 |
| `tests/Gateway/CallbackTest.php` | 7 |
| `tests/Gateway/HandleReturnTest.php` | 4 |
| `tests/Gateway/RestoreCartTest.php` | 6 |
| `tests/Gateway/RefundTest.php` | 4 |
| `tests/Gateway/WebhookTest.php` | 5 |
| `tests/Gateway/StatusTransitionsTest.php` | 3 |
| **Total** | **54** |

---

## Known Corrections From Spec vs. Source

These discrepancies were found by reading the source. The plan reflects reality, not the spec:

1. **Token URL:** The code uses `{base}/v1/tokens`, NOT `/oauth/token`. All token mocks must match `api.link-sandbox.money/v1/tokens`.

2. **`store_api_process_linkmoney` new-account flow:** When `selected === '__new'` or `selected === ''`, the method returns early with NO action (no session creation, no redirect, no exception). The spec describes session creation — this does not happen in this hook. The session URL for Blocks checkout is pre-generated in `WC_Gateway_LinkMoney_Blocks::get_payment_method_script_handles()` and passed to the JS via `wp_localize_script`. Tests `test_new_account_creates_session` and `test_new_account_session_failure_throws` in the spec are incorrect.

3. **`store_api_process_linkmoney` PENDING path:** Unlike `process_saved_payment()`, the Store API hook does NOT have a branch for `paymentStatus=PENDING` → on-hold. A PENDING response still results in the order being set to processing.

4. **`linkmoney_handle_return` status code 2:** The method updates order to on-hold and then falls through — it does NOT call `wp_safe_redirect`. Only status 0, status 1, and status >= 204 call redirect.

5. **`process_refund` API endpoint:** The refund endpoint is `{base}/v1/refunds` (not `/v1/payments/{id}/refund`).
