<?php
/**
 * Standalone tests for Thix_Auto_Digital digital-order eligibility and the
 * stable delivery reference — run with:
 *   php wordpress-plugin/3thix-payments/tests/test-auto-digital.php
 *
 * No WordPress needed: the WP/WC surface the class touches is stubbed below.
 */

error_reporting(E_ALL);

// ---- Minimal WP/WC stubs -------------------------------------------------

define('ABSPATH', __DIR__ . '/');

class WC_Order {
    private $id;
    private $items;
    private $key;
    public function __construct($id, array $items, $key = 'wc_order_testkey') {
        $this->id = $id;
        $this->items = $items;
        $this->key = $key;
    }
    public function get_items($type = 'line_item') { return $this->items; }
    public function get_id() { return $this->id; }
    public function get_order_key() { return $this->key; }
}

class Fake_Product {
    private $virtual;
    private $downloadable;
    public function __construct($virtual, $downloadable) {
        $this->virtual = $virtual;
        $this->downloadable = $downloadable;
    }
    public function is_virtual() { return $this->virtual; }
    public function is_downloadable() { return $this->downloadable; }
    /** Mirrors WC_Product::needs_shipping(): only virtual products don't ship. */
    public function needs_shipping() { return !$this->virtual; }
}

class Fake_Item {
    private $product;
    public function __construct($product) { $this->product = $product; }
    public function get_product() { return $this->product; }
}

require __DIR__ . '/../includes/class-thix-auto-digital.php';

// ---- Tiny assertion helper -----------------------------------------------

$failures = 0;
function check($ok, $label) {
    global $failures;
    if ($ok) {
        echo "ok    - $label\n";
    } else {
        $failures++;
        echo "FAIL  - $label\n";
    }
}

// ---- Eligibility ------------------------------------------------------------

$virtual        = new Fake_Product(true, false);   // virtual service
$virtual_dl     = new Fake_Product(true, true);    // classic digital download
$physical       = new Fake_Product(false, false);  // plain shipped goods
$physical_dl    = new Fake_Product(false, true);   // downloadable BUT still ships (boxed game + digital manual)

check(
    Thix_Auto_Digital::is_digital_order(new WC_Order(1, [new Fake_Item($virtual_dl)])) === true,
    'all-virtual downloadable order IS digital'
);
check(
    Thix_Auto_Digital::is_digital_order(new WC_Order(2, [new Fake_Item($virtual)])) === true,
    'virtual-only (non-downloadable) order IS digital'
);
check(
    Thix_Auto_Digital::is_digital_order(new WC_Order(3, [new Fake_Item($physical)])) === false,
    'physical order is NOT digital'
);
check(
    Thix_Auto_Digital::is_digital_order(new WC_Order(4, [new Fake_Item($physical_dl)])) === false,
    'downloadable-but-shippable product is NOT digital (needs_shipping wins over downloadable)'
);
check(
    Thix_Auto_Digital::is_digital_order(new WC_Order(5, [new Fake_Item($virtual_dl), new Fake_Item($physical)])) === false,
    'mixed cart (digital + shipped) is NOT digital'
);
check(
    Thix_Auto_Digital::is_digital_order(new WC_Order(6, [new Fake_Item($virtual_dl), new Fake_Item($physical_dl)])) === false,
    'mixed cart (digital + downloadable-but-shippable) is NOT digital'
);
check(
    Thix_Auto_Digital::is_digital_order(new WC_Order(7, [])) === false,
    'empty order is NOT digital'
);
check(
    Thix_Auto_Digital::is_digital_order(new WC_Order(8, [new Fake_Item(null)])) === false,
    'order with a deleted product is NOT digital (fail closed)'
);

// ---- Delivery reference ----------------------------------------------------

// No WC_Data_Store stub defined → falls back to the order-id + order-key ref,
// which must be deterministic and unique per order.
$order = new WC_Order(42, [new Fake_Item($virtual)], 'wc_order_AbC123');
$ref1 = Thix_Auto_Digital::delivery_ref($order);
$ref2 = Thix_Auto_Digital::delivery_ref($order);
check($ref1 === 'wc-order-42-wc_order_AbC123', "fallback delivery ref is order-id + order-key (got: $ref1)");
check($ref1 === $ref2, 'delivery ref is stable across calls');
check(strlen($ref1) >= 4, 'delivery ref satisfies the 4-char server minimum');

$other = new WC_Order(43, [new Fake_Item($virtual)], 'wc_order_XyZ999');
check(Thix_Auto_Digital::delivery_ref($other) !== $ref1, 'delivery ref differs between orders');

// ---- Result ----------------------------------------------------------------

if ($failures > 0) {
    echo "\n$failures assertion(s) FAILED\n";
    exit(1);
}
echo "\nall assertions passed\n";
