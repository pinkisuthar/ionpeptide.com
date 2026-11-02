<?php
/**
 * GatewayRepository - Database operations for gateways
 *
 * Uses per-request in-memory caching to avoid redundant DB queries.
 * A single checkout request can trigger filterGateways() multiple times;
 * without caching, each call ran 3-5 separate queries.
 *
 * Cache is automatically invalidated on any write operation (add/update/delete).
 * Cache is instance-scoped and only lives for the current PHP request.
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Gateway;

use PaymentOrchestrator\Core\Logger;

final class GatewayRepository {
    private const TABLE = 'po_gateways';
    
    /**
     * Per-request cache of ALL gateway rows from the DB.
     * null = not yet loaded, array = loaded (even if empty).
     * Every read method filters from this single cached dataset.
     *
     * @var Gateway[]|null
     */
    private ?array $allCache = null;
    
    /**
     * Load all gateways from DB into cache (once per request).
     * All read methods delegate to this to avoid multiple queries.
     *
     * @return Gateway[]
     */
    private function loadAll(): array {
        if ($this->allCache !== null) {
            return $this->allCache;
        }
        
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        
        $rows = $wpdb->get_results(
            "SELECT * FROM {$table} ORDER BY priority ASC, display_name ASC",
            ARRAY_A
        );
        
        $this->allCache = array_map(fn($row) => Gateway::fromArray($row), $rows ?: []);
        
        return $this->allCache;
    }
    
    /**
     * Invalidate the in-memory cache.
     * Called automatically after any write operation.
     */
    private function invalidateCache(): void {
        $this->allCache = null;
    }
    
    /**
     * Get all gateways
     *
     * @return Gateway[]
     */
    public function getAll(): array {
        return $this->loadAll();
    }
    
    /**
     * Get enabled gateways for checkout
     *
     * @return Gateway[]
     */
    public function getEnabled(): array {
        return array_values(array_filter(
            $this->loadAll(),
            fn(Gateway $g) => $g->isEnabled
        ));
    }
    
    /**
     * Get managed gateways (enabled AND not always-on)
     *
     * @return Gateway[]
     */
    public function getManaged(): array {
        return array_values(array_filter(
            $this->loadAll(),
            fn(Gateway $g) => $g->isEnabled && !$g->isAlwaysOn
        ));
    }
    
    /**
     * Get always-on gateways
     *
     * @return Gateway[]
     */
    public function getAlwaysOn(): array {
        return array_values(array_filter(
            $this->loadAll(),
            fn(Gateway $g) => $g->isEnabled && $g->isAlwaysOn
        ));
    }
    
    /**
     * Get gateway by ID
     */
    public function getById(string $gatewayId): ?Gateway {
        foreach ($this->loadAll() as $gateway) {
            if ($gateway->id === $gatewayId) {
                return $gateway;
            }
        }
        
        return null;
    }
    
    /**
     * Check if gateway is managed (enabled in our system)
     */
    public function isManaged(string $gatewayId): bool {
        foreach ($this->loadAll() as $gateway) {
            if ($gateway->id === $gatewayId && $gateway->isEnabled) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Add a new gateway
     */
    public function add(array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        
        $result = $wpdb->insert($table, [
            'gateway_id' => $data['gateway_id'],
            'display_name' => $data['display_name'],
            'monthly_target' => $data['monthly_target'] ?? 0,
            'max_transaction_amount' => $data['max_transaction_amount'] ?? 0,
            'gateway_type' => $data['gateway_type'] ?? 'card',
            'accepts_visa' => $data['accepts_visa'] ?? 1,
            'accepts_mastercard' => $data['accepts_mastercard'] ?? 1,
            'accepts_amex' => $data['accepts_amex'] ?? 1,
            'accepts_discover' => $data['accepts_discover'] ?? 1,
            'is_enabled' => $data['is_enabled'] ?? 1,
            'is_always_on' => $data['is_always_on'] ?? 0,
            'priority' => $data['priority'] ?? 10,
        ]);
        
        if ($result) {
            $this->invalidateCache();
            Logger::info('Gateway added', ['gateway_id' => $data['gateway_id']]);
        }
        
        return (bool) $result;
    }
    
    /**
     * Update a gateway
     */
    public function update(string $gatewayId, array $data): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        
        $allowed = ['display_name', 'monthly_target', 'max_transaction_amount', 'gateway_type', 'accepts_visa', 'accepts_mastercard', 'accepts_amex', 'accepts_discover', 'is_enabled', 'is_always_on', 'priority'];
        $data = array_intersect_key($data, array_flip($allowed));
        
        if (empty($data)) {
            return false;
        }
        
        $result = $wpdb->update(
            $table,
            $data,
            ['gateway_id' => $gatewayId]
        );
        
        if ($result !== false) {
            $this->invalidateCache();
            Logger::info('Gateway updated', ['gateway_id' => $gatewayId, 'changes' => array_keys($data)]);
        }
        
        return $result !== false;
    }
    
    /**
     * Delete a gateway
     */
    public function delete(string $gatewayId): bool {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;
        
        $result = $wpdb->delete($table, ['gateway_id' => $gatewayId]);
        
        if ($result) {
            $this->invalidateCache();
            Logger::info('Gateway deleted', ['gateway_id' => $gatewayId]);
        }
        
        return (bool) $result;
    }
    
    /**
     * Get gateways that accept Amex
     *
     * @return Gateway[]
     */
    public function getAmexCapable(): array {
        return array_values(array_filter(
            $this->loadAll(),
            fn(Gateway $g) => $g->isEnabled && $g->acceptsAmex
        ));
    }
    
    /**
     * Get gateways that accept Discover
     *
     * @return Gateway[]
     */
    public function getDiscoverCapable(): array {
        return array_values(array_filter(
            $this->loadAll(),
            fn(Gateway $g) => $g->isEnabled && $g->acceptsDiscover
        ));
    }
}
