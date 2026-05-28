<?php
/**
 * Admin settings page template — tabbed layout.
 *
 * @package Woo_Odoo_Connector
 */

defined('ABSPATH') || exit;

$settings    = get_option('woo_odoo_connector_settings', []);
$log         = array_reverse(get_option('woo_odoo_sync_log', []));
$queue_stats = (class_exists('Woo_Odoo_Queue') && function_exists('as_get_scheduled_actions'))
    ? Woo_Odoo_Queue::get_stats()
    : ['pending' => 0, 'running' => 0];
$audit       = array_reverse(get_option('woo_odoo_audit_log', []));

// Active tab — default to 'connection'.
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$active_tab  = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connection';
$page_url    = admin_url('admin.php?page=woo-odoo-settings');

$tabs = [
    'connection' => __('Connection',        'erplinker-odoosync'),
    'sync'       => __('Sync Options',      'erplinker-odoosync'),
    'features'   => __('Advanced Features', 'erplinker-odoosync-lite'),
    'advsync'    => __('Advanced Sync',     'erplinker-odoosync'),
    'notify'     => __('Notifications',     'erplinker-odoosync'),
    'tools'      => __('Tools & Queue',     'erplinker-odoosync'),
    'logs'       => __('Logs',              'erplinker-odoosync'),
];
?>

<div class="wrap woo-odoo-wrap">
    <div class="woo-odoo-header">
        <h1><?php esc_html_e('OdooSync for WooCommerce \u2013 Settings', 'erplinker-odoosync-lite'); ?></h1>
        <div>
            <span id="woo-odoo-connection-status" class="woo-odoo-header-badge woo-odoo-status-pending"
                  title="<?php esc_attr_e('Checking...', 'erplinker-odoosync-lite'); ?>">
                <?php esc_html_e('Checking connection...', 'erplinker-odoosync-lite'); ?>
            </span>
        </div>
    </div>

    <!-- Tab navigation -->
    <nav class="nav-tab-wrapper woo-odoo-nav-tabs" style="margin-bottom:20px">
        <?php foreach ($tabs as $slug => $label) : ?>
            <a href="<?php echo esc_url(add_query_arg('tab', $slug, $page_url)); ?>"
               class="nav-tab <?php echo $active_tab === $slug ? 'nav-tab-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php /* ================================================================
           TAB: CONNECTION
           ================================================================ */ ?>
    <?php if ($active_tab === 'connection') : ?>

        <div class="woo-odoo-stats" style="margin-bottom:20px">
            <div class="woo-odoo-stat-card">
                <div class="woo-odoo-stat-value"><?php echo esc_html($queue_stats['pending'] ?? 0); ?></div>
                <div class="woo-odoo-stat-label"><?php esc_html_e('Queue Pending', 'erplinker-odoosync-lite'); ?></div>
            </div>
            <div class="woo-odoo-stat-card">
                <div class="woo-odoo-stat-value"><?php echo esc_html($queue_stats['running'] ?? 0); ?></div>
                <div class="woo-odoo-stat-label"><?php esc_html_e('Queue Running', 'erplinker-odoosync-lite'); ?></div>
            </div>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-status&tab=action-scheduler')); ?>" class="woo-odoo-stat-card woo-odoo-stat-link">
                <div class="woo-odoo-stat-value"><?php esc_html_e('Action Scheduler', 'erplinker-odoosync-lite'); ?></div>
                <div class="woo-odoo-stat-label"><?php esc_html_e('View queue →', 'erplinker-odoosync-lite'); ?></div>
            </a>
        </div>

        <div class="woo-odoo-grid">
            <div>
                <form method="post" action="options.php" id="woo-odoo-settings-form">
                    <?php settings_fields('woo_odoo_connector'); ?>
                    <div class="woo-odoo-card">
                        <h2 class="woo-odoo-card-title"><?php esc_html_e('Odoo Connection', 'erplinker-odoosync-lite'); ?></h2>
                        <p class="woo-odoo-card-desc"><?php esc_html_e('Enter your Odoo instance details.', 'erplinker-odoosync-lite'); ?></p>
                        <?php do_settings_sections_for('woo_odoo_connection'); ?>
                        <?php do_settings_sections_for('woo_odoo_api_auth'); ?>
                        <?php do_settings_sections_for('woo_odoo_accounting'); ?>
                        <?php submit_button(__('Save Connection Settings', 'erplinker-odoosync-lite'), 'primary', 'submit', false); ?>
                    </div>
                </form>
            </div>
            <div>
                <div class="woo-odoo-card">
                    <h2 class="woo-odoo-card-title"><?php esc_html_e('Test Connection', 'erplinker-odoosync-lite'); ?></h2>
                    <p class="woo-odoo-card-desc"><?php esc_html_e('Verify your Odoo connection before syncing.', 'erplinker-odoosync-lite'); ?></p>
                    <div class="woo-odoo-btn-group">
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-test-connection">
                            <?php esc_html_e('Test Connection', 'erplinker-odoosync-lite'); ?>
                        </button>
                    </div>
                    <div id="woo-odoo-test-result" class="woo-odoo-result" style="display:none;"></div>
                </div>
                <div class="woo-odoo-card">
                    <h2 class="woo-odoo-card-title"><?php esc_html_e('Additional Instances', 'erplinker-odoosync-lite'); ?></h2>
                    <p class="woo-odoo-card-desc"><?php esc_html_e('Add more Odoo connections.', 'erplinker-odoosync-lite'); ?></p>
                    <div id="woo-odoo-add-instance" class="woo-odoo-add-instance">
                        <input type="text" id="woo-odoo-inst-name" placeholder="<?php esc_attr_e('Instance name', 'erplinker-odoosync-lite'); ?>" />
                        <input type="url" id="woo-odoo-inst-url" placeholder="<?php esc_attr_e('Odoo URL', 'erplinker-odoosync-lite'); ?>" />
                        <input type="text" id="woo-odoo-inst-db" placeholder="<?php esc_attr_e('Database', 'erplinker-odoosync-lite'); ?>" />
                        <input type="text" id="woo-odoo-inst-user" placeholder="<?php esc_attr_e('Username', 'erplinker-odoosync-lite'); ?>" />
                        <input type="password" id="woo-odoo-inst-pass" placeholder="<?php esc_attr_e('Password', 'erplinker-odoosync-lite'); ?>" />
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-inst-add-btn"><?php esc_html_e('Add Instance', 'erplinker-odoosync-lite'); ?></button>
                    </div>
                    <div id="woo-odoo-instances-list"></div>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <?php /* ================================================================
           TAB: SYNC OPTIONS
           ================================================================ */ ?>
    <?php if ($active_tab === 'sync') : ?>

        <form method="post" action="options.php" id="woo-odoo-settings-form">
            <?php settings_fields('woo_odoo_connector'); ?>
            <div class="woo-odoo-card" style="max-width:800px">
                <h2 class="woo-odoo-card-title"><?php esc_html_e('Sync Options', 'erplinker-odoosync-lite'); ?></h2>
                <?php do_settings_sections_for('woo_odoo_sync'); ?>
                <?php do_settings_sections_for('woo_odoo_warehouse_stock'); ?>
                <?php submit_button(__('Save Sync Settings', 'erplinker-odoosync-lite'), 'primary', 'submit', false); ?>
            </div>
        </form>

        <div class="woo-odoo-card" style="max-width:800px">
            <h2 class="woo-odoo-card-title"><?php esc_html_e('Manual Sync', 'erplinker-odoosync-lite'); ?></h2>
            <div class="woo-odoo-btn-group">
                <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-sync-products"><?php esc_html_e('Sync Products WC → Odoo', 'erplinker-odoosync-lite'); ?></button>
                <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-import-products"><?php esc_html_e('Import Products Odoo → WC', 'erplinker-odoosync-lite'); ?></button>
                <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-sync-customers"><?php esc_html_e('Sync Customers', 'erplinker-odoosync-lite'); ?></button>
                <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-sync-orders"><?php esc_html_e('Sync Orders', 'erplinker-odoosync-lite'); ?></button>
                <button type="button" class="woo-odoo-btn woo-odoo-btn-secondary" id="woo-odoo-sync-stock"><?php esc_html_e('Pull Stock from Odoo', 'erplinker-odoosync-lite'); ?></button>
            </div>
            <div id="woo-odoo-sync-result" class="woo-odoo-result" style="display:none;margin-top:12px"></div>
        </div>

    <?php endif; ?>

    <?php /* ================================================================
           TAB: ADVANCED FEATURES
           ================================================================ */ ?>
    <?php if ($active_tab === 'features') : ?>

        <div class="notice notice-info inline" style="margin:0 0 20px">
            <p>
                <?php esc_html_e( 'Enable the features you need. Each feature adds its own submenu page in the left navigation. Save, then reload the page to see the new menu items.', 'erplinker-odoosync' ); ?>
            </p>
        </div>

        <form method="post" action="options.php" id="woo-odoo-settings-form">
            <?php settings_fields('woo_odoo_connector'); ?>
            <div class="woo-odoo-card" style="max-width:800px">
                <h2 class="woo-odoo-card-title"><?php esc_html_e('Advanced Features', 'erplinker-odoosync-lite'); ?></h2>
                <p class="woo-odoo-card-desc"><?php esc_html_e('Toggle optional modules. Each feature loads only when enabled to keep the plugin lean.', 'erplinker-odoosync-lite'); ?></p>

                <table class="form-table" role="presentation">
                    <?php
                    // Render the advanced-features section fields directly.
                    global $wp_settings_fields;
                    $section_fields = $wp_settings_fields['erplinker-odoosync']['woo_odoo_advanced_features'] ?? [];
                    foreach ($section_fields as $field) {
                        echo '<tr>';
                        echo '<th scope="row"><label for="' . esc_attr($field['id']) . '">' . wp_kses_post($field['title']) . '</label></th>';
                        echo '<td>';
                        call_user_func($field['callback'], $field['args']);
                        echo '</td>';
                        echo '</tr>';
                    }
                    ?>
                </table>

                <?php submit_button(__('Save Feature Settings', 'erplinker-odoosync-lite'), 'primary', 'submit', false); ?>
            </div>
        </form>

        <!-- Feature status overview -->
        <div class="woo-odoo-card" style="max-width:800px;margin-top:20px">
            <h2 class="woo-odoo-card-title"><?php esc_html_e('Feature Status', 'erplinker-odoosync-lite'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Feature', 'erplinker-odoosync-lite'); ?></th>
                        <th><?php esc_html_e('Status', 'erplinker-odoosync-lite'); ?></th>
                        <th><?php esc_html_e('Admin Page', 'erplinker-odoosync-lite'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $feature_map = [
                        'feature_ai_optimizer'   => [ 'label' => __('AI Optimizer',        'erplinker-odoosync'), 'page' => 'woo-odoo-optimizer' ],
                        'feature_analytics'      => [ 'label' => __('Analytics',           'erplinker-odoosync'), 'page' => 'woo-odoo-analytics' ],
                        'feature_b2b'            => [ 'label' => __('B2B / Pricelists',    'erplinker-odoosync'), 'page' => 'woo-odoo-b2b' ],
                        'feature_graphql'        => [ 'label' => __('GraphQL API',         'erplinker-odoosync'), 'page' => null ],
                        'feature_multi_currency' => [ 'label' => __('Multi-Currency',      'erplinker-odoosync'), 'page' => null ],
                        'feature_realtime'       => [ 'label' => __('Real-Time Sync',      'erplinker-odoosync'), 'page' => null ],
                        'feature_trust_proxy'    => [ 'label' => __('Trust Proxy Headers', 'erplinker-odoosync-lite'), 'page' => null ],
                    ];
                    foreach ($feature_map as $key => $info) :
                        $enabled = !empty($settings[$key]);
                    ?>
                        <tr>
                            <td><?php echo esc_html($info['label']); ?></td>
                            <td>
                                <?php if ($enabled) : ?>
                                    <span style="color:#00a32a;font-weight:600">&#9679; <?php esc_html_e('Enabled', 'erplinker-odoosync-lite'); ?></span>
                                <?php else : ?>
                                    <span style="color:#888">&#9675; <?php esc_html_e('Disabled', 'erplinker-odoosync-lite'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($enabled && $info['page']) : ?>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . $info['page'])); ?>">
                                        <?php esc_html_e('Open →', 'erplinker-odoosync-lite'); ?>
                                    </a>
                                <?php elseif (!$enabled) : ?>
                                    <em style="color:#999"><?php esc_html_e('Enable to access', 'erplinker-odoosync-lite'); ?></em>
                                <?php else : ?>
                                    <em style="color:#999"><?php esc_html_e('No dedicated page', 'erplinker-odoosync-lite'); ?></em>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

    <?php endif; ?>

    <?php /* ================================================================
           TAB: ADVANCED SYNC
           ================================================================ */ ?>
    <?php if ($active_tab === 'advsync') : ?>

        <form method="post" action="options.php" id="woo-odoo-settings-form">
            <?php settings_fields('woo_odoo_connector'); ?>
            <?php include WOO_ODOO_CONNECTOR_PLUGIN_DIR . 'admin/views/settings-advanced-sync.php'; ?>
            <?php submit_button(__('Save Advanced Sync Settings', 'erplinker-odoosync-lite'), 'primary', 'submit', true); ?>
        </form>

    <?php endif; ?>

    <?php /* ================================================================
           TAB: NOTIFICATIONS
           ================================================================ */ ?>
    <?php if ($active_tab === 'notify') : ?>

        <form method="post" action="options.php" id="woo-odoo-settings-form">
            <?php settings_fields('woo_odoo_connector'); ?>
            <div class="woo-odoo-card" style="max-width:700px">
                <h2 class="woo-odoo-card-title"><?php esc_html_e('Failure Notifications', 'erplinker-odoosync-lite'); ?></h2>
                <?php do_settings_sections_for('woo_odoo_notifications'); ?>
                <?php submit_button(__('Save Notification Settings', 'erplinker-odoosync-lite'), 'primary', 'submit', false); ?>
            </div>
        </form>

    <?php endif; ?>

    <?php /* ================================================================
           TAB: TOOLS & QUEUE
           ================================================================ */ ?>
    <?php if ($active_tab === 'tools') : ?>

        <div class="woo-odoo-grid">
            <div>
                <div class="woo-odoo-card">
                    <h2 class="woo-odoo-card-title"><?php esc_html_e('Queue Jobs (Action Scheduler)', 'erplinker-odoosync-lite'); ?></h2>
                    <p class="woo-odoo-card-desc"><?php esc_html_e('Enqueue bulk sync for large catalogs. Processes in batches of 50.', 'erplinker-odoosync-lite'); ?></p>
                    <div class="woo-odoo-form-row" style="margin-bottom:12px">
                        <label><?php esc_html_e('Date range (Orders/Customers)', 'erplinker-odoosync-lite'); ?></label><br>
                        <input type="date" id="woo-odoo-date-from" value="<?php echo esc_attr(gmdate('Y-m-d', strtotime('-30 days'))); ?>" />
                        <span style="margin:0 8px"><?php esc_html_e('to', 'erplinker-odoosync-lite'); ?></span>
                        <input type="date" id="woo-odoo-date-to" value="<?php echo esc_attr(gmdate('Y-m-d')); ?>" />
                    </div>
                    <div class="woo-odoo-btn-group">
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-queue-products"><?php esc_html_e('Queue Products', 'erplinker-odoosync-lite'); ?></button>
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-queue-customers"><?php esc_html_e('Queue Customers', 'erplinker-odoosync-lite'); ?></button>
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-queue-orders"><?php esc_html_e('Queue Orders', 'erplinker-odoosync-lite'); ?></button>
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-queue-stock"><?php esc_html_e('Queue Stock', 'erplinker-odoosync-lite'); ?></button>
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-secondary" id="woo-odoo-queue-cancel"><?php esc_html_e('Cancel Queue', 'erplinker-odoosync-lite'); ?></button>
                    </div>
                    <p id="woo-odoo-queue-stats" class="woo-odoo-progress-text"></p>
                    <div id="woo-odoo-queue-progress" class="woo-odoo-progress-wrap">
                        <div class="woo-odoo-progress-bar"><div id="woo-odoo-progress-bar" class="woo-odoo-progress-fill"></div></div>
                        <p id="woo-odoo-progress-text" class="woo-odoo-progress-text"></p>
                    </div>
                    <div id="woo-odoo-queue-result" class="woo-odoo-result" style="display:none;"></div>
                </div>

                <div class="woo-odoo-card">
                    <h2 class="woo-odoo-card-title"><?php esc_html_e('Retry Queue', 'erplinker-odoosync-lite'); ?></h2>
                    <p class="woo-odoo-card-desc"><?php esc_html_e('Failed syncs queued with exponential backoff (max 5 retries).', 'erplinker-odoosync-lite'); ?></p>
                    <div class="woo-odoo-btn-group">
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-primary" id="woo-odoo-retry-process"><?php esc_html_e('Process Retries Now', 'erplinker-odoosync-lite'); ?></button>
                        <button type="button" class="woo-odoo-btn woo-odoo-btn-secondary" id="woo-odoo-retry-clear"><?php esc_html_e('Clear Retry Queue', 'erplinker-odoosync-lite'); ?></button>
                    </div>
                    <div id="woo-odoo-retry-list" class="woo-odoo-retry-empty" style="margin-top:16px;"></div>
                </div>
            </div>
            <div>
                <div class="woo-odoo-card">
                    <h2 class="woo-odoo-card-title"><?php esc_html_e('Scheduled Sync', 'erplinker-odoosync-lite'); ?></h2>
                    <?php
                    $freqs = Woo_Odoo_Options::get_cron_frequencies();
                    $opts  = get_option('woo_odoo_connector_settings', []);
                    ?>
                    <ul class="woo-odoo-schedule-list">
                        <li><span class="woo-odoo-schedule-dot"></span> <?php echo esc_html(sprintf(__('Products: %s',  'erplinker-odoosync'), $freqs[$opts['cron_products_freq']  ?? 'hourly']           ?? 'hourly')); ?></li>
                        <li><span class="woo-odoo-schedule-dot"></span> <?php echo esc_html(sprintf(__('Customers: %s', 'erplinker-odoosync-lite'), $freqs[$opts['cron_customers_freq'] ?? 'hourly']           ?? 'hourly')); ?></li>
                        <li><span class="woo-odoo-schedule-dot"></span> <?php echo esc_html(sprintf(__('Orders: %s',   'erplinker-odoosync'), $freqs[$opts['cron_orders_freq']   ?? 'fifteen_minutes']  ?? 'every 15 min')); ?></li>
                        <li><span class="woo-odoo-schedule-dot"></span> <?php echo esc_html(sprintf(__('Stock: %s',    'erplinker-odoosync'), $freqs[$opts['cron_stock_freq']    ?? 'thirty_minutes']   ?? 'every 30 min')); ?></li>
                    </ul>
                </div>
            </div>
        </div>

    <?php endif; ?>

    <?php /* ================================================================
           TAB: LOGS
           ================================================================ */ ?>
    <?php if ($active_tab === 'logs') : ?>

        <div class="woo-odoo-card">
            <h2 class="woo-odoo-card-title"><?php esc_html_e('Sync Log', 'erplinker-odoosync-lite'); ?></h2>
            <p class="woo-odoo-card-desc"><?php esc_html_e('Recent sync activity. Enable logging in Sync Options.', 'erplinker-odoosync-lite'); ?></p>
            <div class="woo-odoo-log-panel">
                <?php if (empty($log)) : ?>
                    <div class="woo-odoo-log-empty"><?php esc_html_e('No log entries yet.', 'erplinker-odoosync-lite'); ?></div>
                <?php else : ?>
                    <?php foreach (array_slice($log, 0, 100) as $entry) : ?>
                        <div class="woo-odoo-log-entry <?php echo ($entry['level'] ?? '') === 'error' ? 'error' : (($entry['level'] ?? '') === 'warning' ? 'warning' : ''); ?>">
                            [<?php echo esc_html($entry['time'] ?? ''); ?>] <?php echo esc_html($entry['message'] ?? ''); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($audit)) : ?>
        <div class="woo-odoo-card">
            <h2 class="woo-odoo-card-title"><?php esc_html_e('Audit Trail', 'erplinker-odoosync-lite'); ?></h2>
            <div class="woo-odoo-log-panel">
                <?php foreach (array_slice($audit, 0, 50) as $entry) : ?>
                    <div class="woo-odoo-log-entry">
                        [<?php echo esc_html($entry['time'] ?? ''); ?>] <?php echo esc_html(($entry['entity'] ?? '') . ' #' . ($entry['entity_id'] ?? '')); ?> — <?php echo esc_html($entry['direction'] ?? ''); ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>

</div>

<?php
/**
 * Render only the fields for a specific settings section without the section title/description.
 * This avoids calling do_settings_sections() which renders ALL sections at once.
 */
function do_settings_sections_for( string $section_id ): void {
    global $wp_settings_fields;
    $fields = $wp_settings_fields['erplinker-odoosync'][ $section_id ] ?? [];
    if ( empty( $fields ) ) {
        return;
    }
    echo '<table class="form-table" role="presentation"><tbody>';
    foreach ( $fields as $field ) {
        echo '<tr>';
        if ( ! empty( $field['args']['label_for'] ) ) {
            echo '<th scope="row"><label for="' . esc_attr( $field['args']['label_for'] ) . '">' . wp_kses_post( $field['title'] ) . '</label></th>';
        } else {
            echo '<th scope="row">' . wp_kses_post( $field['title'] ) . '</th>';
        }
        echo '<td>';
        call_user_func( $field['callback'], $field['args'] );
        echo '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
