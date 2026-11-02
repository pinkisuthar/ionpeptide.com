<?php
/**
 * Container - Simple dependency injection container
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Core;

use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Volume\VolumeTracker;
use PaymentOrchestrator\Health\HealthMonitor;
use PaymentOrchestrator\Routing\RotationManager;
use PaymentOrchestrator\Frontend\CheckoutHandler;
use PaymentOrchestrator\Admin\AdminController;
use PaymentOrchestrator\Reports\DailyReporter;

use PaymentOrchestrator\Recovery\FailureHandler;

final class Container {
    /** @var array<string, object> */
    private array $instances = [];
    
    /** @var array<string, callable> */
    private array $factories = [];
    
    public function __construct() {
        $this->registerFactories();
    }
    
    /**
     * Register service factories
     */
    private function registerFactories(): void {
        $this->factories[GatewayRepository::class] = fn() => new GatewayRepository();
        
        $this->factories[HealthMonitor::class] = fn() => new HealthMonitor(
            $this->get(GatewayRepository::class)
        );
        
        $this->factories[VolumeTracker::class] = fn() => new VolumeTracker(
            $this->get(GatewayRepository::class)
        );
        
        $this->factories[RotationManager::class] = fn() => new RotationManager(
            $this->get(GatewayRepository::class),
            $this->get(VolumeTracker::class),
            $this->get(HealthMonitor::class)
        );
        
        $this->factories[CheckoutHandler::class] = fn() => new CheckoutHandler(
            $this->get(GatewayRepository::class),
            $this->get(RotationManager::class),
            $this->get(VolumeTracker::class)
        );
        
        $this->factories[DailyReporter::class] = fn() => new DailyReporter(
            $this->get(GatewayRepository::class),
            $this->get(VolumeTracker::class)
        );
        
        $this->factories[FailureHandler::class] = fn() => new FailureHandler(
            $this->get(GatewayRepository::class),
            $this->get(RotationManager::class)
        );
        
        $this->factories[AdminController::class] = fn() => new AdminController($this);
        
        $this->factories[Plugin::class] = fn() => new Plugin($this);
    }
    
    /**
     * Get a service instance
     *
     * @template T
     * @param class-string<T> $class
     * @return T
     */
    public function get(string $class): object {
        if (!isset($this->instances[$class])) {
            if (!isset($this->factories[$class])) {
                throw new \RuntimeException("No factory registered for: {$class}");
            }
            $this->instances[$class] = ($this->factories[$class])();
        }
        
        return $this->instances[$class];
    }
    
    /**
     * Check if a service is registered
     */
    public function has(string $class): bool {
        return isset($this->factories[$class]);
    }
}
