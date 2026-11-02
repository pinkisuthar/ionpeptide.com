<?php
/**
 * Shared admin navigation renderer.
 *
 * All 4 pages share the same header with icon-based nav tabs.
 * Only one POP submenu entry ("Email Recovery") - internal navigation via tabs.
 *
 * @package POPRecoveryEmail\Admin
 */

declare(strict_types=1);

namespace POPRecoveryEmail\Admin;

defined('ABSPATH') || exit;

final class AdminNav {

    /**
     * Render the shared header + nav tabs.
     *
     * @param string $activeTab  Current tab key.
     */
    public static function render(string $activeTab = 'dashboard'): void {
        $enabled = get_option('prer_enabled', 'yes') === 'yes';

        $tabs = [
            'dashboard' => ['label' => 'Dashboard',  'icon' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>'],
            'settings'  => ['label' => 'Settings',   'icon' => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>'],
            'templates' => ['label' => 'Templates',  'icon' => '<path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5L14.5 2z"/><polyline points="14 2 14 8 20 8"/>'],
            'reports'   => ['label' => 'Reports',    'icon' => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>'],
            'help'      => ['label' => 'Help',       'icon' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>'],
        ];
        ?>
        <div class="prer-header">
            <div class="prer-header-left">
                <div class="prer-logo">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                </div>
                <div>
                    <h1 class="prer-title">Email Recovery</h1>
                    <span class="prer-version">v<?php echo esc_html(PRER_VERSION); ?></span>
                </div>
            </div>
            <div class="prer-header-right">
                <span class="prer-status-pill <?php echo $enabled ? 'pill-active' : 'pill-paused'; ?>">
                    <span class="prer-pill-dot"></span>
                    <?php echo $enabled ? 'Active' : 'Paused'; ?>
                </span>
            </div>
        </div>

        <div class="prer-nav-bar">
            <?php foreach ($tabs as $key => $tab):
                $url = admin_url('admin.php?page=pop-email-recovery&tab=' . $key);
                $isActive = ($activeTab === $key);
            ?>
            <a href="<?php echo esc_url($url); ?>" class="prer-nav-tab <?php echo $isActive ? 'active' : ''; ?>">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><?php echo $tab['icon']; ?></svg>
                <span><?php echo esc_html($tab['label']); ?></span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
