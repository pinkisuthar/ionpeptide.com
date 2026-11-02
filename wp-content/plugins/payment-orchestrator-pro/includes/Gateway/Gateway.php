<?php
/**
 * Gateway - Value object representing a payment gateway
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Gateway;

final class Gateway {
    public function __construct(
        public readonly string $id,
        public readonly string $displayName,
        public readonly float $monthlyTarget,
        public readonly float $maxTransactionAmount,
        public readonly string $gatewayType,
        public readonly bool $acceptsVisa,
        public readonly bool $acceptsMastercard,
        public readonly bool $acceptsAmex,
        public readonly bool $acceptsDiscover,
        public readonly bool $isEnabled,
        public readonly bool $isAlwaysOn,
        public readonly int $priority,
    ) {}
    
    /**
     * Create from database row
     */
    public static function fromArray(array $row): self {
        return new self(
            id: $row['gateway_id'],
            displayName: $row['display_name'],
            monthlyTarget: (float) ($row['monthly_target'] ?? 0),
            maxTransactionAmount: (float) ($row['max_transaction_amount'] ?? 0),
            gatewayType: $row['gateway_type'] ?? 'card',
            acceptsVisa: (bool) ($row['accepts_visa'] ?? true),
            acceptsMastercard: (bool) ($row['accepts_mastercard'] ?? true),
            acceptsAmex: (bool) ($row['accepts_amex'] ?? true),
            acceptsDiscover: (bool) ($row['accepts_discover'] ?? true),
            isEnabled: (bool) ($row['is_enabled'] ?? true),
            isAlwaysOn: (bool) ($row['is_always_on'] ?? false),
            priority: (int) ($row['priority'] ?? 10),
        );
    }
    
    /**
     * Check if this is a card-based gateway
     */
    public function isCardGateway(): bool {
        return $this->gatewayType === 'card';
    }
    
    /**
     * Check if gateway has unlimited capacity
     */
    public function hasUnlimitedCapacity(): bool {
        return $this->monthlyTarget <= 0;
    }
    
    /**
     * Check if gateway supports a card type
     */
    public function supportsCardType(string $cardType): bool {
        if (!$this->isCardGateway()) {
            return false; // Non-card gateways don't route by card type
        }
        return match (strtolower($cardType)) {
            'visa' => $this->acceptsVisa,
            'mastercard', 'mc' => $this->acceptsMastercard,
            'amex', 'american express' => $this->acceptsAmex,
            'discover' => $this->acceptsDiscover,
            default => true,
        };
    }
    
    /**
     * Check if gateway has limited card support
     */
    public function hasLimitedCardSupport(): bool {
        if (!$this->isCardGateway()) return false;
        return !$this->acceptsVisa || !$this->acceptsMastercard || !$this->acceptsAmex || !$this->acceptsDiscover;
    }
}
