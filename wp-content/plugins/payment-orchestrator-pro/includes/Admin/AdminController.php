<?php
/**
 * AdminController - Handles admin pages and AJAX
 *
 * @package PaymentOrchestrator
 */

declare(strict_types=1);

namespace PaymentOrchestrator\Admin;

use PaymentOrchestrator\Core\Container;
use PaymentOrchestrator\Core\Logger;
use PaymentOrchestrator\Gateway\GatewayRepository;
use PaymentOrchestrator\Volume\VolumeTracker;
use PaymentOrchestrator\Reports\DailyReporter;
use PaymentOrchestrator\Recovery\FailureHandler;
use PaymentOrchestrator\Recovery\FailureClassifier;

final class AdminController {
    private Container $container;
    
    public function __construct(Container $container) {
        $this->container = $container;
    }
    
    /**
     * Handle CSV export early (before any output)
     */
    public function handleCsvExport(): void {
        // Check if this is a reports page export request
        if (!isset($_GET['page']) || $_GET['page'] !== 'payment-orchestrator-reports') {
            return;
        }
        
        if (!isset($_GET['export']) || $_GET['export'] !== 'csv') {
            return;
        }
        
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        try {
            $repo = $this->container->get(GatewayRepository::class);
            $gateways = $repo->getAll();
            
            $reportType = isset($_GET['report_type']) ? sanitize_text_field($_GET['report_type']) : 'today';
            $selectedGateway = isset($_GET['gateway']) ? sanitize_text_field($_GET['gateway']) : '';
            $dateFrom = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
            $dateTo = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
            
            $dates = $this->getReportDateRange($reportType, $dateFrom, $dateTo);
            $dateFrom = $dates['from'];
            $dateTo = $dates['to'];
            
            $reportData = $this->generateReportData($dateFrom, $dateTo, $selectedGateway, $gateways);
            
            $this->exportReportCsv($reportData, $dateFrom, $dateTo);
            exit;
        } catch (\Exception $e) {
            Logger::error('CSV export failed', ['error' => $e->getMessage()]);
            wp_die('Export failed: ' . esc_html($e->getMessage()));
        }
    }
    
    /**
     * Register admin menu
     */
    public function registerMenu(): void {
        add_menu_page(
            __('Payment Orchestrator', 'payment-orchestrator'),
            __('Pay Orchestrator', 'payment-orchestrator'),
            'manage_woocommerce',
            'payment-orchestrator',
            [$this, 'renderDashboard'],
            'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyOTUuMjQgMjk1LjI0Ij48ZyBmaWxsPSJibGFjayI+PHBhdGggZD0iTTI5NS4yMzksMTYxLjkwNWgtMzguMDk1di00Mi44MjRsLTc1LjIyOS0wLjUwNWwtMjkuNTMzLTEyLjIzM1Y5MC40NzZoLTkuNTI0djE1Ljg2N2wtMjkuNTMzLDEyLjIzM2wtNzUuMjI5LDAuNTA1djQyLjgyNEgwLjAwMXY1Ny4xNDNoODUuNzE0di01Ny4xNDNINDcuNjJ2LTMzLjM2N2w2NS42NzYtMC40MzhsMjkuNTYyLDEzLjA5NXYyMC43MWgtMzguMDk1djU3LjE0M2g4NS43MTR2LTU3LjE0M2gtMzguMDk1di0yMC43MWwyOS41NjItMTMuMDk1bDY1LjY3NiwwLjQzOHYzMy4zNjdoLTM4LjA5NXY1Ny4xNDNoMzguMDk1djE0LjI4NkgxNDIuODU4djIzLjgxaC0xOS4wNDdjLTEwLjUwNSwwLTE5LjA0OCw4LjU0My0xOS4wNDgsMTkuMDQ4czguNTQzLDE5LjA0OCwxOS4wNDgsMTkuMDQ4aDQ3LjYxOWMxMC41MDUsMCwxOS4wNDgtOC41NDMsMTkuMDQ4LTE5LjA0OHMtOC41NDMtMTkuMDQ4LTE5LjA0OC0xOS4wNDhoLTE5LjA0OHYtMTQuMjg2aDEwNC43NjJ2LTIzLjgxaDM4LjA5NVYxNjEuOTA1eiBNNzYuMTkxLDIwOS41MjRIOS41MjV2LTM4LjA5NWg2Ni42NjZWMjA5LjUyNHogTTE4MC45NTMsMjA5LjUyNGgtNjYuNjY3di0zOC4wOTVoNjYuNjY3VjIwOS41MjR6IE0xNDcuNjIsMTMyLjg4NmwtMjEuMjQzLTkuNDFsMjEuMjQzLTguNzk1bDIxLjI0Myw4LjhMMTQ3LjYyLDEzMi44ODZ6IE0xODAuOTUzLDI3Ni4xOWMwLDUuMjUyLTQuMjcxLDkuNTI0LTkuNTI0LDkuNTI0aC00Ny42MThjLTUuMjUyLDAtOS41MjQtNC4yNzEtOS41MjQtOS41MjRjMC01LjI1Miw0LjI3MS05LjUyNCw5LjUyNC05LjUyNGg0Ny42MTlDMTc2LjY4MiwyNjYuNjY3LDE4MC45NTMsMjcwLjkzOCwxODAuOTUzLDI3Ni4xOXogTTI4NS43MTUsMjA5LjUyNGgtNjYuNjY3di0zOC4wOTVoNjYuNjY3VjIwOS41MjR6Ii8+PHBhdGggZD0iTTE3MS40Myw5NS4yMzhoLTQ3LjYxOVY2Ny44MWMtOC45OS03LjIxOS0xNC4yODYtMTguMTQzLTE0LjI4Ni0yOS43MTRDMTA5LjUyNSwxNy4wOSwxMjYuNjE1LDAsMTQ3LjYyLDBzMzguMDk1LDE3LjA5LDM4LjA5NSwzOC4wOTVjMCwxMS41NzYtNS4yOTUsMjIuNS0xNC4yODYsMjkuNzE0TDE3MS40Myw5NS4yMzh6Ii8+PHBhdGggZD0iTTE3Ni4xOTEsNzEuNDI5aC0yMy44MVY1MS41MDVjNS41MjktMS45NzEsOS41MjQtNy4yMSw5LjUyNC0xMy40MWMwLTcuODc2LTYuNDEtMTQuMjg2LTE0LjI4Ni0xNC4yODZzLTE0LjI4Niw2LjQxLTE0LjI4NiwxNC4yODZjMCw2LjIsMy45OTUsMTEuNDM4LDkuNTI0LDEzLjQxdjE5LjkyNGgtMjMuODF2OS41MjRoNTcuMTQzVjcxLjQyOXogTTE0Ny42MiwzMy4zMzNjMi42MjQsMCw0Ljc2MiwyLjEzOCw0Ljc2Miw0Ljc2MnMtMi4xMzgsNC43NjItNC43NjIsNC43NjJzLTQuNzYyLTIuMTM4LTQuNzYyLTQuNzYyQzE0Mi44NTgsMzUuNDcxLDE0NC45OTYsMzMuMzMzLDE0Ny42MiwzMy4zMzN6Ii8+PHJlY3QgeD0iNzEuNDMiIHk9IjMzLjMzMyIgd2lkdGg9IjIzLjgxIiBoZWlnaHQ9IjkuNTI0Ii8+PHJlY3QgeD0iNzYuMzIiIHk9IjU3LjkzNCIgdHJhbnNmb3JtPSJtYXRyaXgoMC45MjM4IC0wLjM4MjggMC4zODI4IDAuOTIzOCAtMTcuMjgwNSAzOC41NDkpIiB3aWR0aD0iMjMuODA4IiBoZWlnaHQ9IjkuNTI0Ii8+PHJlY3QgeD0iMjAyLjI0OCIgeT0iNTAuNzkyIiB0cmFuc2Zvcm09Im1hdHJpeCgwLjM4MjcgLTAuOTIzOSAwLjkyMzkgMC4zODI3IDY5Ljg2NTEgMjI5Ljk1NTMpIiB3aWR0aD0iOS41MjQiIGhlaWdodD0iMjMuODExIi8+PHJlY3QgeD0iMjAwLjAwMSIgeT0iMzMuMzMzIiB3aWR0aD0iMjMuODEiIGhlaWdodD0iOS41MjQiLz48cmVjdCB4PSIxOTUuMTA2IiB5PSI4LjczNCIgdHJhbnNmb3JtPSJtYXRyaXgoMC45MjM4IC0wLjM4MjggMC4zODI4IDAuOTIzOCAxMC42MDIyIDgwLjI3NCkiIHdpZHRoPSIyMy44MDgiIGhlaWdodD0iOS41MjQiLz48cmVjdCB4PSI4My40NjciIHk9IjEuNTg3IiB0cmFuc2Zvcm09Im1hdHJpeCgwLjM4MjcgLTAuOTIzOSAwLjkyMzkgMC4zODI3IDQxLjk5OTMgODkuODQyMikiIHdpZHRoPSI5LjUyNCIgaGVpZ2h0PSIyMy44MTEiLz48L2c+PC9zdmc+Cg==',
            56
        );
        
        // Default submenu (Dashboard) - replaces the auto-generated parent submenu
        add_submenu_page(
            'payment-orchestrator',
            __('Dashboard', 'payment-orchestrator'),
            __('Dashboard', 'payment-orchestrator'),
            'manage_woocommerce',
            'payment-orchestrator',
            [$this, 'renderDashboard']
        );
        
        // Hidden submenu pages - registered with null parent so they don't show in sidebar
        // Navigation is handled by the in-page tab bar instead
        $hiddenPages = [
            ['payment-orchestrator-gateways', 'Gateways', [$this, 'renderGateways']],
            ['payment-orchestrator-reports', 'Reports', [$this, 'renderReports']],
            ['payment-orchestrator-scorecards', 'Gateway Scorecards', [$this, 'renderScorecards']],
            ['payment-orchestrator-recovery', 'Recovery', [$this, 'renderRecovery']],
            ['payment-orchestrator-settings', 'Settings', [$this, 'renderSettings']],
            ['payment-orchestrator-logs', 'Logs', [$this, 'renderLogs']],
            ['payment-orchestrator-help', 'Help', [$this, 'renderHelp']],
        ];
        
        foreach ($hiddenPages as [$slug, $title, $callback]) {
            add_submenu_page(
                'payment-orchestrator', // keep parent so WP highlights the menu
                __($title, 'payment-orchestrator'),
                __($title, 'payment-orchestrator'),
                'manage_woocommerce',
                $slug,
                $callback
            );
        }
        
        // Remove all submenu items except Dashboard from sidebar
        add_action('admin_head', function() {
            remove_submenu_page('payment-orchestrator', 'payment-orchestrator-gateways');
            remove_submenu_page('payment-orchestrator', 'payment-orchestrator-reports');
            remove_submenu_page('payment-orchestrator', 'payment-orchestrator-scorecards');
            remove_submenu_page('payment-orchestrator', 'payment-orchestrator-recovery');
            remove_submenu_page('payment-orchestrator', 'payment-orchestrator-settings');
            remove_submenu_page('payment-orchestrator', 'payment-orchestrator-logs');
            remove_submenu_page('payment-orchestrator', 'payment-orchestrator-help');
        });
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueueAssets(string $hook): void {
        if (strpos($hook, 'payment-orchestrator') === false) {
            return;
        }
        
        wp_enqueue_style(
            'po-admin',
            PO_PLUGIN_URL . 'assets/css/admin.css',
            [],
            PO_VERSION
        );

        // 2.7.35: Command Center styles and chart script (Dashboard only, when selected)
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page === 'payment-orchestrator' && \PaymentOrchestrator\Admin\Theme::current() === 'command') {
            wp_enqueue_style('po-command-center', PO_PLUGIN_URL . 'assets/css/command-center.css', ['po-admin'], PO_VERSION);
            wp_enqueue_script('po-command-center', PO_PLUGIN_URL . 'assets/js/command-center.js', [], PO_VERSION, true);
        }

        wp_enqueue_script(
            'chartjs',
            'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js',
            [],
            '4.4.1',
            true
        );
        
        wp_enqueue_script(
            'po-admin',
            PO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery', 'chartjs'],
            PO_VERSION,
            true
        );
        
        wp_localize_script('po-admin', 'poAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('po_admin_nonce'),
        ]);
    }
    
    /**
     * Add settings link to plugins page
     */
    public function addSettingsLink(array $links): array {
        $url = admin_url('admin.php?page=payment-orchestrator');
        array_unshift($links, '<a href="' . esc_url($url) . '">Settings</a>');
        return $links;
    }
    
    /**
     * Render dashboard page
     */
    public function renderDashboard(): void {
        $tracker = $this->container->get(VolumeTracker::class);
        $rotation = $this->container->get(\PaymentOrchestrator\Routing\RotationManager::class);
        $health = $this->container->get(\PaymentOrchestrator\Health\HealthMonitor::class);
        
        $stats = $tracker->getAllStats();
        $todayStats = $tracker->getTodayStatsByGateway();
        $history = $tracker->getThreeMonthHistory();
        $healthData = $health->getAllHealth();
        $rotationWeights = $rotation->getCurrentWeights();
        
        $totalToday = 0;
        $totalMonth = 0;
        $totalTarget = 0;
        $todayTransactions = 0;
        $gatewayCount = count($stats);
        
        foreach ($stats as $stat) {
            $totalToday += $stat['today_volume'];
            $totalMonth += $stat['monthly_volume'];
            $totalTarget += $stat['monthly_target'];
            $todayTransactions += $stat['today_count'];
        }
        
        // 2.7.33: Command Center layout (Settings -> Appearance)
        if (\PaymentOrchestrator\Admin\Theme::current() === 'command') {
            $gatewaysRepo  = $this->container->get(\PaymentOrchestrator\Gateway\GatewayRepository::class);
            $daysRemaining = (int) current_time('t') - (int) current_time('j');
            $cc = \PaymentOrchestrator\Admin\CommandCenterData::build(
                \PaymentOrchestrator\Admin\CommandCenterData::selectedDate(),
                $gatewaysRepo->getManaged()
            );
            include PO_PLUGIN_DIR . 'templates/admin/dashboard-command.php';
            return;
        }
        
        include PO_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }
    
    /**
     * Render gateways page
     */
    public function renderGateways(): void {
        $repo = $this->container->get(GatewayRepository::class);
        
        $this->handleGatewayActions($repo);
        
        $gateways = $repo->getAll();
        $wcGateways = $this->getWooCommerceGateways();
        
        // Get monthly volume stats for each gateway
        $tracker = $this->container->get(VolumeTracker::class);
        $gatewayStats = $tracker->getAllStats();
        
        $editGateway = null;
        if (isset($_GET['edit'])) {
            $editGateway = $repo->getById(sanitize_text_field($_GET['edit']));
        }
        
        include PO_PLUGIN_DIR . 'templates/admin/gateways.php';
    }
    
    /**
     * Render settings page
     */
    public function renderSettings(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['po_save_settings'])) {
            if (wp_verify_nonce($_POST['_wpnonce'] ?? '', 'po_settings')) {
                $this->saveSettings($_POST);
                add_settings_error('po_messages', 'po_saved', 'Settings saved.', 'success');
            }
        }
        
        $settings = $this->getSettings();
        include PO_PLUGIN_DIR . 'templates/admin/settings.php';
    }
    
    /**
     * Render logs page
     */
    public function renderLogs(): void {
        $logs = Logger::getLogs(200);
        include PO_PLUGIN_DIR . 'templates/admin/logs.php';
    }
    
    /**
     * Render help page
     */
    public function renderHelp(): void {
        include PO_PLUGIN_DIR . 'templates/admin/help.php';
    }
    
    /**
     * Render recovery page
     */
    /**
     * Render Gateway Scorecards page
     */
    public function renderScorecards(): void {
        try {
            $repo = $this->container->get(GatewayRepository::class);
            $allGateways = $repo->getAll();
            $gatewayNames = [];
            foreach ($allGateways as $gw) {
                $gatewayNames[$gw->id] = $gw->displayName;
            }
            
            // View mode: today or month
            $viewMode = isset($_GET['view']) ? sanitize_text_field($_GET['view']) : 'today';
            $selectedMonth = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : current_time('Y-m');
            
            global $wpdb;
            $txnTable = $wpdb->prefix . 'po_transactions';
            $failTable = $wpdb->prefix . 'po_failed_transactions';
            
            $txnTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$txnTable}'");
            $failTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$failTable}'");
            
            // Build date filter - use WordPress timezone for "today" to match dashboard
            if ($viewMode === 'today') {
                $wpToday = current_time('Y-m-d');
                $dateFilter = $wpdb->prepare("DATE(created_at) = %s", $wpToday);
                $failDateFilter = $wpdb->prepare("created_at >= %s AND created_at < %s", ...\PaymentOrchestrator\Core\SiteTime::dayUtcRange($wpToday)); // 2.7.25: UTC table
            } else {
                $dateFilter = $wpdb->prepare("DATE_FORMAT(created_at, '%%Y-%%m') = %s", $selectedMonth);
                $failDateFilter = $wpdb->prepare("month_year = %s", $selectedMonth);
            }
            
            $scorecards = [];
            
            foreach ($allGateways as $gw) {
                $card = [
                    'id' => $gw->id,
                    'name' => $gw->displayName,
                    'is_enabled' => $gw->isEnabled,
                    'is_always_on' => $gw->isAlwaysOn ?? false,
                    'successful' => 0,
                    'failed' => 0,
                    'total' => 0,
                    'volume' => 0.0,
                    'lost_revenue' => 0.0,
                    'success_rate' => 0.0,
                    'avg_transaction' => 0.0,
                    'recovered' => 0,
                    'recovered_revenue' => 0.0,
                    'failed_on' => 0,
                    'top_errors' => [],
                ];
                
                // Transaction stats
                if ($txnTableExists) {
                    $stats = $wpdb->get_row($wpdb->prepare(
                        "SELECT 
                            COUNT(*) as total,
                            SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                            SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                            SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as volume,
                            AVG(CASE WHEN status = 'success' THEN amount ELSE NULL END) as avg_txn
                        FROM {$txnTable}
                        WHERE gateway_id = %s AND {$dateFilter}",
                        $gw->id
                    ));
                    
                    $card['total'] = (int) ($stats->total ?? 0);
                    $card['successful'] = (int) ($stats->successful ?? 0);
                    $card['failed'] = (int) ($stats->failed ?? 0);
                    $card['volume'] = (float) ($stats->volume ?? 0);
                    $card['avg_transaction'] = round((float) ($stats->avg_txn ?? 0), 2);
                    
                    // Success rate: only count gateway-attributable failures against the rate.
                    // Customer-caused failures (insufficient funds, bad CVV, etc.) are neutral -
                    // they don't reflect gateway performance, matching HealthMonitor's logic.
                    $gatewayFailures = 0;
                    if ($failTableExists) {
                        $gatewayFailures = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(DISTINCT order_id) FROM {$failTable}
                            WHERE gateway_id = %s AND {$failDateFilter}
                            AND " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql(),
                            $gw->id
                        ));
                    }
                    $rateBase = $card['successful'] + $gatewayFailures;
                    $card['success_rate'] = $rateBase > 0 
                        ? round(($card['successful'] / $rateBase) * 100, 1) : 100.0;
                }
                
                // Failed transaction details - only gateway-attributable failures
                // Customer-caused declines (non_retryable) don't count as "failed on this gateway"
                if ($failTableExists) {
                    $failStats = $wpdb->get_row($wpdb->prepare(
                        "SELECT 
                            COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$failTable} WHERE gateway_id = %s AND {$failDateFilter} AND failure_category IN ('retryable_gateway','retryable_card_type')) d), 0) as lost_revenue,
                            COUNT(DISTINCT order_id) as fail_count
                        FROM {$failTable}
                        WHERE gateway_id = %s AND {$failDateFilter}
                        AND " . \PaymentOrchestrator\Recovery\FailureClassifier::healthFaultSql(),
                        $gw->id,
                        $gw->id
                    ));
                    
                    $card['lost_revenue'] = (float) ($failStats->lost_revenue ?? 0);
                    $card['failed_on'] = (int) ($failStats->fail_count ?? 0);
                    
                    // Recovered orders: attribute to the gateway that PROCESSED the recovery (retry_gateway)
                    $recoveredStats = $wpdb->get_row($wpdb->prepare(
                        "SELECT 
                            COUNT(*) as recovered_count,
                            COALESCE(SUM(order_total), 0) as recovered_revenue
                        FROM {$failTable}
                        WHERE retry_gateway = %s AND recovered = 1 AND {$failDateFilter}",
                        $gw->id
                    ));
                    
                    $card['recovered'] = (int) ($recoveredStats->recovered_count ?? 0);
                    $card['recovered_revenue'] = (float) ($recoveredStats->recovered_revenue ?? 0);
                    
                    // Top 3 error codes for this gateway (failures that happened ON this gateway)
                    $card['top_errors'] = $wpdb->get_results($wpdb->prepare(
                        "SELECT failure_code, failure_reason, COUNT(*) as cnt 
                        FROM {$failTable}
                        WHERE gateway_id = %s AND {$failDateFilter} AND failure_code IS NOT NULL AND failure_code != ''
                        GROUP BY failure_code, failure_reason
                        ORDER BY cnt DESC
                        LIMIT 3",
                        $gw->id
                    ), ARRAY_A) ?: [];
                }
                
                $scorecards[] = $card;
            }
            
            // Sort: most total transactions first
            usort($scorecards, fn($a, $b) => $b['total'] <=> $a['total']);
            
            // Totals: aggregate directly from tables to avoid any double-counting.
            // Transaction totals come from po_transactions (each txn recorded once).
            // Failure/recovery totals come from po_failed_transactions (each fail recorded once).
            $totals = [
                'successful' => 0,
                'failed' => 0,
                'total' => 0,
                'volume' => 0.0,
                'lost_revenue' => 0.0,
                'recovered_revenue' => 0.0,
                'recovered_count' => 0,
                'failed_count' => 0,
            ];
            
            if ($txnTableExists) {
                $txnTotals = $wpdb->get_row(
                    "SELECT 
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                        COALESCE(SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END), 0) as volume
                    FROM {$txnTable}
                    WHERE {$dateFilter}"
                );
                $totals['total'] = (int) ($txnTotals->total ?? 0);
                $totals['successful'] = (int) ($txnTotals->successful ?? 0);
                $totals['failed'] = (int) ($txnTotals->failed ?? 0);
                $totals['volume'] = (float) ($txnTotals->volume ?? 0);
            }
            
            if ($failTableExists) {
                $failTotals = $wpdb->get_row(
                    "SELECT 
                        COUNT(DISTINCT order_id) as failed_count,
                        COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$failTable} WHERE {$failDateFilter}) d), 0) as lost_revenue,
                        COUNT(DISTINCT CASE WHEN recovered = 1 THEN order_id ELSE NULL END) as recovered_count,
                        COALESCE((SELECT SUM(d.order_total) FROM (SELECT DISTINCT order_id, order_total FROM {$failTable} WHERE {$failDateFilter} AND recovered = 1) d), 0) as recovered_revenue
                    FROM {$failTable}
                    WHERE {$failDateFilter}"
                );
                $totals['lost_revenue'] = (float) ($failTotals->lost_revenue ?? 0);
                $totals['recovered_revenue'] = (float) ($failTotals->recovered_revenue ?? 0);
                $totals['recovered_count'] = (int) ($failTotals->recovered_count ?? 0);
                $totals['failed_count'] = (int) ($failTotals->failed_count ?? 0);
            }
            
            $totals['success_rate'] = $totals['total'] > 0 
                ? round(($totals['successful'] / $totals['total']) * 100, 1) : 0;
            $totals['net_lost'] = $totals['lost_revenue'] - $totals['recovered_revenue'];
            $totals['recovery_rate'] = $totals['failed_count'] > 0
                ? round(($totals['recovered_count'] / $totals['failed_count']) * 100, 1) : 0;
            
            // Month selector
            $months = [];
            for ($i = 0; $i < 6; $i++) {
                $m = date('Y-m', strtotime("-{$i} months"));
                $months[$m] = date('F Y', strtotime("-{$i} months"));
            }
            
            include PO_PLUGIN_DIR . 'templates/admin/scorecards.php';
            
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error"><p>Error loading scorecards: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
    
    public function renderRecovery(): void {
        try {
            $repo = $this->container->get(GatewayRepository::class);
            $gateways = $repo->getAll();
            
            $selectedMonth = isset($_GET['month']) ? sanitize_text_field($_GET['month']) : current_time('Y-m');
            
            $recoveryStats = FailureHandler::getRecoveryStats($selectedMonth);
            $topFailureCodes = FailureHandler::getTopFailureCodes($selectedMonth, 15);
            $gatewayPairs = FailureHandler::getRecoveryByGatewayPair($selectedMonth);
            $classifierRules = $this->getClassifierRules();
            
            // Today-scoped data
            $todayRecoveryStats = FailureHandler::getRecoveryStatsToday();
            $todayGatewayPairs = FailureHandler::getRecoveryByGatewayPairToday();
            $todayTopFailureCodes = FailureHandler::getTopFailureCodesToday(15);
            
            $recoveryEnabled = get_option('po_recovery_enabled', 'yes') === 'yes';
            
            include PO_PLUGIN_DIR . 'templates/admin/recovery.php';
        } catch (\Exception $e) {
            Logger::error('Recovery page error', [
                'error' => $e->getMessage(),
            ]);
            echo '<div class="notice notice-error"><p>Error loading recovery page: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
    
    /**
     * Get classifier rules for display in admin UI
     */
    private function getClassifierRules(): array {
        $highRiskMode = get_option('po_high_risk_retry_mode', 'off');
        // Legacy toggle support
        if ($highRiskMode === 'off' && get_option('po_high_risk_retry_enabled', 'no') === 'yes') {
            $highRiskMode = 'auto';
        }
        $isHighRisk = in_array($highRiskMode, ['auto', 'specific'], true);
        
        $retryGatewayCodes = [
            '400' => 'General error',
            '410' => 'Invalid merchant ID',
            '420' => 'Response Timeout',
            '440 (Re-enter)' => 'Re-enter transaction',
            '440 (Amount)' => 'Invalid amount',
            '300 (Token)' => 'Payment Token does not exist',
            '300 (Duplicate)' => 'Duplicate transaction',
            '300 (Processing)' => 'Error Processing Transaction',
            '300 (Auth)' => 'Authentication Failed',
            'cURL error' => 'Network timeout / connection failure',
            'Gateway response' => 'Unrecognized gateway response',
        ];
        
        $nonRetryableCodes = [
            '202' => 'Insufficient funds',
            '203' => 'Exceeds withdrawal limit / Activity limit',
            '204' => 'Blocked / Not permitted / Issuer declined',
            '220' => 'Invalid card number',
            '221' => 'No such issuer',
            '222' => 'Account Closed',
            '223' => 'Expired card',
            '225' => 'CVV mismatch / Invalid CVV',
            '240' => 'Declined ' . "\u{2014}" . ' Contact card issuer',
            '250' => 'Pick up card (no fraud)',
            '251' => 'Pick up card (lost)',
            '252' => 'Pick up card (stolen)',
            '261' => 'Customer stopped recurring',
            '300 (AVS)' => 'AVS Rejected (address mismatch)',
            '300 (Invalid CC)' => 'Invalid Credit Card Number',
            '440 (Security)' => 'Security violation',
            '441' => 'Invalid transaction',
            'Incomplete' => 'Credit card details incomplete',
        ];
        
        // High-risk mode moves 201 and 253 to retryable
        if ($isHighRisk) {
            $retryLabel = $highRiskMode === 'specific' 
                ? 'High-risk retry ' . "\u{2192}" . ' specific gateway' 
                : 'High-risk retry ' . "\u{2192}" . ' any gateway';
            $retryGatewayCodes['201'] = 'Do Not Honor (' . $retryLabel . ')';
            $retryGatewayCodes['253'] = 'Pick up card ' . "\u{2014}" . ' suspected fraud (' . $retryLabel . ')';
        } else {
            $nonRetryableCodes['201'] = 'Do Not Honor';
            $nonRetryableCodes['253'] = 'Pick up card (suspected fraud)';
        }
        
        // -- G-Payments (GP) codes --
        $gpRetryableCodes = [
            'processing_error' => 'Processing error',
            'service_not_available' => 'Service not available',
            'service_issue' => 'Service issue (HTTP 500)',
            'no_action_taken' => 'No action taken',
            'reenter_transaction' => 'Re-enter transaction',
            'timeout' => 'Gateway timeout',
            'card_network_issue' => 'Card network issue',
            'duplicate_transaction' => 'Duplicate transaction',
            'resource_locked' => 'Resource locked',
            'bank_auth_required' => 'Bank auth required',
            'action_not_supported' => 'Action not supported',
        ];
        
        $gpNonRetryableCodes = [
            'insufficient_funds' => 'Insufficient funds',
            'contact_issuer' => 'Contact issuer',
            'contact_acquirer' => 'Contact acquirer',
            'invalid_cvv' => 'Invalid CVV',
            'expired_card' => 'Expired card',
            'invalid_expiry' => 'Invalid expiry date',
            'invalid_number' => 'Invalid card number',
            'invalid_card' => 'Invalid card',
            'lost_card' => 'Lost card',
            'stolen_card' => 'Stolen card',
            'restricted_card' => 'Restricted card',
            'suspected_fraud' => 'Suspected fraud',
            'security_violation' => 'Security violation',
            'fraud_block' => 'Fraud block',
            'invalid_address' => 'Invalid address',
            'invalid_amount' => 'Invalid amount',
            'amount_limit_exceeded' => 'Amount limit exceeded',
            'rate_limit_exceeded' => 'Rate limit exceeded',
            'account_not_found' => 'Account not found',
            'invalid_account' => 'Invalid account',
            'invalid_merchant' => 'Invalid merchant',
            'incorrect_pin' => 'Incorrect PIN',
            'pin_try_exceeded' => 'PIN tries exceeded',
            'pin_error' => 'PIN error',
            'action_not_allowed' => 'Action not allowed',
            'invalid_issuer' => 'Invalid issuer',
            'invalid_transaction' => 'Invalid transaction',
            'unknown' => 'Unknown decline',
            'payment_issue' => 'Payment issue (API error)',
            'invalid_input' => 'Invalid input (API error)',
            'bad_request' => 'Bad request (API error)',
        ];
        
        // GP high-risk codes move to retryable when high-risk mode is on
        // (same behavior as NMI 201/253)
        if ($isHighRisk) {
            $gpRetryableCodes['do_not_honor'] = 'Do Not Honor (' . $retryLabel . ')';
            $gpRetryableCodes['generic_decline'] = 'Generic decline (' . $retryLabel . ')';
            $gpRetryableCodes['pickup_card'] = 'Pickup card (' . $retryLabel . ')';
        } else {
            $gpNonRetryableCodes['do_not_honor'] = 'Do Not Honor';
            $gpNonRetryableCodes['generic_decline'] = 'Generic decline';
            $gpNonRetryableCodes['pickup_card'] = 'Pickup card';
        }
        
        return [
            'retryable_gateway' => [
                'label' => 'Retryable ' . "\u{2014}" . ' Gateway Error',
                'description' => 'Gateway-side problem. Customer\'s card is fine. Auto-retry on different gateway.',
                'color' => '#2563eb',
                'health_impact' => 'Counts against gateway health',
                'codes' => $retryGatewayCodes,
            ],
            'retryable_card_type' => [
                'label' => 'Retryable ' . "\u{2014}" . ' Card Type',
                'description' => 'Card type not accepted on this gateway. Route to a compatible gateway.',
                'color' => '#334155',
                'health_impact' => 'Does NOT count against health',
                'codes' => [
                    '300 (Amex)' => 'American Express not accepted',
                    '300 (Discover)' => 'Discover not accepted',
                    '300 (Maestro)' => 'Maestro not accepted',
                ],
            ],
            'non_retryable' => [
                'label' => 'Non-Retryable ' . "\u{2014}" . ' Customer Issue',
                'description' => 'Customer\'s card or account problem. Won\'t work on any gateway.',
                'color' => '#dc2626',
                'health_impact' => 'Does NOT count against health',
                'codes' => $nonRetryableCodes,
            ],
            'gp_retryable_gateway' => [
                'label' => 'G-Payments ' . "\u{2014}" . ' Retryable',
                'description' => 'G-Payments gateway-side problem. Auto-retry on different gateway.',
                'color' => '#2563eb',
                'health_impact' => 'Counts against gateway health',
                'codes' => $gpRetryableCodes,
            ],
            'gp_non_retryable' => [
                'label' => 'G-Payments ' . "\u{2014}" . ' Non-Retryable',
                'description' => 'G-Payments customer card/account problem. Won\'t work on any gateway.',
                'color' => '#dc2626',
                'health_impact' => 'Does NOT count against health',
                'codes' => $gpNonRetryableCodes,
            ],
        ];
    }
    
    /**
     * Render reports page
     */
    public function renderReports(): void {
        try {
            $repo = $this->container->get(GatewayRepository::class);
            $gateways = $repo->getAll();
            
            // Get filter parameters
            $reportType = isset($_GET['report_type']) ? sanitize_text_field($_GET['report_type']) : 'today';
            $selectedGateway = isset($_GET['gateway']) ? sanitize_text_field($_GET['gateway']) : '';
            $dateFrom = isset($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : '';
            $dateTo = isset($_GET['date_to']) ? sanitize_text_field($_GET['date_to']) : '';
            
            // Calculate date range based on report type
            $dates = $this->getReportDateRange($reportType, $dateFrom, $dateTo);
            $dateFrom = $dates['from'];
            $dateTo = $dates['to'];
            
            // Generate report data (CSV export is handled earlier in admin_init)
            $reportData = $this->generateReportData($dateFrom, $dateTo, $selectedGateway, $gateways);
            
            include PO_PLUGIN_DIR . 'templates/admin/reports.php';
        } catch (\Exception $e) {
            Logger::error('Reports page error', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            echo '<div class="notice notice-error"><p>Error loading reports: ' . esc_html($e->getMessage()) . '</p></div>';
        }
    }
    
    /**
     * Get date range for report type
     */
    private function getReportDateRange(string $type, string $customFrom, string $customTo): array {
        $timezone = wp_timezone();
        $now = new \DateTime('now', $timezone);
        
        switch ($type) {
            case 'yesterday':
                $from = (clone $now)->modify('-1 day')->format('Y-m-d');
                $to = $from;
                break;
            case 'this_week':
                $from = (clone $now)->modify('monday this week')->format('Y-m-d');
                $to = $now->format('Y-m-d');
                break;
            case 'this_month':
                $from = $now->format('Y-m-01');
                $to = $now->format('Y-m-d');
                break;
            case 'last_month':
                $from = (clone $now)->modify('first day of last month')->format('Y-m-d');
                $to = (clone $now)->modify('last day of last month')->format('Y-m-d');
                break;
            case 'custom':
                $from = $customFrom ?: $now->format('Y-m-d');
                $to = $customTo ?: $now->format('Y-m-d');
                break;
            case 'today':
            default:
                $from = $now->format('Y-m-d');
                $to = $from;
                break;
        }
        
        return ['from' => $from, 'to' => $to];
    }
    
    /**
     * Generate report data
     *
     * Summary counts and volume for single-day ranges (today, yesterday) use po_daily_stats,
     * which is the same source as the dashboard. This ensures Reports and Dashboard always
     * show identical transaction counts. po_daily_stats uses WP-timezone dates (stat_date),
     * so there is no UTC-offset mismatch for day-level aggregates.
     *
     * Multi-day ranges (this_week, this_month, last_month, custom) continue to query
     * po_transactions with proper UTC-boundary conversion - po_daily_stats rows are
     * already date-bounded so they work cleanly for those spans too, but po_transactions
     * is kept for multi-day to preserve the existing CSV export and detail list logic.
     *
     * Transaction detail lists (failed list, recent list) always come from po_transactions
     * regardless of range - those are for drill-down only and do not affect headline numbers.
     */
    private function generateReportData(string $dateFrom, string $dateTo, string $gatewayFilter, array $gateways): array {
        global $wpdb;
        $table      = $wpdb->prefix . 'po_transactions';
        $statsTable = $wpdb->prefix . 'po_daily_stats';

        // Build gateway lookup
        $gatewayNames = [];
        foreach ($gateways as $gw) {
            $gatewayNames[$gw->id] = $gw->displayName;
        }

        // 2.7.25: po_transactions.created_at is stored in the SITE timezone
        // (current_time('mysql')), not UTC - the previous UTC conversion shifted
        // every multi-day report by the site's offset (Oct to date: -$1,594).
        // Kept for reference; detail queries below use the site-time range.
        $timezone = wp_timezone();
        $utcTz    = new \DateTimeZone('UTC');

        $startLocal = new \DateTime($dateFrom . ' 00:00:00', $timezone);
        $endLocal   = new \DateTime($dateTo   . ' 23:59:59', $timezone);
        $startUtc   = (clone $startLocal)->setTimezone($utcTz)->format('Y-m-d H:i:s');
        $endUtc     = (clone $endLocal)->setTimezone($utcTz)->format('Y-m-d H:i:s');

        // Base conditions for po_transactions detail queries (site-time range)
        $where  = "WHERE created_at >= %s AND created_at <= %s";
        $params = [$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59'];

        if ($gatewayFilter) {
            $where   .= " AND gateway_id = %s";
            $params[] = $gatewayFilter;
        }

        // -- Summary & gateway breakdown -----------------------------------------
        // For single-day ranges use po_daily_stats (WP-timezone stat_date) so that
        // counts exactly match what the dashboard shows via getTodayStatsByGateway().
        // For multi-day ranges use po_transactions with UTC conversion as before.
        $isSingleDay = ($dateFrom === $dateTo);

        if ($isSingleDay) {
            // po_daily_stats: stat_date is a DATE column written in WP timezone,
            // so comparing against a WP-timezone date string is always correct.
            $dsWhere  = "WHERE stat_date >= %s AND stat_date <= %s";
            $dsParams = [$dateFrom, $dateTo];

            if ($gatewayFilter) {
                $dsWhere   .= " AND gateway_id = %s";
                $dsParams[] = $gatewayFilter;
            }

            // NOTE: transaction_count in po_daily_stats only increments on SUCCESS
            // (see VolumeTracker::updateDailyStats - failures increment failure_count only).
            // Total must be computed as success_count + failure_count to include both.
            $dsRows = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    gateway_id,
                    COALESCE(SUM(success_count),  0) AS successful,
                    COALESCE(SUM(failure_count),  0) AS failed,
                    COALESCE(SUM(success_count) + SUM(failure_count), 0) AS total,
                    COALESCE(SUM(total_volume),   0) AS volume
                FROM {$statsTable} {$dsWhere}
                GROUP BY gateway_id
                ORDER BY total DESC",
                ...$dsParams
            ), ARRAY_A) ?: [];

            // Build the summary totals by summing the per-gateway rows.
            $totalSuccessful = 0;
            $totalFailed     = 0;
            $totalTxns       = 0;
            $totalVolume     = 0.0;

            foreach ($dsRows as &$gw) {
                $gw['display_name'] = $gatewayNames[$gw['gateway_id']] ?? $gw['gateway_id'];
                $gw['success_rate'] = $gw['total'] > 0
                    ? round(($gw['successful'] / $gw['total']) * 100, 1)
                    : 0;
                $totalSuccessful += (int) $gw['successful'];
                $totalFailed     += (int) $gw['failed'];
                $totalTxns       += (int) $gw['total'];
                $totalVolume     += (float) $gw['volume'];
            }
            unset($gw);

            $byGateway  = $dsRows;
            $successRate = $totalTxns > 0 ? round(($totalSuccessful / $totalTxns) * 100, 1) : 0;
            $summary = (object) [
                'total_transactions' => $totalTxns,
                'successful'         => $totalSuccessful,
                'failed'             => $totalFailed,
                'total_volume'       => $totalVolume,
            ];

        } else {
            // Multi-day: use po_transactions with UTC-boundary conversion (original logic).
            $summary = $wpdb->get_row($wpdb->prepare(
                "SELECT 
                    COUNT(*) as total_transactions,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as total_volume
                FROM {$table} {$where}",
                ...$params
            ));

            if (!$summary) {
                $summary = (object) ['total_transactions' => 0, 'successful' => 0, 'failed' => 0, 'total_volume' => 0];
            }

            $successRate = $summary->total_transactions > 0
                ? round(($summary->successful / $summary->total_transactions) * 100, 1)
                : 0;

            $byGateway = $wpdb->get_results($wpdb->prepare(
                "SELECT 
                    gateway_id,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'success' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN status = 'failed'  THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN status = 'success' THEN amount ELSE 0 END) as volume
                FROM {$table} {$where}
                GROUP BY gateway_id
                ORDER BY total DESC",
                ...$params
            ), ARRAY_A) ?: [];

            foreach ($byGateway as &$gw) {
                $gw['display_name'] = $gatewayNames[$gw['gateway_id']] ?? $gw['gateway_id'];
                $gw['success_rate'] = $gw['total'] > 0
                    ? round(($gw['successful'] / $gw['total']) * 100, 1)
                    : 0;
            }
            unset($gw);
        }
        
        // Failed transactions (simple query without join)
        $failedTransactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} 
            {$where} AND status = 'failed'
            ORDER BY created_at DESC
            LIMIT 100",
            ...$params
        ), ARRAY_A);
        
        if (!$failedTransactions) {
            $failedTransactions = [];
        }
        
        // Get failure reasons from order meta/notes
        foreach ($failedTransactions as &$txn) {
            $txn['gateway_name'] = $gatewayNames[$txn['gateway_id']] ?? $txn['gateway_id'];
            $txn['failure_reason'] = $this->getOrderFailureReason((int) $txn['order_id']);
        }
        
        // Total failed count
        $failedCountTotal = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} {$where} AND status = 'failed'",
            ...$params
        ));
        
        // Recent transactions (limit 50)
        $transactions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} {$where}
            ORDER BY created_at DESC
            LIMIT 50",
            ...$params
        ), ARRAY_A);
        
        if (!$transactions) {
            $transactions = [];
        }
        
        foreach ($transactions as &$txn) {
            $txn['gateway_name'] = $gatewayNames[$txn['gateway_id']] ?? $txn['gateway_id'];
        }
        
        return [
            'summary' => [
                'total_transactions' => (int) ($summary->total_transactions ?? 0),
                'successful' => (int) ($summary->successful ?? 0),
                'failed' => (int) ($summary->failed ?? 0),
                'total_volume' => (float) ($summary->total_volume ?? 0),
                'success_rate' => $successRate,
            ],
            'by_gateway' => $byGateway,
            'failed_transactions' => $failedTransactions,
            'failed_count_total' => (int) ($failedCountTotal ?? 0),
            'transactions' => $transactions,
        ];
    }
    
    /**
     * Get failure reason from WooCommerce order
     */
    private function getOrderFailureReason($orderId): string {
        try {
            $orderId = (int) $orderId;
            
            if (!$orderId) {
                return 'Unknown';
            }
            
            // First: check our structured failure table (clean code + reason)
            global $wpdb;
            $table = $wpdb->prefix . 'po_failed_transactions';
            $tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
            
            if ($tableExists) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT failure_code, failure_reason, failure_category FROM {$table} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
                    $orderId
                ));
                
                if ($row && ($row->failure_code || $row->failure_reason)) {
                    $parts = [];
                    if ($row->failure_code) {
                        $parts[] = $row->failure_code;
                    }
                    if ($row->failure_reason) {
                        $parts[] = $row->failure_reason;
                    }
                    return implode(' - ', $parts);
                }
            }
            
            // Fallback: order meta
            if (!function_exists('wc_get_order')) {
                return 'WooCommerce not available';
            }
            
            $order = wc_get_order($orderId);
            if (!$order) {
                return 'Order not found';
            }
            
            $failureReason = $order->get_meta('_payment_failure_reason');
            if ($failureReason) {
                return $failureReason;
            }
            
            // Fallback: scan order notes for NMI pattern first, then general failure notes
            if (function_exists('wc_get_order_notes')) {
                $notes = wc_get_order_notes([
                    'order_id' => $orderId,
                    'limit' => 10,
                    'type' => 'internal',
                ]);
                
                foreach ($notes as $note) {
                    if (preg_match('/NMI failure reason:\s*(.+)/i', $note->content, $match)) {
                        return wp_strip_all_tags(trim($match[1]));
                    }
                }
                
                foreach ($notes as $note) {
                    $content = strtolower($note->content);
                    if (strpos($content, 'failed') !== false || 
                        strpos($content, 'declined') !== false ||
                        strpos($content, 'error') !== false) {
                        return wp_strip_all_tags($note->content);
                    }
                }
            }
            
            return 'Payment failed - no details available';
        } catch (\Exception $e) {
            return 'Error retrieving reason';
        }
    }
    
    /**
     * Export report to CSV
     */
    private function exportReportCsv(array $reportData, string $dateFrom, string $dateTo): void {
        // Clean any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        $filename = 'payment-orchestrator-report-' . $dateFrom . '-to-' . $dateTo . '.csv';
        
        // Prevent caching
        nocache_headers();
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        
        // Add BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Get the selected gateway filter from URL
        $selectedGateway = isset($_GET['gateway']) ? sanitize_text_field($_GET['gateway']) : '';
        
        // Get gateway lookup for custom names
        $repo = $this->container->get(GatewayRepository::class);
        $gateways = $repo->getAll();
        $gatewayNames = [];
        foreach ($gateways as $gw) {
            $gatewayNames[$gw->id] = $gw->displayName;
        }
        
        $gatewayLabel = $selectedGateway 
            ? ($gatewayNames[$selectedGateway] ?? $selectedGateway) 
            : 'All Gateways';
        
        // Header row
        fputcsv($output, ['Payment Orchestrator Transaction Report']);
        fputcsv($output, ['Date Range:', $dateFrom . ' to ' . $dateTo]);
        fputcsv($output, ['Gateway:', $gatewayLabel]);
        fputcsv($output, ['Generated:', current_time('Y-m-d H:i:s')]);
        fputcsv($output, []);
        
        // Summary
        fputcsv($output, ['SUMMARY']);
        fputcsv($output, ['Total Transactions', $reportData['summary']['total_transactions']]);
        fputcsv($output, ['Successful', $reportData['summary']['successful']]);
        fputcsv($output, ['Failed', $reportData['summary']['failed']]);
        fputcsv($output, ['Total Volume', '$' . number_format($reportData['summary']['total_volume'], 2)]);
        fputcsv($output, ['Success Rate', $reportData['summary']['success_rate'] . '%']);
        fputcsv($output, []);
        
        // Query WooCommerce orders directly
        global $wpdb;
        
        // Get WordPress timezone
        $wpTimezone = wp_timezone_string();
        $localTz = wp_timezone();
        $utcTz = new \DateTimeZone('UTC');
        
        // Convert local date range to GMT for querying
        try {
            $startLocal = new \DateTime($dateFrom . ' 00:00:00', $localTz);
            $endLocal = new \DateTime($dateTo . ' 23:59:59', $localTz);
            
            $startUtc = clone $startLocal;
            $startUtc->setTimezone($utcTz);
            $endUtc = clone $endLocal;
            $endUtc->setTimezone($utcTz);
            
            $startDateGmt = $startUtc->format('Y-m-d H:i:s');
            $endDateGmt = $endUtc->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            $startDateGmt = $dateFrom . ' 00:00:00';
            $endDateGmt = $dateTo . ' 23:59:59';
        }
        
        // Check if HPOS is enabled
        $isHpos = class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') 
            && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
        
        // SECTION 1: TRANSACTIONS
        fputcsv($output, ['TRANSACTIONS']);
        fputcsv($output, ['Timezone:', $wpTimezone]);
        fputcsv($output, []);
        fputcsv($output, ['Order ID', 'Gateway ID', 'Gateway Name', 'Amount', 'Date/Time', 'Order Status']);
        
        if ($isHpos) {
            // Build query with optional gateway filter
            $sql = "SELECT id, payment_method, total_amount, status, date_created_gmt
                FROM {$wpdb->prefix}wc_orders 
                WHERE type = 'shop_order'
                AND date_created_gmt >= %s
                AND date_created_gmt <= %s";
            $params = [$startDateGmt, $endDateGmt];
            
            if ($selectedGateway) {
                $sql .= " AND payment_method = %s";
                $params[] = $selectedGateway;
            }
            
            $sql .= " ORDER BY date_created_gmt DESC";
            
            $orders = $wpdb->get_results($wpdb->prepare($sql, ...$params));
            
            foreach ($orders as $order) {
                $gatewayId = $order->payment_method ?: 'N/A';
                $customName = $gatewayNames[$gatewayId] ?? $gatewayId;
                $status = str_replace('wc-', '', $order->status);
                
                $localTime = $this->convertGmtToLocal($order->date_created_gmt);
                
                fputcsv($output, [
                    $order->id,
                    $gatewayId,
                    $customName,
                    '$' . number_format((float)$order->total_amount, 2),
                    $localTime,
                    ucfirst($status),
                ]);
            }
        } else {
            // Legacy post-based orders
            $startDateLocal = $dateFrom . ' 00:00:00';
            $endDateLocal = $dateTo . ' 23:59:59';
            
            $sql = "SELECT p.ID, p.post_date, p.post_status,
                    MAX(CASE WHEN pm.meta_key = '_payment_method' THEN pm.meta_value END) as payment_method,
                    MAX(CASE WHEN pm.meta_key = '_order_total' THEN pm.meta_value END) as total_amount
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_type = 'shop_order'
                AND p.post_date >= %s
                AND p.post_date <= %s";
            $params = [$startDateLocal, $endDateLocal];
            
            if ($selectedGateway) {
                $sql .= " AND p.ID IN (
                    SELECT post_id FROM {$wpdb->postmeta} 
                    WHERE meta_key = '_payment_method' AND meta_value = %s
                )";
                $params[] = $selectedGateway;
            }
            
            $sql .= " GROUP BY p.ID ORDER BY p.post_date DESC";
            
            $orders = $wpdb->get_results($wpdb->prepare($sql, ...$params));
            
            foreach ($orders as $order) {
                $gatewayId = $order->payment_method ?: 'N/A';
                $customName = $gatewayNames[$gatewayId] ?? $gatewayId;
                $status = str_replace('wc-', '', $order->post_status);
                
                fputcsv($output, [
                    $order->ID,
                    $gatewayId,
                    $customName,
                    '$' . number_format((float)$order->total_amount, 2),
                    $order->post_date,
                    ucfirst($status),
                ]);
            }
        }
        
        // SECTION 2: FAILED TRANSACTIONS WITH RECOVERY INFO
        fputcsv($output, []);
        fputcsv($output, ['FAILED TRANSACTIONS']);
        fputcsv($output, ['Order ID', 'Failed Gateway', 'Failure Code', 'Failure Reason', 'Category', 'Amount', 'Date/Time', 'Recovered', 'Recovery Gateway', 'Recovery Source', 'Notes']);
        
        $failTable = $wpdb->prefix . 'po_failed_transactions';
        $failTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$failTable}'");
        
        if ($failTableExists) {
            // Convert dates to UTC for po_failed_transactions.created_at
            $failSql = "SELECT * FROM {$failTable} WHERE created_at >= %s AND created_at <= %s";
            $failParams = [$startDateGmt, $endDateGmt];
            
            if ($selectedGateway) {
                // Include failures where the selected gateway was either the failing OR recovering gateway
                $failSql .= " AND (gateway_id = %s OR retry_gateway = %s)";
                $failParams[] = $selectedGateway;
                $failParams[] = $selectedGateway;
            }
            
            $failSql .= " ORDER BY created_at DESC";
            
            $failures = $wpdb->get_results($wpdb->prepare($failSql, ...$failParams), ARRAY_A);
            
            foreach ($failures ?: [] as $f) {
                $failedGwName = $gatewayNames[$f['gateway_id']] ?? $f['gateway_id'];
                $retryGwName = '';
                $notes = '';
                
                if ($f['recovered'] && $f['retry_gateway']) {
                    $retryGwName = $gatewayNames[$f['retry_gateway']] ?? $f['retry_gateway'];
                    $notes = "Originally failed on {$failedGwName}, recovered via {$retryGwName}";
                } elseif (!$f['recovered'] && $f['retry_gateway']) {
                    $retryGwName = $gatewayNames[$f['retry_gateway']] ?? $f['retry_gateway'];
                    $notes = "Retry assigned to {$retryGwName}, not yet recovered";
                } else {
                    $notes = 'Not retryable';
                }
                
                $localTime = $this->convertGmtToLocal($f['created_at']);
                
                fputcsv($output, [
                    $f['order_id'],
                    $failedGwName,
                    $f['failure_code'] ?: 'N/A',
                    $f['failure_reason'] ?: 'N/A',
                    $f['failure_category'] ?: 'N/A',
                    '$' . number_format((float)$f['order_total'], 2),
                    $localTime,
                    $f['recovered'] ? 'Yes' : 'No',
                    $retryGwName ?: 'N/A',
                    $f['recovery_source'] ?: 'N/A',
                    $notes,
                ]);
            }
        }
        
        fclose($output);
    }
    
    /**
     * Get timezone offset string for MySQL CONVERT_TZ
     */
    private function getTimezoneOffset(): string {
        $timezone = wp_timezone();
        $now = new \DateTime('now', $timezone);
        $offset = $now->format('P'); // Returns like +05:30 or -08:00
        return $offset;
    }
    
    /**
     * Convert GMT time to local WordPress time
     */
    private function convertGmtToLocal(string $gmtTime): string {
        try {
            $utc = new \DateTimeZone('UTC');
            $local = wp_timezone();
            $dt = new \DateTime($gmtTime, $utc);
            $dt->setTimezone($local);
            return $dt->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            return $gmtTime;
        }
    }
    
    /**
     * Handle gateway add/edit/delete actions
     */
    private function handleGatewayActions(GatewayRepository $repo): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }
        
        if (isset($_POST['po_add_gateway']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'po_add_gateway')) {
            $gatewayId = sanitize_text_field($_POST['gateway_id'] ?? '');
            $displayName = sanitize_text_field($_POST['display_name'] ?? '');
            
            if ($gatewayId && $displayName) {
                $result = $repo->add([
                    'gateway_id' => $gatewayId,
                    'display_name' => $displayName,
                    'monthly_target' => (float) ($_POST['monthly_target'] ?? 0),
                    'max_transaction_amount' => (float) ($_POST['max_transaction_amount'] ?? 0),
                    'gateway_type' => sanitize_text_field($_POST['gateway_type'] ?? 'card'),
                    'accepts_visa' => isset($_POST['accepts_visa']) ? 1 : 0,
                    'accepts_mastercard' => isset($_POST['accepts_mastercard']) ? 1 : 0,
                    'accepts_amex' => isset($_POST['accepts_amex']) ? 1 : 0,
                    'accepts_discover' => isset($_POST['accepts_discover']) ? 1 : 0,
                    'is_always_on' => isset($_POST['is_always_on']) ? 1 : 0,
                    'priority' => (int) ($_POST['priority'] ?? 10),
                ]);
                
                if ($result) {
                    add_settings_error('po_messages', 'po_added', 'Gateway added successfully.', 'success');
                } else {
                    add_settings_error('po_messages', 'po_error', 'Failed to add gateway. It may already exist.', 'error');
                }
            }
        }
        
        if (isset($_POST['po_update_gateway']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'po_update_gateway')) {
            $gatewayId = sanitize_text_field($_POST['gateway_id'] ?? '');
            
            if ($gatewayId) {
                $repo->update($gatewayId, [
                    'display_name' => sanitize_text_field($_POST['display_name'] ?? ''),
                    'monthly_target' => (float) ($_POST['monthly_target'] ?? 0),
                    'max_transaction_amount' => (float) ($_POST['max_transaction_amount'] ?? 0),
                    'gateway_type' => sanitize_text_field($_POST['gateway_type'] ?? 'card'),
                    'accepts_visa' => isset($_POST['accepts_visa']) ? 1 : 0,
                    'accepts_mastercard' => isset($_POST['accepts_mastercard']) ? 1 : 0,
                    'accepts_amex' => isset($_POST['accepts_amex']) ? 1 : 0,
                    'accepts_discover' => isset($_POST['accepts_discover']) ? 1 : 0,
                    'is_enabled' => isset($_POST['is_enabled']) ? 1 : 0,
                    'is_always_on' => isset($_POST['is_always_on']) ? 1 : 0,
                    'priority' => (int) ($_POST['priority'] ?? 10),
                ]);
                
                add_settings_error('po_messages', 'po_updated', 'Gateway updated successfully.', 'success');
                wp_redirect(admin_url('admin.php?page=payment-orchestrator-gateways'));
                exit;
            }
        }
        
        if (isset($_POST['po_delete_gateway']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'po_delete_gateway')) {
            $gatewayId = sanitize_text_field($_POST['gateway_id'] ?? '');
            
            if ($gatewayId) {
                $repo->delete($gatewayId);
                add_settings_error('po_messages', 'po_deleted', 'Gateway removed.', 'success');
            }
        }
    }
    
    /**
     * Get current settings
     */
    private function getSettings(): array {
        $defaultStatuses = ['wc-processing', 'wc-completed', 'wc-shipped', 'wc-delivered'];
        return [
            'max_cold_transactions' => (int) get_option('po_max_cold_transactions', 5),
            'test_mode' => get_option('po_test_mode', 'no'),
            'rotation_debug' => get_option('po_rotation_debug', 'no'),
            'success_statuses' => get_option('po_success_statuses', $defaultStatuses),
            'daily_report_enabled' => get_option('po_daily_report_enabled', 'no'),
            'daily_report_time' => get_option('po_daily_report_time', '20:00'),
            'daily_report_timezone' => get_option('po_daily_report_timezone', 'America/Chicago'),
            'daily_report_email' => get_option('po_daily_report_email', get_option('admin_email')),
            'enable_logging' => get_option('po_enable_logging', 'yes'),
            'log_level' => get_option('po_log_level', 'info'),
            'delete_data_on_uninstall' => get_option('po_delete_data_on_uninstall', 'no'),
            // Failure alerts
            'failure_alert_enabled' => get_option('po_failure_alert_enabled', 'yes'),
            'failure_alert_threshold' => (int) get_option('po_failure_alert_threshold', 3),
            'failure_alert_window' => (int) get_option('po_failure_alert_window', 10),
            'failure_alert_email' => get_option('po_failure_alert_email', get_option('admin_email')),
            // High-risk retry
            'high_risk_retry_mode' => get_option('po_high_risk_retry_mode', 'off'),
            'high_risk_retry_gateway' => get_option('po_high_risk_retry_gateway', ''),
            // Nuclear bypass
            'bypass_enabled' => get_option('po_bypass_enabled', 'no'),
            'auto_bypass_enabled' => get_option('po_auto_bypass_enabled', 'no'),
            // REST API
            'api_enabled' => get_option('po_api_enabled', 'no'),
            'api_key_prefix' => get_option('po_api_key_prefix', ''),
            'api_key_created' => get_option('po_api_key_created', ''),
            'api_ip_allowlist' => get_option('po_api_ip_allowlist', ''),
            'api_require_ssl' => get_option('po_api_require_ssl', 'yes'),
        ];
    }
    
    /**
     * Save settings
     */
    private function saveSettings(array $data): void {
        // 2.7.27: admin theme - saved per user, not site-wide
        if (isset($data['po_admin_theme'])) {
            \PaymentOrchestrator\Admin\Theme::save(sanitize_key(wp_unslash((string) $data['po_admin_theme'])));
        }
        
        $fields = [
            'max_cold_transactions' => 'intval',
            'test_mode' => 'sanitize_text_field',
            'rotation_debug' => 'sanitize_text_field',
            'daily_report_enabled' => 'sanitize_text_field',
            'daily_report_time' => 'sanitize_text_field',
            'daily_report_timezone' => 'sanitize_text_field',
            'daily_report_email' => 'sanitize_email',
            'enable_logging' => 'sanitize_text_field',
            'log_level' => 'sanitize_text_field',
            'log_retention_days' => 'intval',
            'max_log_entries' => 'intval',
            'delete_data_on_uninstall' => 'sanitize_text_field',
            // Failure alerts
            'failure_alert_enabled' => 'sanitize_text_field',
            'failure_alert_threshold' => 'intval',
            'failure_alert_window' => 'intval',
            'failure_alert_email' => 'sanitize_email',
            // High-risk retry
            'high_risk_retry_mode' => 'sanitize_text_field',
            'high_risk_retry_gateway' => 'sanitize_text_field',
            // Nuclear bypass
            'bypass_enabled' => 'sanitize_text_field',
            'auto_bypass_enabled' => 'sanitize_text_field',
            // REST API
            'api_enabled' => 'sanitize_text_field',
            'api_ip_allowlist' => 'sanitize_text_field',
            'api_require_ssl' => 'sanitize_text_field',
        ];
        
        foreach ($fields as $field => $sanitize) {
            if (isset($data[$field])) {
                $value = $sanitize($data[$field]);
                update_option('po_' . $field, $value);
            }
        }
        
        // Save success statuses (array)
        if (isset($data['success_statuses']) && is_array($data['success_statuses'])) {
            $statuses = array_map('sanitize_text_field', $data['success_statuses']);
            update_option('po_success_statuses', $statuses);
        } elseif (!isset($data['success_statuses'])) {
            // No checkboxes selected - save empty array
            update_option('po_success_statuses', []);
        }
        
        // Reschedule daily report
        wp_clear_scheduled_hook('po_daily_report');
        
        if (get_option('po_daily_report_enabled', 'no') === 'yes') {
            $timezone = get_option('po_daily_report_timezone', 'America/Chicago');
            $time = get_option('po_daily_report_time', '20:00');
            
            try {
                $tz = new \DateTimeZone($timezone);
                $scheduled = new \DateTime($time, $tz);
                $now = new \DateTime('now', $tz);
                
                if ($scheduled <= $now) {
                    $scheduled->modify('+1 day');
                }
                
                wp_schedule_event($scheduled->getTimestamp(), 'daily', 'po_daily_report');
            } catch (\Exception $e) {
                Logger::error('Failed to schedule daily report', ['error' => $e->getMessage()]);
            }
        }
        
        Logger::info('Settings saved');
    }
    
    /**
     * Get available WooCommerce gateways
     */
    private function getWooCommerceGateways(): array {
        if (!function_exists('WC') || !WC()->payment_gateways()) {
            return [];
        }
        
        $gateways = [];
        foreach (WC()->payment_gateways()->payment_gateways() as $gateway) {
            if ($gateway->enabled === 'yes') {
                $gateways[$gateway->id] = $gateway->get_title();
            }
        }
        
        return $gateways;
    }
    
    /**
     * AJAX: Get logs
     */
    public function ajaxGetLogs(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $level = isset($_POST['level']) ? sanitize_text_field($_POST['level']) : null;
        $logs = Logger::getLogs(200, $level !== 'all' ? $level : null);
        
        wp_send_json_success($logs);
    }
    
    /**
     * AJAX: Clear logs
     */
    public function ajaxClearLogs(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        Logger::clear();
        Logger::info('Logs cleared by admin');
        
        wp_send_json_success();
    }
    
    /**
     * AJAX: Export logs
     */
    public function ajaxExportLogs(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $export = Logger::export();
        
        wp_send_json_success(['content' => $export]);
    }
    
    /**
     * AJAX: Send test report
     */
    public function ajaxSendTestReport(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
        
        if (!$email) {
            wp_send_json_error('Invalid email address');
        }
        
        $reporter = $this->container->get(DailyReporter::class);
        $result = $reporter->sendTest($email);
        
        if ($result) {
            wp_send_json_success('Report sent to ' . $email);
        } else {
            wp_send_json_error('Failed to send report');
        }
    }
    
    /**
     * AJAX: Backfill volume from WooCommerce orders
     */
    public function ajaxBackfillVolume(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $gatewayId = isset($_POST['gateway_id']) ? sanitize_text_field($_POST['gateway_id']) : '';
        $month = isset($_POST['month']) ? sanitize_text_field($_POST['month']) : null; // Format: YYYY-MM
        $rebackfill = isset($_POST['rebackfill']) && $_POST['rebackfill'] === 'true';
        
        if (!$gatewayId) {
            wp_send_json_error('Gateway ID required');
        }
        
        $tracker = $this->container->get(VolumeTracker::class);
        $result = $tracker->backfillFromOrders($gatewayId, $month, $rebackfill);
        
        if ($result['success']) {
            wp_send_json_success([
                'message' => sprintf(
                    'Backfilled %d orders totaling $%s for %s',
                    $result['count'],
                    number_format($result['volume'], 2),
                    $gatewayId
                ),
                'count' => $result['count'],
                'volume' => $result['volume'],
            ]);
        } else {
            wp_send_json_error($result['message'] ?? 'Backfill failed');
        }
    }
    
    /**
     * AJAX: Get 3-month history data for chart
     */
    public function ajaxGetHistory(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $tracker = $this->container->get(VolumeTracker::class);
        $history = $tracker->getThreeMonthHistory();
        
        wp_send_json_success($history);
    }
    
    /**
     * AJAX: Simulate rotation distribution
     */
    public function ajaxSimulateRotation(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $count = isset($_POST['count']) ? (int) $_POST['count'] : 100;
        $count = min(1000, max(10, $count)); // Limit between 10-1000
        
        $rotation = $this->container->get(\PaymentOrchestrator\Routing\RotationManager::class);
        $distribution = $rotation->simulateDistribution($count);
        $weights = $rotation->getCurrentWeights();
        
        wp_send_json_success([
            'distribution' => $distribution,
            'weights' => $weights,
            'simulated_count' => $count,
        ]);
    }
    
    /**
     * AJAX: Reset and sync all gateways
     */
    public function ajaxResetSyncAll(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        // Extend execution time for large syncs
        if (function_exists('set_time_limit')) {
            @set_time_limit(600); // 10 minutes
        }
        
        // Increase memory limit if possible
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }
        
        global $wpdb;
        
        // Disable autocommit for faster inserts
        $wpdb->query('SET autocommit = 0');
        
        try {
            // Step 1: Clear all transaction data
            $wpdb->query("DELETE FROM {$wpdb->prefix}po_transactions");
            $wpdb->query("DELETE FROM {$wpdb->prefix}po_daily_stats");
            $wpdb->query('COMMIT');
            
            Logger::info('Reset & Sync: Cleared all transaction data');
            
            // Step 2: Get all managed gateways
            $repo = $this->container->get(GatewayRepository::class);
            $gateways = $repo->getManaged();
            
            if (empty($gateways)) {
                $wpdb->query('SET autocommit = 1');
                wp_send_json_success([
                    'message' => 'Data cleared. No managed gateways to sync.',
                    'gateways_synced' => 0,
                ]);
                return;
            }
            
            // Step 3: Backfill each gateway for current month
            $tracker = $this->container->get(VolumeTracker::class);
            $results = [];
            $totalOrders = 0;
            $totalVolume = 0;
            $errors = [];
            
            foreach ($gateways as $gateway) {
                try {
                    $result = $tracker->backfillVolume($gateway->id, '', true); // Current month, rebackfill
                    $results[$gateway->id] = [
                        'name' => $gateway->displayName,
                        'orders' => $result['count'] ?? 0,
                        'volume' => $result['volume'] ?? 0,
                    ];
                    $totalOrders += $result['count'] ?? 0;
                    $totalVolume += $result['volume'] ?? 0;
                    
                    Logger::info('Reset & Sync: Backfilled gateway', [
                        'gateway' => $gateway->id,
                        'orders' => $result['count'] ?? 0,
                    ]);
                    
                    // Commit after each gateway to prevent loss
                    $wpdb->query('COMMIT');
                    
                } catch (\Exception $e) {
                    $errors[] = $gateway->displayName . ': ' . $e->getMessage();
                    Logger::error('Reset & Sync: Gateway backfill failed', [
                        'gateway' => $gateway->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            $wpdb->query('SET autocommit = 1');
            
            $message = sprintf(
                'Synced %d gateways: %d orders, $%s volume',
                count($gateways),
                $totalOrders,
                number_format($totalVolume, 2)
            );
            
            if (!empty($errors)) {
                $message .= ' (Errors: ' . implode('; ', $errors) . ')';
            }
            
            wp_send_json_success([
                'message' => $message,
                'gateways_synced' => count($gateways),
                'total_orders' => $totalOrders,
                'total_volume' => $totalVolume,
                'details' => $results,
                'errors' => $errors,
            ]);
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            $wpdb->query('SET autocommit = 1');
            Logger::error('Reset & Sync failed', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => 'Sync failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * AJAX: Clear all health data
     */
    public function ajaxClearHealth(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }
        
        try {
            $health = $this->container->get(\PaymentOrchestrator\Health\HealthMonitor::class);
            $health->clearAllHealth();
            
            Logger::info('Health data cleared for all gateways');
            
            wp_send_json_success([
                'message' => 'Health data cleared for all gateways. All gateways will now show as healthy until new transaction data is collected.',
            ]);
            
        } catch (\Exception $e) {
            Logger::error('Clear health failed', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => 'Failed: ' . $e->getMessage()]);
        }
    }
    
    /**
     * AJAX: Drill down into transactions by gateway, status, date range, or failure code.
     */
    public function ajaxToggleOrchestrator(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $enabled = sanitize_text_field($_POST['enabled'] ?? 'yes');
        update_option('po_orchestrator_enabled', $enabled === 'yes' ? 'yes' : 'no');
        
        wp_send_json_success([
            'enabled' => get_option('po_orchestrator_enabled', 'yes') === 'yes',
        ]);
    }
    
    public function ajaxOrderLookup(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if (!$orderId) {
            wp_send_json_error('Invalid Order ID');
        }
        
        $order = wc_get_order($orderId);
        if (!$order) {
            wp_send_json_error('Order #' . $orderId . ' not found');
        }
        
        // Gateway name lookup
        $repo = $this->container->get(GatewayRepository::class);
        $gateways = $repo->getAll();
        $gatewayNames = [];
        foreach ($gateways as $gw) {
            $gatewayNames[$gw->id] = $gw->displayName;
        }
        
        $gatewayId = $order->get_payment_method();
        $gatewayName = $gatewayNames[$gatewayId] ?? ($order->get_payment_method_title() ?: $gatewayId);
        
        $result = [
            'order_id' => $orderId,
            'status' => ucfirst($order->get_status()),
            'amount' => number_format((float) $order->get_total(), 2),
            'gateway_id' => $gatewayId,
            'gateway_name' => $gatewayName,
            'date' => $order->get_date_created() ? $order->get_date_created()->date('Y-m-d H:i:s') : 'N/A',
            'edit_url' => $order->get_edit_order_url(),
            'failure' => null,
        ];
        
        // Check for failure data
        global $wpdb;
        $failTable = $wpdb->prefix . 'po_failed_transactions';
        $failRow = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$failTable} WHERE order_id = %d ORDER BY id DESC LIMIT 1",
            $orderId
        ), ARRAY_A);
        
        if ($failRow) {
            $retryGwName = '';
            if ($failRow['retry_gateway']) {
                $retryGwName = $gatewayNames[$failRow['retry_gateway']] ?? $failRow['retry_gateway'];
            }
            
            $result['failure'] = [
                'code' => $failRow['failure_code'] ?: null,
                'reason' => $failRow['failure_reason'] ?: null,
                'category' => $failRow['failure_category'] ?: null,
                'recovered' => (bool) $failRow['recovered'],
                'retry_gateway' => $failRow['retry_gateway'] ?: null,
                'retry_gateway_name' => $retryGwName ?: null,
                'recovery_source' => $failRow['recovery_source'] ?: null,
            ];
        }
        
        wp_send_json_success($result);
    }
    
    public function ajaxToggleGateway(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        $gatewayId = sanitize_text_field($_POST['gateway_id'] ?? '');
        $enabled = ($_POST['enabled'] ?? '1') === '1' ? 1 : 0;
        
        if (!$gatewayId) {
            wp_send_json_error('Missing gateway_id');
        }
        
        $repo = $this->container->get(GatewayRepository::class);
        $repo->update($gatewayId, ['is_enabled' => $enabled]);
        
        wp_send_json_success([
            'gateway_id' => $gatewayId,
            'enabled' => $enabled,
        ]);
    }
    
    public function ajaxDrillDown(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $gatewayId = isset($_POST['gateway_id']) ? sanitize_text_field($_POST['gateway_id']) : '';
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $dateFrom = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : current_time('Y-m-d');
        $dateTo = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : current_time('Y-m-d');
        $failureCode = isset($_POST['failure_code']) ? sanitize_text_field($_POST['failure_code']) : '';
        $limit = 100;
        
        global $wpdb;
        $table = $wpdb->prefix . 'po_transactions';
        $failTable = $wpdb->prefix . 'po_failed_transactions';
        
        // Gateway name lookup
        $allGateways = $this->container->get(\PaymentOrchestrator\Gateway\GatewayRepository::class)->getAll();
        $gatewayNames = [];
        foreach ($allGateways as $gw) {
            $gatewayNames[$gw->id] = $gw->displayName;
        }
        
        try {
            // If filtering by failure_code, use po_failed_transactions table
            if ($failureCode !== '') {
                $failTableExists = $wpdb->get_var("SHOW TABLES LIKE '{$failTable}'");
                if (!$failTableExists) {
                    wp_send_json_success(['rows' => [], 'total' => 0]);
                    return;
                }
                
                // 2.7.25: po_failed_transactions is UTC - convert the site date range
                $failRange = \PaymentOrchestrator\Core\SiteTime::rangeToUtc($dateFrom . ' 00:00:00', gmdate('Y-m-d', strtotime($dateTo . ' +1 day')) . ' 00:00:00');
                $where = "WHERE failure_code = %s AND created_at >= %s AND created_at < %s";
                $params = [$failureCode, $failRange[0], $failRange[1]];
                
                if ($gatewayId) {
                    $where .= " AND gateway_id = %s";
                    $params[] = $gatewayId;
                }
                
                $total = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$failTable} {$where}",
                    ...$params
                ));
                
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT order_id, gateway_id, order_total as amount, failure_code, failure_reason, 
                            failure_category, recovered, created_at
                    FROM {$failTable} {$where}
                    ORDER BY created_at DESC LIMIT {$limit}",
                    ...$params
                ), ARRAY_A);
                
                foreach ($rows as &$row) {
                    $row['gateway_name'] = $gatewayNames[$row['gateway_id']] ?? $row['gateway_id'];
                    $row['status'] = 'failed';
                    $row['failure_reason'] = ($row['failure_code'] ? $row['failure_code'] . ' - ' : '') . ($row['failure_reason'] ?? '');
                    $row['edit_url'] = admin_url('post.php?post=' . $row['order_id'] . '&action=edit');
                }
                
                wp_send_json_success(['rows' => $rows, 'total' => $total]);
                return;
            }
            
            // Standard drill-down from po_transactions
            $where = "WHERE DATE(created_at) BETWEEN %s AND %s";
            $params = [$dateFrom, $dateTo];
            
            if ($gatewayId) {
                $where .= " AND gateway_id = %s";
                $params[] = $gatewayId;
            }
            
            if ($status && in_array($status, ['success', 'failed'], true)) {
                $where .= " AND status = %s";
                $params[] = $status;
            }
            
            $total = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} {$where}",
                ...$params
            ));
            
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT order_id, gateway_id, amount, status, error_code, error_message, created_at 
                FROM {$table} {$where}
                ORDER BY created_at DESC LIMIT {$limit}",
                ...$params
            ), ARRAY_A);
            
            foreach ($rows as &$row) {
                $row['gateway_name'] = $gatewayNames[$row['gateway_id']] ?? $row['gateway_id'];
                $row['edit_url'] = admin_url('post.php?post=' . $row['order_id'] . '&action=edit');
                
                // For failed rows, get the structured failure reason
                if ($row['status'] === 'failed') {
                    $row['failure_reason'] = $this->getOrderFailureReason((int) $row['order_id']);
                } else {
                    $row['failure_reason'] = '';
                }
            }
            
            wp_send_json_success(['rows' => $rows, 'total' => $total]);
            
        } catch (\Throwable $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Generate a new API key. Returns the plain-text key (shown once).
     */
    public function ajaxGenerateApiKey(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        try {
            $plainKey = \PaymentOrchestrator\Api\RestApi::generateApiKey();
            
            wp_send_json_success([
                'key' => $plainKey,
                'prefix' => substr($plainKey, 0, 7) . '...',
                'created' => current_time('mysql'),
            ]);
        } catch (\Throwable $e) {
            wp_send_json_error('Failed to generate API key: ' . $e->getMessage());
        }
    }
    
    /**
     * AJAX: Revoke the current API key.
     */
    public function ajaxRevokeApiKey(): void {
        check_ajax_referer('po_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Unauthorized');
        }
        
        try {
            \PaymentOrchestrator\Api\RestApi::revokeApiKey();
            wp_send_json_success(['revoked' => true]);
        } catch (\Throwable $e) {
            wp_send_json_error('Failed to revoke API key: ' . $e->getMessage());
        }
    }
}
