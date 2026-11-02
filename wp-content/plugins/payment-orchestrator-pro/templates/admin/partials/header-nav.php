<?php
/**
 * Shared header + tab navigation for all POP admin pages.
 * Include at the top of every admin template.
 *
 * Usage: include PO_PLUGIN_DIR . 'templates/admin/partials/header-nav.php';
 *
 * Expects: $currentPage to be set (e.g., 'payment-orchestrator', 'payment-orchestrator-gateways', etc.)
 * If not set, auto-detects from $_GET['page'].
 */

defined('ABSPATH') || exit;

$currentPage = $currentPage ?? ($_GET['page'] ?? 'payment-orchestrator');
$orchestratorEnabled = get_option('po_orchestrator_enabled', 'yes') === 'yes';

// SVG icons (16x16, stroke-based to match the Email Recovery screenshot style)
$tabIcons = [
    'dashboard'  => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
    'gateways'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'reports'    => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
    'scorecards' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>',
    'recovery'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/></svg>',
    'settings'   => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>',
    'help'       => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
];

$tabs = [
    'payment-orchestrator'            => ['label' => 'Dashboard',  'icon' => $tabIcons['dashboard']],
    'payment-orchestrator-gateways'   => ['label' => 'Gateways',   'icon' => $tabIcons['gateways']],
    'payment-orchestrator-reports'    => ['label' => 'Reports',    'icon' => $tabIcons['reports']],
    'payment-orchestrator-scorecards' => ['label' => 'Scorecards', 'icon' => $tabIcons['scorecards']],
    'payment-orchestrator-recovery'   => ['label' => 'Recovery',   'icon' => $tabIcons['recovery']],
    'payment-orchestrator-settings'   => ['label' => 'Settings',   'icon' => $tabIcons['settings']],
    'payment-orchestrator-help'       => ['label' => 'Help',       'icon' => $tabIcons['help']],
];
?>

<!-- POP Unified Header -->
<div class="po-header-bar">
    <div style="display: flex; align-items: center; gap: 14px;">
        <div style="background: rgba(255,255,255,0.1); border-radius: 10px; padding: 10px; display: flex; align-items: center; justify-content: center;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color: #e2e8f0;">
                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
            </svg>
        </div>
        <div>
            <div style="font-size: 20px; font-weight: 700; letter-spacing: -0.3px;">Payment Orchestrator</div>
            <div style="font-size: 12px; color: #94a3b8;">v<?php echo esc_html(PO_VERSION); ?></div>
        </div>
    </div>
    <div style="display: flex; align-items: center; gap: 16px;">
        <?php if ($orchestratorEnabled): ?>
            <span style="display: inline-flex; align-items: center; gap: 6px; background: rgba(34,197,94,0.15); color: #4ade80; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;">
                <span style="width: 8px; height: 8px; background: #4ade80; border-radius: 50%; display: inline-block;"></span> Active
            </span>
        <?php else: ?>
            <span style="display: inline-flex; align-items: center; gap: 6px; background: rgba(239,68,68,0.15); color: #f87171; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600;">
                <span style="width: 8px; height: 8px; background: #f87171; border-radius: 50%; display: inline-block;"></span> Paused
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Tab Navigation -->
<div style="background: #fff; border: 1px solid #e2e8f0; border-top: none; border-radius: 0 0 12px 12px; padding: 0 12px; margin-bottom: 20px; display: flex; align-items: center; gap: 0; overflow-x: auto;">
    <?php foreach ($tabs as $slug => $tab): 
        $isActive = ($slug === $currentPage);
        $url = admin_url('admin.php?page=' . $slug);
    ?>
        <?php if ($isActive): ?>
            <a href="<?php echo esc_url($url); ?>" class="po-tab-link" style="display: inline-flex; align-items: center; gap: 7px; padding: 14px 18px; font-size: 13px; font-weight: 600; color: #1e293b; background: #f1f5f9; border-radius: 8px; margin: 6px 2px; text-decoration: none; white-space: nowrap;">
                <span style="display: inline-flex; color: #334155;"><?php echo $tab['icon']; ?></span>
                <span class="po-tab-label"><?php echo esc_html($tab['label']); ?></span>
            </a>
        <?php else: ?>
            <a href="<?php echo esc_url($url); ?>" class="po-tab-link" style="display: inline-flex; align-items: center; gap: 7px; padding: 14px 18px; font-size: 13px; font-weight: 500; color: #64748b; text-decoration: none; border-radius: 8px; margin: 6px 2px; white-space: nowrap; transition: all 0.15s;"
               onmouseenter="this.style.color='#334155';this.style.background='#f8fafc'" onmouseleave="this.style.color='#64748b';this.style.background='transparent'">
                <span style="display: inline-flex; color: #94a3b8;"><?php echo $tab['icon']; ?></span>
                <span class="po-tab-label"><?php echo esc_html($tab['label']); ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
    
    <!-- Spacer -->
    <div style="flex: 1;"></div>
    
    <!-- Logs button (right-aligned) -->
    <?php
    $logsActive = ($currentPage === 'payment-orchestrator-logs');
    $logsUrl = admin_url('admin.php?page=payment-orchestrator-logs');
    $logsStyle = $logsActive
        ? 'display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; font-size: 12px; font-weight: 600; color: #1e293b; background: #f1f5f9; border-radius: 8px; margin: 6px 4px; text-decoration: none; white-space: nowrap;'
        : 'display: inline-flex; align-items: center; gap: 6px; padding: 10px 14px; font-size: 12px; font-weight: 500; color: #94a3b8; text-decoration: none; border-radius: 8px; margin: 6px 4px; white-space: nowrap; transition: all 0.15s;';
    ?>
    <a href="<?php echo esc_url($logsUrl); ?>" style="<?php echo $logsStyle; ?>" title="Debug Logs"
       <?php if (!$logsActive): ?>onmouseenter="this.style.color='#64748b';this.style.background='#f8fafc'" onmouseleave="this.style.color='#94a3b8';this.style.background='transparent'"<?php endif; ?>>
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
        Logs
    </a>
</div>
