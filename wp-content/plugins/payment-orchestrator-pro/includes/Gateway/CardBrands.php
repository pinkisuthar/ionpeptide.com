<?php
/**
 * Card brand catalogue and per-gateway acceptance.
 *
 * Single source of truth for "which card brands does this gateway accept?",
 * used by card-type retries and the checkout card-brand switch.
 *
 * - NMI gateways managed by PC NMI Accounts: the gateway's own accepted card
 *   types setting (PC_NMI_Guard::allowed_card_types), any brand.
 * - Other gateways: POP's per-gateway Visa / Mastercard / Amex / Discover flags.
 *
 * Brand identifiers use the NMI/WooCommerce card-type vocabulary:
 * visa, mastercard, amex, discover, diners-club, jcb, maestro.
 *
 * @package PaymentOrchestrator\Gateway
 * @since 2.7.23
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Gateway;

defined('ABSPATH') || exit;

final class CardBrands {

    /** Brand id => [label, WooCommerce core icon file]. */
    public const BRANDS = [
        'visa'        => ['Visa', 'visa.svg'],
        'mastercard'  => ['Mastercard', 'mastercard.svg'],
        'amex'        => ['American Express', 'amex.svg'],
        'discover'    => ['Discover', 'discover.svg'],
        'diners-club' => ['Diners Club', 'diners.svg'],
        'jcb'         => ['JCB', 'jcb.svg'],
        'maestro'     => ['Maestro', 'maestro.svg'],
    ];

    /** Brands POP's own gateway flags can express. */
    private const POP_FLAG_BRANDS = ['visa', 'mastercard', 'amex', 'discover'];

    /**
     * Normalise a brand string to the catalogue vocabulary ('' if unknown/empty).
     */
    public static function normalize(?string $brand): string {
        $b = strtolower(trim((string) $brand));
        $map = [
            'diners' => 'diners-club', 'dinersclub' => 'diners-club', 'diners club' => 'diners-club',
            'mc' => 'mastercard', 'master card' => 'mastercard',
            'american express' => 'amex', 'americanexpress' => 'amex',
        ];
        $b = $map[$b] ?? $b;
        return isset(self::BRANDS[$b]) ? $b : '';
    }

    public static function label(string $brand): string {
        return self::BRANDS[$brand][0] ?? ucfirst($brand);
    }

    /**
     * Brands a gateway accepts, or null when it cannot be determined.
     *
     * @return string[]|null
     */
    public static function acceptedBrands(Gateway $gateway): ?array {
        $pcList = self::pcNmiAllowed($gateway->id);
        if ($pcList !== null) {
            return $pcList;
        }
        if (!$gateway->isCardGateway()) {
            return null;
        }
        $out = [];
        foreach (self::POP_FLAG_BRANDS as $brand) {
            if ($gateway->supportsCardType($brand)) {
                $out[] = $brand;
            }
        }
        return $out;
    }

    /**
     * Does the gateway accept this brand? Unknown/empty brand => true (cannot judge).
     */
    public static function accepts(Gateway $gateway, string $brand): bool {
        $brand = self::normalize($brand);
        if ($brand === '') {
            return true;
        }
        $pcList = self::pcNmiAllowed($gateway->id);
        if ($pcList !== null) {
            return in_array($brand, $pcList, true);
        }
        // POP flags only cover four brands; other brands keep the previous behaviour.
        return $gateway->supportsCardType($brand);
    }

    /**
     * Does the gateway refuse any of the four mainstream brands?
     */
    public static function isLimited(Gateway $gateway): bool {
        foreach (self::POP_FLAG_BRANDS as $brand) {
            if (!self::accepts($gateway, $brand)) {
                return true;
            }
        }
        return false;
    }

    /**
     * PC NMI Accounts' accepted card types for an NMI gateway, or null when the
     * gateway is not an NMI gateway managed by PC NMI Accounts.
     *
     * @return string[]|null
     */
    private static function pcNmiAllowed(string $gatewayId): ?array {
        if (!class_exists('PC_NMI_Guard') || !class_exists('PC_NMI_Accounts')
            || !method_exists('PC_NMI_Accounts', 'family_ids') || !method_exists('PC_NMI_Guard', 'allowed_card_types')) {
            return null;
        }
        if (!in_array($gatewayId, (array) \PC_NMI_Accounts::family_ids(), true)) {
            return null;
        }
        $list = [];
        foreach ((array) \PC_NMI_Guard::allowed_card_types($gatewayId) as $brand) {
            $n = self::normalize((string) $brand);
            if ($n !== '') {
                $list[] = $n;
            }
        }
        return array_values(array_unique($list));
    }
}
