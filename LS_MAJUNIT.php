<?php
/**
 * Plugin Name: Lightspeed Major Unit Inventory Sync
 * Description: A plugin to pull major unit inventory data from Lightspeed and create products in WordPress WooCommerce.
 * Version: 1.3.0
 * Author: ShockerJoe
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

class Lightspeed_Inventory_Sync {
    private $api_url = 'https://int.Lightspeeddataservices.com/lsapi/unit/76214633';
    private $options;

    public function __construct() {
        $this->options = get_option('lightspeed_inventory_sync_options');
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'settings_init'));
        add_action('lightspeed_inventory_sync_event', array($this, 'fetch_and_create_products'));
    }

    public function add_admin_menu() {
        add_options_page('Lightspeed Inventory Sync', 'Lightspeed Inventory Sync', 'manage_options', 'lightspeed_inventory_sync', array($this, 'options_page'));
    }

    public function settings_init() {
        register_setting('lightspeed_inventory_sync', 'lightspeed_inventory_sync_options');

        add_settings_section(
            'lightspeed_inventory_sync_section',
            __('Settings', 'lightspeed_inventory_sync'),
            null,
            'lightspeed_inventory_sync'
        );

        add_settings_field(
            'lightspeed_api_username',
            __('API Username', 'lightspeed_inventory_sync'),
            array($this, 'api_username_render'),
            'lightspeed_inventory_sync',
            'lightspeed_inventory_sync_section'
        );

        add_settings_field(
            'lightspeed_api_password',
            __('API Password', 'lightspeed_inventory_sync'),
            array($this, 'api_password_render'),
            'lightspeed_inventory_sync',
            'lightspeed_inventory_sync_section'
        );

        add_settings_field(
            'fetch_interval',
            __('Data Fetch Interval (in hours)', 'lightspeed_inventory_sync'),
            array($this, 'fetch_interval_render'),
            'lightspeed_inventory_sync',
            'lightspeed_inventory_sync_section'
        );

        add_settings_field(
            'enable_debug',
            __('Enable Debugging', 'lightspeed_inventory_sync'),
            array($this, 'enable_debug_render'),
            'lightspeed_inventory_sync',
            'lightspeed_inventory_sync_section'
        );
    }

    public function api_username_render() {
        printf('<input type="text" name="lightspeed_inventory_sync_options[api_username]" value="%s">', esc_attr($this->options['api_username']));
    }

    public function api_password_render() {
        printf('<input type="password" name="lightspeed_inventory_sync_options[api_password]" value="%s">', esc_attr($this->options['api_password']));
    }

    public function fetch_interval_render() {
        printf('<input type="number" name="lightspeed_inventory_sync_options[fetch_interval]" value="%s" min="1">', esc_attr($this->options['fetch_interval']));
    }

    public function enable_debug_render() {
        $checked = isset($this->options['enable_debug']) ? 'checked' : '';
        printf('<input type="checkbox" name="lightspeed_inventory_sync_options[enable_debug]" %s>', $checked);
    }

    public function options_page() {
        echo '<form action="options.php" method="post">';
        settings_fields('lightspeed_inventory_sync');
        do_settings_sections('lightspeed_inventory_sync');
        submit_button();
        echo '</form>';

        // Add Force Sync Button
        echo '<h2>Force Sync</h2>';
        echo '<form method="post" action="">';
        submit_button('Force Sync Now', 'primary', 'force_sync_now');
        echo '</form>';

        // Display Debug Information
        if ($this->options['enable_debug']) {
            echo '<h2>Debug Information</h2>';
            $debug_log = get_option('lightspeed_inventory_sync_debug_log', 'No debug information available.');
            echo '<pre>' . esc_html($debug_log) . '</pre>';
        }

        // Handle Force Sync
        if (isset($_POST['force_sync_now'])) {
            $this->fetch_and_create_products();
            echo '<div class="updated"><p>Force sync completed successfully.</p></div>';
        }
    }

    public function schedule_event() {
        if (!wp_next_scheduled('lightspeed_inventory_sync_event')) {
            $interval = isset($this->options['fetch_interval']) ? intval($this->options['fetch_interval']) * HOUR_IN_SECONDS : HOUR_IN_SECONDS;
            wp_schedule_event(time(), $interval, 'lightspeed_inventory_sync_event');
        }
    }

    public function fetch_and_create_products() {
        $username = $this->options['api_username'];
        $password = $this->options['api_password'];
        $endpoint = '/Unit';

        if ($username && $password) {
            if ($this->options['enable_debug']) {
                $this->log_debug('Lightspeed Inventory Sync Started');
            }
            $url = $this->api_url . $endpoint;
            if ($this->options['enable_debug']) {
                $this->log_debug('Lightspeed Inventory Sync URL: ' . $url);
                $this->log_debug('Lightspeed Inventory Sync Username: ' . $username);
                $this->log_debug('Lightspeed Inventory Sync Password length: ' . strlen($password));
            }

            $args = array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
                    'Accept' => 'application/json'
                ),
                'httpversion' => '1.1',
                'timeout' => 45,
                'sslverify' => false,
            );

            $response = wp_remote_get($url, $args);

            if (is_wp_error($response)) {
                if ($this->options['enable_debug']) {
                    $this->log_debug('Lightspeed Inventory Sync Error: ' . $response->get_error_message());
                }
                return;
            }

            // Log the response code
            $response_code = wp_remote_retrieve_response_code($response);
            if ($this->options['enable_debug']) {
                $this->log_debug('Lightspeed Inventory Sync Response Code: ' . $response_code);
            }

            // Retrieve the response body
            $body = wp_remote_retrieve_body($response);

            // Log the raw response body
            if ($this->options['enable_debug']) {
                $this->log_debug('Lightspeed Inventory Sync Raw Response: ' . $body);
            }

            $data = json_decode($body, true);

            // Check if the data is valid and non-empty
            if (!is_array($data) || empty($data)) {
                if ($this->options['enable_debug']) {
                    $this->log_debug('No valid data returned from the API or data is empty.');
                }
                return;
            }

            if ($this->options['enable_debug']) {
                $this->log_debug('Lightspeed Inventory Sync Data: ' . print_r($data, true));
            }

            if (is_array($data)) {
                foreach ($data as $unit) {
                    if (isset($unit['Make'], $unit['Model'], $unit['StockNumber'], $unit['WebPrice'])) {
                        $product_data = array(
                            'post_title' => $unit['Make'] . ' ' . $unit['Model'],
                            'post_content' => isset($unit['CodeName']) ? $unit['CodeName'] : '',
                            'post_status' => 'publish',
                            'post_type' => 'product',
                        );

                        $product_id = wp_insert_post($product_data);

                        if ($product_id) {
                            update_post_meta($product_id, '_sku', $unit['StockNumber']);
                            update_post_meta($product_id, '_regular_price', $unit['WebPrice']);
                            update_post_meta($product_id, '_stock', isset($unit['OnHold']) && $unit['OnHold'] === '' ? 1 : 0);
                            update_post_meta($product_id, '_vin', $unit['VIN']);
                            update_post_meta($product_id, '_model_year', $unit['ModelYear']);
                            update_post_meta($product_id, '_condition', $unit['Condition']);
                            update_post_meta($product_id, '_color', $unit['Color']);
                            update_post_meta($product_id, '_length', $unit['Length']);
                            update_post_meta($product_id, '_width', $unit['Width']);
                            wp_set_object_terms($product_id, 'simple', 'product_type');
                        } else {
                            if ($this->options['enable_debug']) {
                                $this->log_debug('Failed to create product for Unit: ' . print_r($unit, true));
                            }
                        }
                    } else {
                        if ($this->options['enable_debug']) {
                            $this->log_debug('Missing required data for Unit: ' . print_r($unit, true));
                        }
                    }
                }
            }
        } else {
            if ($this->options['enable_debug']) {
                $this->log_debug('API credentials are missing. Please provide both username and password.');
            }
        }
    }

    private function log_debug($message) {
        $existing_log = get_option('lightspeed_inventory_sync_debug_log', '');
        $new_log = $existing_log . "\n" . date('Y-m-d H:i:s') . ' - ' . $message;
        update_option('lightspeed_inventory_sync_debug_log', $new_log);
    }
}

if (is_admin()) {
    $lightspeed_inventory_sync = new Lightspeed_Inventory_Sync();
}

register_activation_hook(__FILE__, function () {
    (new Lightspeed_Inventory_Sync())->schedule_event();
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('lightspeed_inventory_sync_event');
    delete_option('lightspeed_inventory_sync_debug_log');
});
