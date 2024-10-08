<?php
/*
Plugin Name: Firebase Messaging FCM HTTP V1
Description: Sends notifications to React Native apps via Firebase FCM HTTP v1, manages subscribers, and sends notifications via topics.
Version: 1.3
Author: Alberto Nasello
*/

if (!defined('ABSPATH')) exit;

// Include the Composer autoloader
require_once __DIR__ . '/vendor/autoload.php';

use Kreait\Firebase\Factory;
use Kreait\Firebase\Messaging\CloudMessage;

class FCM_Notifications_Plugin {

    private $table_name;
    private $languages = array('en_US' => 'English', 'fr_FR' => 'Français', 'es_ES' => 'Español');

    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'fcm_subscribers';

        // WordPress actions
        register_activation_hook(__FILE__, array($this, 'install'));
        add_action('admin_menu', array($this, 'create_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));

        // Meta box in the post editor
        add_action('add_meta_boxes', array($this, 'add_meta_box'));
        // Save meta box data
        add_action('save_post', array($this, 'save_post_meta'), 10, 2);
        // Send notification when post is saved
        add_action('save_post', array($this, 'send_notification_on_save'), 20, 2);
        // Action for scheduled notification
        add_action('fcm_send_scheduled_notification', array($this, 'send_scheduled_notification'), 10, 1);

        // REST API for registering tokens
        add_action('rest_api_init', array($this, 'register_api_routes'));

        // Load plugin text domain for translations
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }

    // Load text domain for translations
    public function load_textdomain() {
        load_plugin_textdomain('fcm_notifications', false, dirname(plugin_basename(__FILE__)) . '/languages/');
    }

    // Install the table to store tokens
    public function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            token VARCHAR(255) NOT NULL,
            device_type VARCHAR(50) DEFAULT NULL,
            device_uuid VARCHAR(255) NOT NULL,
            device_name VARCHAR(255) DEFAULT NULL,
            topic VARCHAR(255) DEFAULT NULL,
            other_data JSON DEFAULT NULL,
            subscribed TINYINT(1) DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY device_uuid (device_uuid)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    // Create admin menu
    public function create_admin_menu() {
        // Main page displays subscriber list
        add_menu_page(
            __('FCM Notifications', 'fcm_notifications'),
            __('FCM Notifications', 'fcm_notifications'),
            'manage_options',
            'fcm-subscribers',
            array($this, 'subscribers_page')
        );

        // Submenu for general settings
        add_submenu_page(
            'fcm-subscribers',
            __('General Settings', 'fcm_notifications'),
            __('General Settings', 'fcm_notifications'),
            'manage_options',
            'fcm-general-settings',
            array($this, 'general_settings_page')
        );

        // Submenu for FCM settings
        add_submenu_page(
            'fcm-subscribers',
            __('FCM Settings', 'fcm_notifications'),
            __('FCM Settings', 'fcm_notifications'),
            'manage_options',
            'fcm-settings',
            array($this, 'fcm_settings_page')
        );

        // Submenu for test notification
        add_submenu_page(
            'fcm-subscribers',
            __('Test Notification', 'fcm_notifications'),
            __('Test Notification', 'fcm_notifications'),
            'manage_options',
            'fcm-test-notification',
            array($this, 'test_notification_page')
        );

        // Submenu for tutorial
        add_submenu_page(
            'fcm-subscribers',
            __('Tutorial', 'fcm_notifications'),
            __('Tutorial', 'fcm_notifications'),
            'manage_options',
            'fcm-tutorial',
            array($this, 'tutorial_page')
        );
    }

    // Register settings
    public function register_settings() {
        register_setting('fcm_general_settings', 'fcm_allowed_post_types');
        register_setting('fcm_general_settings', 'fcm_checkbox_default_checked');
        register_setting('fcm_general_settings', 'fcm_timezone');
        register_setting('fcm_general_settings', 'fcm_language');

        register_setting('fcm_plugin_settings', 'fcm_service_account_json');
        register_setting('fcm_plugin_settings', 'fcm_project_id');
    }

    // General settings page
    public function general_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('General Settings', 'fcm_notifications'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('fcm_general_settings');
                do_settings_sections('fcm_general_settings');

                // Get current values
                $allowed_post_types = get_option('fcm_allowed_post_types', array());
                $checkbox_default_checked = get_option('fcm_checkbox_default_checked', 'no');
                $fcm_timezone = get_option('fcm_timezone', 'UTC');
                $fcm_language = get_option('fcm_language', 'en_US');
                ?>

                <h2><?php _e('Post Types', 'fcm_notifications'); ?></h2>
                <table class="form-table">
                    <?php
                    $post_types = get_post_types(array('public' => true), 'objects');
                    $exclude = array('attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request', 'wp_block');
                    foreach ($post_types as $post_type) {
                        if (in_array($post_type->name, $exclude)) continue;
                        ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($post_type->labels->name); ?></th>
                            <td>
                                <input type="checkbox" name="fcm_allowed_post_types[]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, $allowed_post_types)); ?> />
                            </td>
                        </tr>
                        <?php
                    }
                    ?>
                </table>

                <h2><?php _e('Notification Settings', 'fcm_notifications'); ?></h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Default Checkbox State', 'fcm_notifications'); ?></th>
                        <td>
                            <input type="checkbox" name="fcm_checkbox_default_checked" value="yes" <?php checked($checkbox_default_checked, 'yes'); ?> />
                            <?php _e('Pre-select the "Send notification via FCM on save" checkbox by default.', 'fcm_notifications'); ?>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Time Zone for Notifications', 'fcm_notifications'); ?></th>
                        <td>
                            <select name="fcm_timezone">
                                <?php
                                $timezones = timezone_identifiers_list();
                                foreach ($timezones as $timezone) {
                                    echo '<option value="' . esc_attr($timezone) . '" ' . selected($fcm_timezone, $timezone, false) . '>' . esc_html($timezone) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Language', 'fcm_notifications'); ?></th>
                        <td>
                            <select name="fcm_language">
                                <?php
                                foreach ($this->languages as $lang_code => $lang_name) {
                                    echo '<option value="' . esc_attr($lang_code) . '" ' . selected($fcm_language, $lang_code, false) . '>' . esc_html($lang_name) . '</option>';
                                }
                                ?>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // FCM settings page
    public function fcm_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('FCM Settings', 'fcm_notifications'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('fcm_plugin_settings');
                do_settings_sections('fcm_plugin_settings');

                // Get current values
                $fcm_service_account_json = get_option('fcm_service_account_json');
                $fcm_project_id = get_option('fcm_project_id');
                ?>

                <h2><?php _e('Firebase Cloud Messaging', 'fcm_notifications'); ?></h2>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Service Account JSON', 'fcm_notifications'); ?></th>
                        <td><textarea name="fcm_service_account_json" rows="10" cols="50"><?php echo esc_textarea($fcm_service_account_json); ?></textarea></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Project ID', 'fcm_notifications'); ?></th>
                        <td><input type="text" name="fcm_project_id" value="<?php echo esc_attr($fcm_project_id); ?>" size="50"/></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // Test notification page
    public function test_notification_page() {
        if(isset($_POST['fcm_test_notification'])) {
            $title = sanitize_text_field($_POST['fcm_test_title']);
            $body = sanitize_textarea_field($_POST['fcm_test_body']);

            // Pass null as $post_id and $post_type for tests
            $this->send_fcm_notification($title, $body, null, null);
            echo '<div class="updated"><p>' . __('Notification sent!', 'fcm_notifications') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php _e('Test FCM Notification', 'fcm_notifications'); ?></h1>
            <form method="post">
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row"><?php _e('Title', 'fcm_notifications'); ?></th>
                        <td><input type="text" name="fcm_test_title" size="50"/></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row"><?php _e('Message', 'fcm_notifications'); ?></th>
                        <td><textarea name="fcm_test_body" rows="5" cols="50"></textarea></td>
                    </tr>
                </table>
                <?php submit_button(__('Send Test Notification', 'fcm_notifications'), 'primary', 'fcm_test_notification'); ?>
            </form>
        </div>
        <?php
    }

    public function tutorial_page() {
      ?>
      <div class="wrap">
          <h1><?php _e('Tutorial', 'fcm_notifications'); ?></h1>
          <h2><?php _e('Introduction', 'fcm_notifications'); ?></h2>
          <p><?php _e('This plugin allows you to send notifications to your React Native applications via Firebase Cloud Messaging (FCM) HTTP v1 API.', 'fcm_notifications'); ?></p>

          <h2><?php _e('Setup Instructions', 'fcm_notifications'); ?></h2>
          <ol>
              <li><strong><?php _e('Firebase Setup', 'fcm_notifications'); ?></strong>
                  <ol>
                      <li><?php _e('Create a Firebase project at', 'fcm_notifications'); ?> <a href="https://console.firebase.google.com/">Firebase Console</a>.</li>
                      <li><?php _e('Navigate to <strong>Project Settings</strong> and select the <strong>Service Accounts</strong> tab.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Click on <strong>Generate New Private Key</strong> to download the JSON file.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Copy the contents of the JSON file.', 'fcm_notifications'); ?></li>
                  </ol>
              </li>
              <li><strong><?php _e('Plugin Configuration', 'fcm_notifications'); ?></strong>
                  <ol>
                      <li><?php _e('Go to the WordPress admin dashboard.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Navigate to <strong>FCM Notifications &raquo; FCM Settings</strong>.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Paste the JSON contents into the <strong>Service Account JSON</strong> field.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Enter your <strong>Firebase Project ID</strong>.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Save the settings.', 'fcm_notifications'); ?></li>
                  </ol>
              </li>
              <li><strong><?php _e('Integrate with React Native App', 'fcm_notifications'); ?></strong>
                  <ol>
                      <li><?php _e('Install the <code>@react-native-firebase/messaging</code> package.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Configure Firebase in your React Native app.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Implement code to register the device token and send it to the WordPress REST API endpoint <code>/wp-json/fcm/v1/subscribe</code>.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Handle incoming messages and notifications in your app.', 'fcm_notifications'); ?></li>
                      <li><?php _e('To unsubscribe, send a request to <code>/wp-json/fcm/v1/unsubscribe</code>.', 'fcm_notifications'); ?></li>
                  </ol>
              </li>
              <li><strong><?php _e('Sending Notifications', 'fcm_notifications'); ?></strong>
                  <ol>
                      <li><?php _e('When creating or updating a post of the selected types, you will see a meta box titled <strong>FCM Notification</strong>.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Check the option <strong>Send notification via FCM on save</strong> if you wish to send a notification.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Optionally, schedule the notification by selecting a date and time.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Publish or update the post.', 'fcm_notifications'); ?></li>
                  </ol>
              </li>
              <li><strong><?php _e('Testing Notifications', 'fcm_notifications'); ?></strong>
                  <ol>
                      <li><?php _e('Go to <strong>FCM Notifications &raquo; Test Notification</strong>.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Enter a title and message.', 'fcm_notifications'); ?></li>
                      <li><?php _e('Click <strong>Send Test Notification</strong> to send a test message to all subscribers.', 'fcm_notifications'); ?></li>
                  </ol>
              </li>
          </ol>

          <h2><?php _e('Additional Information', 'fcm_notifications'); ?></h2>
          <p><?php _e('For more details and support, please refer to the plugin documentation or contact the developer.', 'fcm_notifications'); ?></p>
      </div>
      <?php
  }

    // Subscribers page
    public function subscribers_page() {
        global $wpdb;

        // Handle subscriber deletion
        if(isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['subscriber'])) {
            $subscriber_id = intval($_GET['subscriber']);
            $subscriber = $wpdb->get_row($wpdb->prepare("SELECT token, device_uuid FROM $this->table_name WHERE id = %d", $subscriber_id));

            // Unsubscribe from topic before deletion
            if ($subscriber) {
                $this->unsubscribe_from_topic($subscriber->token, 'all');
            }

            $wpdb->delete($this->table_name, array('id' => $subscriber_id));
            echo '<div class="updated"><p>' . __('Subscriber deleted.', 'fcm_notifications') . '</p></div>';
        }

        // Handle search
        $search_term = '';
        if(isset($_POST['fcm_subscriber_search'])) {
            $search_term = sanitize_text_field($_POST['fcm_subscriber_search']);
        }

        // Retrieve subscribers with search
        if(!empty($search_term)) {
            $like_search = '%' . $wpdb->esc_like($search_term) . '%';
            $subscribers = $wpdb->get_results($wpdb->prepare("SELECT * FROM $this->table_name WHERE device_name LIKE %s OR other_data LIKE %s", $like_search, $like_search));
        } else {
            $subscribers = $wpdb->get_results("SELECT * FROM $this->table_name");
        }

        ?>
        <div class="wrap">
            <h1><?php _e('Subscriber List', 'fcm_notifications'); ?></h1>
            <!-- Search Form -->
            <form method="post" style="margin-bottom: 20px;">
                <input type="search" name="fcm_subscriber_search" value="<?php echo esc_attr($search_term); ?>" placeholder="<?php _e('Search by Device Name or Other Data', 'fcm_notifications'); ?>" />
                <?php submit_button(__('Search', 'fcm_notifications'), 'secondary', '', false); ?>
            </form>

            <table class="widefat">
                <thead>
                    <tr>
                        <th><?php _e('ID', 'fcm_notifications'); ?></th>
                        <th><?php _e('Token', 'fcm_notifications'); ?></th>
                        <th><?php _e('Device Type', 'fcm_notifications'); ?></th>
                        <th><?php _e('Device UUID', 'fcm_notifications'); ?></th>
                        <th><?php _e('Device Name', 'fcm_notifications'); ?></th>
                        <th><?php _e('Topic', 'fcm_notifications'); ?></th>
                        <th><?php _e('Other Data', 'fcm_notifications'); ?></th>
                        <th><?php _e('Status', 'fcm_notifications'); ?></th>
                        <th><?php _e('Actions', 'fcm_notifications'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if($subscribers): ?>
                        <?php foreach($subscribers as $subscriber): ?>
                            <tr>
                                <td><?php echo $subscriber->id; ?></td>
                                <td><?php echo esc_html($subscriber->token); ?></td>
                                <td><?php echo esc_html($subscriber->device_type); ?></td>
                                <td><?php echo esc_html($subscriber->device_uuid); ?></td>
                                <td><?php echo esc_html($subscriber->device_name); ?></td>
                                <td><?php echo esc_html($subscriber->topic); ?></td>
                                <td><?php echo esc_html($subscriber->other_data); ?></td>
                                <td><?php echo $subscriber->subscribed ? __('Subscribed', 'fcm_notifications') : __('Unsubscribed', 'fcm_notifications'); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fcm-subscribers&action=delete&subscriber=' . $subscriber->id); ?>" onclick="return confirm('<?php _e('Are you sure you want to delete this subscriber?', 'fcm_notifications'); ?>');"><?php _e('Delete', 'fcm_notifications'); ?></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="9"><?php _e('No subscribers found.', 'fcm_notifications'); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    // Initialize Firebase
    private function initialize_firebase() {
        $service_account_json = get_option('fcm_service_account_json');
        if(empty($service_account_json)) {
            error_log('FCM Notification Error: Service Account JSON not set.');
            return false;
        }

        $credentials = json_decode($service_account_json, true);

        $factory = (new Factory())->withServiceAccount($credentials);
        return $factory;
    }

    // Subscribe to topic
    private function subscribe_to_topic($token, $topic) {
        $factory = $this->initialize_firebase();
        if ($factory) {
            $messaging = $factory->createMessaging();
            try {
                $messaging->subscribeToTopic($topic, $token);
            } catch (\Exception $e) {
                error_log('FCM Subscription Error: ' . $e->getMessage());
            }
        }
    }

    // Unsubscribe from topic
    private function unsubscribe_from_topic($token, $topic) {
        $factory = $this->initialize_firebase();
        if ($factory) {
            $messaging = $factory->createMessaging();
            try {
                $messaging->unsubscribeFromTopic($topic, $token);
            } catch (\Exception $e) {
                error_log('FCM Unsubscription Error: ' . $e->getMessage());
            }
        }
    }

    // Handle subscription
    public function handle_subscribe($request) {
        $token = sanitize_text_field($request->get_param('device_token'));
        $device_type = sanitize_text_field($request->get_param('device_type'));
        $device_uuid = sanitize_text_field($request->get_param('device_uuid'));
        $device_name = sanitize_text_field($request->get_param('device_name'));
        $topic = sanitize_text_field($request->get_param('topic'));
        $other_data = $request->get_param('other_data');
        $other_data_json = json_encode($other_data);

        if(empty($token) || empty($device_uuid)) {
            return new WP_Error('missing_data', __('Token or Device UUID not provided', 'fcm_notifications'), array('status' => 400));
        }

        global $wpdb;

        $data = array(
            'token' => $token,
            'device_type' => $device_type,
            'device_uuid' => $device_uuid,
            'device_name' => $device_name,
            'topic' => $topic,
            'other_data' => $other_data_json,
            'subscribed' => 1,
        );

        $format = array('%s', '%s', '%s', '%s', '%s', '%s', '%d');

        // Insert or update subscriber based on device_uuid
        $existing_subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM $this->table_name WHERE device_uuid = %s", $device_uuid));

        if ($existing_subscriber) {
            // Unsubscribe old token from topic if token has changed
            if ($existing_subscriber->token !== $token) {
                $this->unsubscribe_from_topic($existing_subscriber->token, 'all');
            }

            // Update subscriber
            $wpdb->update(
                $this->table_name,
                $data,
                array('device_uuid' => $device_uuid),
                $format,
                array('%s')
            );
        } else {
            // Insert new subscriber
            $wpdb->insert($this->table_name, $data, $format);
        }

        // Subscribe the token to the 'all' topic
        $this->subscribe_to_topic($token, 'all');

        return rest_ensure_response(array('success' => true));
    }

    // Handle unsubscription
    public function handle_unsubscribe($request) {
        $device_uuid = sanitize_text_field($request->get_param('device_uuid'));

        if(empty($device_uuid)) {
            return new WP_Error('missing_data', __('Device UUID not provided', 'fcm_notifications'), array('status' => 400));
        }

        global $wpdb;

        // Retrieve the subscriber's token using device_uuid
        $subscriber = $wpdb->get_row($wpdb->prepare("SELECT token FROM $this->table_name WHERE device_uuid = %s", $device_uuid));

        if ($subscriber) {
            // Unsubscribe the token from the 'all' topic
            $this->unsubscribe_from_topic($subscriber->token, 'all');

            // Update subscriber's subscribed status
            $wpdb->update(
                $this->table_name,
                array('subscribed' => 0),
                array('device_uuid' => $device_uuid),
                array('%d'),
                array('%s')
            );

            return rest_ensure_response(array('success' => true));
        } else {
            return new WP_Error('not_found', __('Subscriber not found', 'fcm_notifications'), array('status' => 404));
        }
    }

    // Add a meta box in the post editor
    public function add_meta_box() {
        $allowed_post_types = get_option('fcm_allowed_post_types', array());

        foreach ($allowed_post_types as $post_type) {
            add_meta_box(
                'fcm_notification_meta_box',
                __('FCM Notification', 'fcm_notifications'),
                array($this, 'display_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    // Display the meta box content
    public function display_meta_box($post) {
        wp_nonce_field('fcm_notification_nonce', 'fcm_notification_nonce_field');
        $send_notification = get_post_meta($post->ID, '_send_fcm_notification', true);

        // Get default setting for the checkbox
        $checkbox_default_checked = get_option('fcm_checkbox_default_checked', 'no');

        // If meta is not set, use the default setting
        if ($send_notification === '') {
            $default_checked = ($checkbox_default_checked === 'yes') ? 'on' : '';
        } else {
            $default_checked = $send_notification;
        }

        $notification_schedule = get_post_meta($post->ID, '_fcm_notification_schedule', true);
        // Convert date to selected time zone for display
        $fcm_timezone = get_option('fcm_timezone', 'UTC');
        if (!empty($notification_schedule)) {
            $date = new DateTime($notification_schedule, new DateTimeZone('UTC'));
            $date->setTimezone(new DateTimeZone($fcm_timezone));
            $notification_schedule = $date->format('Y-m-d\TH:i');
        }
        ?>
        <p>
            <label>
                <input type="checkbox" name="send_fcm_notification" <?php checked($default_checked, 'on'); ?> />
                <?php _e('Send notification via FCM on save', 'fcm_notifications'); ?>
            </label>
        </p>
        <p>
            <label for="fcm_notification_schedule"><?php _e('Schedule notification (optional):', 'fcm_notifications'); ?></label><br/>
            <input type="datetime-local" name="fcm_notification_schedule" value="<?php echo esc_attr($notification_schedule); ?>" />
        </p>
        <?php
    }

    // Save post meta data
    public function save_post_meta($post_id, $post) {
        // Verify nonce for security
        if (!isset($_POST['fcm_notification_nonce_field']) || !wp_verify_nonce($_POST['fcm_notification_nonce_field'], 'fcm_notification_nonce')) {
            return;
        }
        // Avoid auto-saves
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        // Check permissions
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        // Check post type
        $allowed_post_types = get_option('fcm_allowed_post_types', array());
        if (!in_array($post->post_type, $allowed_post_types)) {
            return;
        }

        // Save checkbox value
        if (isset($_POST['send_fcm_notification'])) {
            update_post_meta($post_id, '_send_fcm_notification', 'on');
        } else {
            delete_post_meta($post_id, '_send_fcm_notification');
        }

        // Save scheduled date in UTC
        if (!empty($_POST['fcm_notification_schedule'])) {
            $schedule_input = sanitize_text_field($_POST['fcm_notification_schedule']);

            // Create DateTime object in selected time zone
            $fcm_timezone = get_option('fcm_timezone', 'UTC');
            $date = DateTime::createFromFormat('Y-m-d\TH:i', $schedule_input, new DateTimeZone($fcm_timezone));

            if ($date) {
                // Convert to UTC for storage
                $date->setTimezone(new DateTimeZone('UTC'));
                // Store date in ISO 8601 format
                update_post_meta($post_id, '_fcm_notification_schedule', $date->format('Y-m-d H:i:s'));
            } else {
                // If date is invalid, delete meta
                delete_post_meta($post_id, '_fcm_notification_schedule');
            }
        } else {
            delete_post_meta($post_id, '_fcm_notification_schedule');
        }
    }

    // Send notification when post is saved
    public function send_notification_on_save($post_id, $post) {
        // Avoid revisions or unpublished posts
        if ($post->post_status != 'publish') {
            return;
        }
        // Check post type
        $allowed_post_types = get_option('fcm_allowed_post_types', array());
        if (!in_array($post->post_type, $allowed_post_types)) {
            return;
        }

        // Check if notification should be sent
        $send_notification = get_post_meta($post_id, '_send_fcm_notification', true);
        if ($send_notification != 'on') {
            return;
        }

        // Check if a schedule is set
        $schedule = get_post_meta($post_id, '_fcm_notification_schedule', true);

        if (!empty($schedule)) {
            // Date is already stored in UTC
            $timestamp = strtotime($schedule);

            if ($timestamp > time()) {
                wp_schedule_single_event($timestamp, 'fcm_send_scheduled_notification', array($post_id));
            } else {
                // If date has passed, send immediately
                $this->send_fcm_notification($post->post_title, wp_strip_all_tags($post->post_content), $post_id, $post->post_type);
            }
        } else {
            // Send immediately
            $this->send_fcm_notification($post->post_title, wp_strip_all_tags($post->post_content), $post_id, $post->post_type);
        }

        // Clean up meta to avoid multiple sends
        delete_post_meta($post_id, '_send_fcm_notification');
        delete_post_meta($post_id, '_fcm_notification_schedule');
    }

    // Function to send scheduled notification
    public function send_scheduled_notification($post_id) {
        $post = get_post($post_id);
        if ($post) {
            $this->send_fcm_notification($post->post_title, wp_strip_all_tags($post->post_content), $post_id, $post->post_type);
        }
    }

    // Send notification via FCM HTTP v1 using topic, including post_id and post_type
    public function send_fcm_notification($title, $body, $post_id = null, $post_type = null) {
        $access_token = $this->get_access_token();
        if(!$access_token) {
            return;
        }

        $project_id = get_option('fcm_project_id');
        if(empty($project_id)) {
            error_log('FCM Notification Error: Project ID not set.');
            return;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/' . $project_id . '/messages:send';

        // The topic used for sending notifications
        $topic = 'all'; // You can change the topic name if necessary

        // Prepare additional data
        $data = array();
        if ($post_id !== null) {
            $data['post_id'] = (string)$post_id;
        }
        if ($post_type !== null) {
            $data['post_type'] = $post_type;
        }

        $message = array(
            'message' => array(
                'topic' => $topic,
                'notification' => array(
                    'title' => $title,
                    'body' => $body
                )
            )
        );

        // Include 'data' only if it's not empty
        if (!empty($data)) {
            $message['message']['data'] = $data;
        }

        $headers = array(
            'Authorization' => 'Bearer ' . $access_token,
            'Content-Type' => 'application/json; UTF-8'
        );

        $args = array(
            'headers' => $headers,
            'body' => json_encode($message)
        );

        $response = wp_remote_post($url, $args);

        if(is_wp_error($response)) {
            error_log('FCM Notification Error: ' . $response->get_error_message());
        } else {
            $response_body = wp_remote_retrieve_body($response);
            error_log('FCM Notification Sent: ' . $response_body);
        }
    }

    // Get access token
    public function get_access_token() {
        $service_account_json = get_option('fcm_service_account_json');
        if(empty($service_account_json)) {
            error_log('FCM Notification Error: Service Account JSON not set.');
            return false;
        }

        $credentials = json_decode($service_account_json, true);

        $client = new Google_Client();
        $client->setAuthConfig($credentials);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');

        $access_token = $client->fetchAccessTokenWithAssertion();

        if(isset($access_token['access_token'])) {
            return $access_token['access_token'];
        } else {
            error_log('FCM Notification Error: Unable to obtain access token.');
            return false;
        }
    }

    // Register REST API routes
    public function register_api_routes() {
        register_rest_route('fcm/v1', '/subscribe', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_subscribe'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route('fcm/v1', '/unsubscribe', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_unsubscribe'),
            'permission_callback' => '__return_true',
        ));
    }
}

// Instantiate the plugin
$fcm_notifications_plugin = new FCM_Notifications_Plugin();
