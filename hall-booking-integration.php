<?php
/**
 * Plugin Name: Hall Booking Integration
 * Description: Automates Sandbaai Hall bookings via Contact Form 7 and Events Manager, with invoice generation and tariff management.
 * Version: 2.2
 * Author: Christopher Reid, Copilot
 */

if (!defined('ABSPATH')) exit;

class HallBookingIntegration {
    public function __construct() {
        add_action('wpcf7_mail_sent', array($this, 'handle_booking_submission'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('add_meta_boxes', array($this, 'add_approval_metabox'));
        add_action('save_post', array($this, 'handle_event_approval'), 10, 3);
        add_action('init', array($this, 'register_invoice_post_type'));
        add_action('admin_post_hall_save_tariffs', array($this, 'save_tariffs'));
        add_shortcode('sandbaai_hall_tariffs', array($this, 'display_tariffs_shortcode'));
    }

    /**
     * Handle Contact Form 7 booking submission: create event + invoice, notify admin.
     */
    public function handle_booking_submission($contact_form) {
        $booking_form_id = get_option('hall_booking_form_id', 0);
        if ($contact_form->id() != $booking_form_id) return;

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) return;

        $posted_data = $submission->get_posted_data();

        // Extract and sanitize
        $contact_person = sanitize_text_field($this->get_first($posted_data['contact-person'] ?? ''));
        $organization   = sanitize_text_field($this->get_first($posted_data['organization'] ?? ''));
        $email          = sanitize_email($this->get_first($posted_data['your-email'] ?? ''));
        $phone          = sanitize_text_field($this->get_first($posted_data['phone'] ?? ''));
        $space_raw      = $this->get_first($posted_data['space'] ?? '');
        $space_cleaned  = $this->clean_space_name($space_raw);
        $space          = sanitize_text_field($space_cleaned);
        $event_date     = sanitize_text_field($this->get_first($posted_data['event-date'] ?? ''));
        $event_time_raw = $this->get_first($posted_data['event-time'] ?? '');
        $event_time     = sanitize_text_field($event_time_raw);

        // Custom hours now separated into start/end time
        $custom_start   = sanitize_text_field($this->get_first($posted_data['custom-start-time'] ?? ''));
        $custom_end     = sanitize_text_field($this->get_first($posted_data['custom-end-time'] ?? ''));
        $guest_count    = intval($this->get_first($posted_data['guest-count'] ?? 0));
        $event_title    = sanitize_text_field($this->get_first($posted_data['event-title'] ?? ''));
        $description    = sanitize_textarea_field($this->get_first($posted_data['event-description'] ?? ''));
        $setup_requirements = $this->format_array_field($posted_data['setup'] ?? []);
        $other_setup    = sanitize_text_field($this->get_first($posted_data['other-setup'] ?? ''));
        $catering_raw   = $this->get_first($posted_data['catering'] ?? '');
        $catering       = sanitize_text_field($catering_raw);
        $event_privacy_raw = strtolower($this->get_first($posted_data['event-privacy'] ?? 'private'));
        $is_private     = ($event_privacy_raw === 'private');

        // Store original title, do not rename for privacy
        $pending_title  = "PENDING: " . ($event_title ?: 'Event');
        $public_description = $description ?: 'Private event at Sandbaai Hall';

        // Admin notes - stored as meta only, not in post_content
        $admin_notes = $this->build_admin_notes([
            'contact_person' => $contact_person,
            'organization' => $organization,
            'email' => $email,
            'phone' => $phone,
            'space' => $space,
            'guest_count' => $guest_count,
            'event_time' => $event_time,
            'custom_start' => $custom_start,
            'custom_end' => $custom_end,
            'setup_requirements' => $setup_requirements,
            'other_setup' => $other_setup,
            'catering' => $catering
        ]);

        $location_id = $this->get_location_id($space);

        // Parse times (handles custom start/end)
        $times = $this->parse_event_times($event_time, $custom_start, $custom_end);

        // Create event post for Events Manager (**post_content is now the user description**)
        $event_id = wp_insert_post([
            'post_title'     => $pending_title,
            'post_content'   => $public_description,
            'post_excerpt'   => $public_description,
            'post_status'    => 'draft', // FIXED: was 'pending'
            'post_type'      => 'event',
            'post_author'    => 1,
            'comment_status' => 'closed'
        ]);

        if ($event_id) {
            // Meta for Events Manager
            update_post_meta($event_id, '_event_start_date', date('Y-m-d', strtotime($event_date)));
            update_post_meta($event_id, '_event_end_date', date('Y-m-d', strtotime($event_date)));
            update_post_meta($event_id, '_event_start_time', $times['start']);
            update_post_meta($event_id, '_event_end_time', $times['end']);
            update_post_meta($event_id, '_event_timezone', 'Africa/Johannesburg');
            update_post_meta($event_id, '_location_id', $location_id);
            update_post_meta($event_id, '_event_rsvp', 0);

            // Set location (robust for EM)
            if (class_exists('EM_Event')) {
                $em_event = new EM_Event($event_id);
                if ($location_id && get_post_status($location_id) == 'publish') {
                    $em_event->location_id = $location_id;
                    $em_event->location = new EM_Location($location_id);
                    $em_event->save();
                }
            }

            // Booking meta
            update_post_meta($event_id, '_booking_contact_person', $contact_person);
            update_post_meta($event_id, '_booking_email', $email);
            update_post_meta($event_id, '_booking_phone', $phone);
            update_post_meta($event_id, '_booking_space', $space);
            update_post_meta($event_id, '_booking_guests', $guest_count);
            update_post_meta($event_id, '_booking_status', 'pending_payment');
            update_post_meta($event_id, '_booking_event_title', $event_title);
            update_post_meta($event_id, '_booking_is_private', $is_private ? 1 : 0);
            update_post_meta($event_id, '_booking_event_description', $description);
            update_post_meta($event_id, '_booking_admin_notes', $admin_notes);
            update_post_meta($event_id, '_booking_custom_start', $custom_start);
            update_post_meta($event_id, '_booking_custom_end', $custom_end);

            // Create initial invoice
            $invoice_id = $this->create_invoice_for_booking($event_id, $contact_person, $email, $space, $event_date, $event_time, $guest_count);

            // Notify booking admin
            $this->send_admin_notification($event_id, $contact_person, $space, $event_date, $event_title, $invoice_id);

            // DO NOT use wp_redirect here; let CF7 handle AJAX
        }
    }

    //////////////////////////
    // Helper and workflow functions
    //////////////////////////

    private function get_first($val) { return is_array($val) ? ($val[0] ?? '') : $val; }

    private function clean_space_name($space_raw) {
        if (preg_match('/^([^(]+)\s*\(/', $space_raw, $matches)) return trim($matches[1]);
        return trim($space_raw);
    }

    private function build_admin_notes($data) {
        $content = "<div style='background: #fff3cd; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px; margin-bottom: 20px;'>";
        $content .= "<strong>🔍 STATUS: PENDING PAYMENT CONFIRMATION</strong><br>This booking requires manual approval once payment is verified.</div>";
        $content .= "<h3>📞 Contact Information</h3><strong>Contact Person:</strong> {$data['contact_person']}<br>";
        if ($data['organization']) $content .= "<strong>Organization:</strong> {$data['organization']}<br>";
        $content .= "<strong>Email:</strong> <a href='mailto:{$data['email']}'>{$data['email']}</a><br>";
        $content .= "<strong>Phone:</strong> <a href='tel:{$data['phone']}'>{$data['phone']}</a><br>";
        $content .= "<h3>🏢 Booking Details</h3><strong>Space Requested:</strong> {$data['space']}<br>";
        $content .= "<strong>Expected Guests:</strong> {$data['guest_count']}<br><strong>Time:</strong> {$data['event_time']}<br>";
        if (!empty($data['custom_start']) && !empty($data['custom_end'])) {
            $content .= "<strong>Custom Hours:</strong> {$data['custom_start']} to {$data['custom_end']}<br>";
        }
        if ($data['setup_requirements']) $content .= "<h3>⚙️ Setup Requirements</h3><p>{$data['setup_requirements']}</p>";
        if ($data['other_setup']) $content .= "<strong>Additional Setup:</strong> {$data['other_setup']}<br>";
        if ($data['catering'] && $data['catering'] != 'No catering') $content .= "<h3>🍽️ Catering</h3><p>{$data['catering']}</p>";
        $content .= "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; margin-top: 20px;'>";
        $content .= "<h4>✅ To Approve This Booking:</h4><ol><li>Verify payment</li><li>Use the 'Quick Approve' button</li><li>Set visibility</li><li>Adjust event title/description</li></ol></div>";
        return $content;
    }

    // Modified: now supports custom start/end time (HH:MM)
    private function parse_event_times($event_time, $custom_start = '', $custom_end = '') {
        $times = [
            'Full Day (8am-12:00am)' => ['start' => '08:00:00', 'end' => '24:00:00'],
            'Morning (8am-1pm)'      => ['start' => '08:00:00', 'end' => '13:00:00'],
            'Afternoon (1pm-6pm)'    => ['start' => '13:00:00', 'end' => '18:00:00'],
            'Evening (6pm-12am)'     => ['start' => '18:00:00', 'end' => '24:00:00']
        ];
        if ($event_time === 'Custom Hours (specify below)' && !empty($custom_start) && !empty($custom_end)) {
            // Expect custom_start and custom_end as HH:MM
            return [
                'start' => $custom_start . ':00', // add seconds
                'end'   => $custom_end . ':00'
            ];
        }
        if (isset($times[$event_time])) return $times[$event_time];
        return ['start' => '08:00:00', 'end' => '17:00:00'];
    }

    private function get_location_id($space) {
        $main_hall_id = get_option('hall_booking_main_hall_location', 0);
        $meeting_room_id = get_option('hall_booking_meeting_room_location', 0);
        $space_lower = strtolower($space);
        if (strpos($space_lower, 'main hall') !== false) return $main_hall_id;
        elseif (strpos($space_lower, 'meeting room') !== false) return $meeting_room_id;
        elseif (strpos($space_lower, 'both') !== false) return $main_hall_id;
        return $main_hall_id;
    }

    private function format_array_field($field_data) {
        if (is_array($field_data)) return implode(', ', array_map('sanitize_text_field', $field_data));
        return sanitize_text_field($field_data);
    }

    //////////////////////
    // Invoice system
    //////////////////////

    public function register_invoice_post_type() {
        register_post_type('hall_invoice', [
            'labels' => [
                'name' => 'Invoices',
                'singular_name' => 'Invoice',
                'add_new' => 'Create Invoice',
                'add_new_item' => 'Create Invoice',
                'edit_item' => 'Edit Invoice',
                'view_item' => 'View Invoice'
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'edit.php?post_type=event',
            'supports' => ['title', 'editor'],
            'capability_type' => 'post'
        ]);
    }

    /**
     * Create invoice linked to booking event
     */
    public function create_invoice_for_booking($event_id, $contact_person, $email, $space, $event_date, $event_time, $guest_count) {
        // Load tariffs from options
        $tariffs = get_option('hall_tariffs', [
            'Main Hall Full Day' => 1200,
            'Main Hall Half Day' => 800,
            'Meeting Room Full Day' => 600,
            'Meeting Room Half Day' => 400
        ]);
        $price = $tariffs["{$space} {$event_time}"] ?? 0;
        $title = "Invoice for {$space} booking on {$event_date}";

        $invoice_id = wp_insert_post([
            'post_title'   => $title,
            'post_type'    => 'hall_invoice',
            'post_status'  => 'draft', // FIXED: was 'pending'
            'post_content' => "Booking for {$space} ({$event_time}) on {$event_date}. Guests: {$guest_count}.",
            'post_author'  => 1,
        ]);
        if ($invoice_id) {
            update_post_meta($invoice_id, '_linked_event', $event_id);
            update_post_meta($invoice_id, '_invoice_contact', $contact_person);
            update_post_meta($invoice_id, '_invoice_email', $email);
            update_post_meta($invoice_id, '_invoice_amount', $price);
            update_post_meta($invoice_id, '_invoice_status', 'pending');
        }
        return $invoice_id;
    }

    /////////////////////
    // Tariff management
    /////////////////////

    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'Hall Booking Settings',
            'Booking Settings',
            'manage_options',
            'hall-booking-settings',
            array($this, 'admin_page')
        );
        add_submenu_page(
            'edit.php?post_type=event',
            'Tariff Management',
            'Tariffs',
            'manage_options',
            'hall-tariffs',
            array($this, 'tariffs_page')
        );
    }

    public function admin_page() {
        if (isset($_POST['submit'])) {
            update_option('hall_booking_form_id', sanitize_text_field($_POST['form_id']));
            update_option('hall_booking_main_hall_location', intval($_POST['main_hall_location']));
            update_option('hall_booking_meeting_room_location', intval($_POST['meeting_room_location']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        if (isset($_POST['delete_booking_id'])) {
            $booking_id = intval($_POST['delete_booking_id']);
            if (current_user_can('delete_post', $booking_id)) {
                wp_trash_post($booking_id);
                echo '<div class="notice notice-success"><p>Booking deleted!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to delete booking. Permission denied.</p></div>';
            }
        }

        $form_id = get_option('hall_booking_form_id', 0);
        $main_hall_location = get_option('hall_booking_main_hall_location', 0);
        $meeting_room_location = get_option('hall_booking_meeting_room_location', 0);
        $cf7_forms = get_posts(['post_type' => 'wpcf7_contact_form', 'posts_per_page' => -1]);
        $locations = get_posts(['post_type' => 'location', 'posts_per_page' => -1]);
        ?>
        <div class="wrap">
            <h1>Hall Booking Integration Settings</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row">Booking Form</th>
                        <td>
                            <select name="form_id">
                                <option value="0">Select your booking form...</option>
                                <?php foreach ($cf7_forms as $form): ?>
                                    <option value="<?php echo esc_attr($form->ID); ?>"<?php if ($form_id == $form->ID) echo ' selected="selected"'; ?>>
                                        <?php echo esc_html($form->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Choose the Contact Form 7 form that should create events</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Main Hall Location</th>
                        <td>
                            <select name="main_hall_location">
                                <option value="0">Select location...</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo esc_attr($location->ID); ?>"<?php if ($main_hall_location == $location->ID) echo ' selected="selected"'; ?>>
                                        <?php echo esc_html($location->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Meeting Room Location</th>
                        <td>
                            <select name="meeting_room_location">
                                <option value="0">Select location...</option>
                                <?php foreach ($locations as $location): ?>
                                    <option value="<?php echo esc_attr($location->ID); ?>"<?php if ($meeting_room_location == $location->ID) echo ' selected="selected"'; ?>>
                                        <?php echo esc_html($location->post_title); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Instructions</h2>
            <ol>
                <li>Select your booking form above</li>
                <li>Make sure your form field names match: contact-person, your-email, phone, space, event-date, event-time, event-title, event-privacy, etc.</li>
                <li>Create locations for your Main Hall and Meeting Room in Events Manager</li>
                <li>Select those locations above</li>
                <li>Test by submitting your booking form</li>
            </ol>

            <h3>Recent Bookings</h3>
            <?php
            $recent_bookings = get_posts([
                'post_type' => 'event',
                'meta_key' => '_booking_status',
                'posts_per_page' => 10,
                'post_status' => ['draft', 'pending', 'publish'] // FIXED: includes draft
            ]);

            if ($recent_bookings) {
                echo '<table class="wp-list-table widefat fixed striped">';
                echo '<thead><tr><th>Event</th><th>Contact</th><th>Date</th><th>Status</th><th>Action</th></tr></thead><tbody>';
                foreach ($recent_bookings as $booking) {
                    $contact = get_post_meta($booking->ID, '_booking_contact_person', true);
                    $date = get_post_meta($booking->ID, '_event_start_date', true);
                    $edit_link = admin_url("post.php?post={$booking->ID}&action=edit");
                    echo '<tr>';
                    echo '<td>' . esc_html($booking->post_title) . '</td>';
                    echo '<td>' . esc_html($contact) . '</td>';
                    echo '<td>' . esc_html($date) . '</td>';
                    echo '<td>' . ($booking->post_status === 'draft' ? 'Pending' : ($booking->post_status === 'publish' ? 'Approved' : ucfirst($booking->post_status))) . '</td>';
                    echo '<td>
                        <a href="' . esc_url($edit_link) . '">Edit</a>
                        | <form method="post" style="display:inline;" onsubmit="return confirm(\'Are you sure you want to delete this booking?\');">
                            <input type="hidden" name="delete_booking_id" value="' . esc_attr($booking->ID) . '"/>
                            <input type="submit" value="Delete" class="button-link-delete"/>
                        </form>
                    </td>';
                    echo '</tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<p>No bookings found.</p>';
            }
            ?>
        </div>
        <?php
    }

public function tariffs_page() {
    if (!current_user_can('manage_options')) return;

    // Full 2025 tariff/pricelist data structure
    $default_tariffs = [
        'HALL HIRE RATE' => [
            'Rate per day up to 24h00' => 2200.00,
            'Rate per hour or part thereof after 24h00' => 220.00,
            'Rate per hour or part thereof for preparations' => 110.00,
            'Rate per hour: for 1st hour' => 220.00,
            'Rate per hour: after first hour – per hour' => 110.00,
            'Meeting room : per hour' => 110.00,
            'Refundable deposit at time of booking' => 2000.00,
        ],
        'SPOTLIGHTS & SOUND' => [
            'Spotlights per event' => 250.00,
            'Sound System' => 200.00,
            'Projector plus screen' => 200.00,
            'Wi Fi' => 50.00,
        ],
        'KITCHEN HIRE' => [
            'Per event, including use of oven, stove fridges' => 650.00,
            'Per event, for serving only' => 450.00,
        ],
        'CROCKERY (each)' => [
            'Dinner Plate' => 2.00,
            'Fish Plate' => 1.50,
            'Side Plate' => 1.00,
            'Soup/desert bowl' => 1.00,
            'Cup & saucer' => 1.50,
            'Teapot' => 8.50,
            'Milk jug, 24ml' => 3.50,
            'Sugar Bowl' => 2.50,
            'Butter disk (fat)' => 1.00,
            'Salt & pepper set' => 3.50,
            'Plater, large glass' => 18.00,
            'Refundable deposit for crockery, cutlery etc' => 500.00,
        ],
        'CUTLERY (each)' => [
            'Table knife' => 1.00,
            'Table fork' => 1.00,
            'Desert spoon' => 1.00,
            'Soup spoon' => 1.00,
            'Teaspoon' => 1.00,
            'Salad server set' => 6.00,
        ],
        'GLASSWARE (each)' => [
            'Salad bowl medium' => 5.00,
            'Water jug' => 4.00,
            'Champagne flutes' => 1.20,
            'White wine' => 1.20,
            'Red wine' => 1.20,
            'Pluto (cooldrink)' => 1.20,
            'Beer' => 1.20,
            'Tumbler' => 1.20,
            'Sherry' => 2.20,
        ],
        'MISCELLANEOUS (each)' => [
            'Ice Bucket' => 8.50,
            'Trays' => 0.00,
            'Urn (20 litre)' => 55.00,
        ],
        'TABLE LINEN (each)' => [
            'White round table cloth (3m) or regular' => 60.00,
            'Serviettes white' => 10.00,
        ],
        'CORKAGE FEES WHEN CLIENT SUPPLIES THEIR OWN' => [
            'Per 750ml botle' => 30.00,
        ],
    ];

    // Load tariffs from DB or use defaults
    $tariffs = get_option('hall_tariffs', $default_tariffs);

    // Handle POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tariffs'])) {
        $new_tariffs = [];
        foreach ($_POST['tariffs'] as $category => $items) {
            foreach ($items as $label => $value) {
                $new_tariffs[$category][$label] = floatval($value);
            }
        }
        update_option('hall_tariffs', $new_tariffs);
        $tariffs = $new_tariffs;
        echo '<div class="notice notice-success"><p>Tariffs updated successfully!</p></div>';
    }

    ?>
    <div class="wrap">
        <h1>Sandbaai Hall Tariff Management (2025)</h1>
        <form method="post">
            <?php foreach ($tariffs as $category => $items): ?>
                <h2 style="margin-top:2em;"><?php echo esc_html($category); ?></h2>
                <table class="form-table">
                <?php foreach ($items as $label => $value): ?>
                    <tr>
                        <th scope="row"><?php echo esc_html($label); ?></th>
                        <td>
                           <input type="number" step="0.01" min="0" name="tariffs[<?php echo esc_attr($category); ?>][<?php echo esc_attr($label); ?>]" value="<?php echo esc_attr($value); ?>" />
<span style="margin-left:10px;font-weight:bold;">R <?php echo number_format((float)$value, 2); ?></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </table>
            <?php endforeach; ?>
            <input type="submit" value="Save Tariffs" class="button button-primary"/>
        </form>
    </div>
    <?php
}

    public function save_tariffs() {
        if (!current_user_can('manage_options')) wp_die('No permissions');
        $tariffs = $_POST['tariffs'] ?? [];
        $cleaned = [];
        foreach ($tariffs as $key => $value) $cleaned[$key] = intval($value);
        update_option('hall_tariffs', $cleaned);
        wp_redirect(admin_url('edit.php?post_type=event&page=hall-tariffs'));
        exit;
    }
    
public function display_tariffs_shortcode() {
    $tariffs = get_option('hall_tariffs', []);
    if (!$tariffs || !is_array($tariffs)) {
        return "<p>No tariff data available.</p>";
    }
    ob_start();
    echo '<div class="sandbaai-hall-tariffs">';
    foreach ($tariffs as $category => $items) {
        echo '<h2>' . esc_html($category) . '</h2>';
        echo '<table style="width:100%;margin-bottom:2em;">';
        foreach ($items as $label => $price) {
            echo '<tr>';
            echo '<td style="width:70%;">' . esc_html($label) . '</td>';
            echo '<td style="width:30%;text-align:right;font-weight:bold;">R ' . number_format((float)$price, 2) . '</td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    echo '</div>';
    return ob_get_clean();
}
    
    //////////////////////
    // Booking approval workflow
    //////////////////////

    public function handle_event_approval($post_id, $post, $update) {
        if ($post->post_type !== 'event' || !$update) return;

        if ($post->post_status === 'publish' && get_post_meta($post_id, '_booking_status', true) === 'pending_payment') {
            update_post_meta($post_id, '_booking_status', 'approved');
            // Clean up title and description
            $public_description = get_post_meta($post_id, '_booking_event_description', true);
            if (!$public_description) $public_description = $post->post_excerpt ?: 'Private event at Sandbaai Hall';
            $is_private = get_post_meta($post_id, '_booking_is_private', true);
            // Always use the original event title, even if private
            $original_title = get_post_meta($post_id, '_booking_event_title', true) ?: $post->post_title;
            wp_update_post(['ID' => $post_id, 'post_title' => $original_title, 'post_content' => $public_description]);
            // Tag event as 'private' for filtering
            if ($is_private) {
                wp_set_post_tags($post_id, 'private', true);
            } else {
                wp_set_post_tags($post_id, '', false);
            }
        }
    }

    public function add_approval_metabox() {
        add_meta_box(
            'hall_booking_approval',
            '⚡ Booking Management',
            array($this, 'approval_metabox_content'),
            'event',
            'side',
            'high'
        );
    }

    public function approval_metabox_content($post) {
        $booking_status = get_post_meta($post->ID, '_booking_status', true);
        $admin_notes = get_post_meta($post->ID, '_booking_admin_notes', true);

        if ($booking_status === 'pending_payment') {
            $contact_person = get_post_meta($post->ID, '_booking_contact_person', true);
            $email = get_post_meta($post->ID, '_booking_email', true);
            $phone = get_post_meta($post->ID, '_booking_phone', true);
            $space = get_post_meta($post->ID, '_booking_space', true);
            $is_private = get_post_meta($post->ID, '_booking_is_private', true);
            $event_title = get_post_meta($post->ID, '_booking_event_title', true);

            echo '<div style="background: #fff3cd; padding: 10px; border-radius: 5px; margin-bottom: 15px;">';
            echo '<strong>📋 Booking Summary:</strong><br>';
            echo "Contact: {$contact_person}<br>";
            echo "Email: <a href='mailto:{$email}'>{$email}</a><br>";
            echo "Phone: <a href='tel:{$phone}'>{$phone}</a><br>";
            echo "Space: {$space}<br>";
            echo "Original Title: " . esc_html($event_title) . "<br>";
            echo '</div>';
            echo '<div style="background: #f8d7da; padding: 10px; border-radius: 5px; margin-bottom: 15px;">';
            echo '<strong>⚠️ Payment Status:</strong><br>Verify payment before approving!';
            echo '</div>';
            echo '<div style="margin-bottom: 15px;">';
            echo '<label><strong>Event Visibility:</strong></label><br>';
            echo '<label><input type="radio" name="event_visibility" value="private"' . ($is_private ? ' checked' : '') . '> Private Event</label><br>';
            echo '<label><input type="radio" name="event_visibility" value="public"' . (!$is_private ? ' checked' : '') . '> Public Event</label>';
            echo '</div>';
            wp_nonce_field('approve_booking_nonce', 'approve_booking_nonce');
            echo '<button type="button" id="quick-approve-btn" class="button button-primary button-large" style="width: 100%; margin-bottom: 10px;">
                    ✅ Approve Booking
                  </button><p style="font-size: 12px; color: #666;">This will publish the event and clean up the display for public viewing.</p>';
            echo '<details><summary>Admin Notes</summary>' . $admin_notes . '</details>';
            ?>
            <script>
            document.getElementById('quick-approve-btn').addEventListener('click', function() {
                if (confirm('⚠️ CONFIRM: Has payment been received and verified?\n\nThis will approve the booking and make it visible based on your privacy setting.')) {
                    var titleField = document.getElementById('title');
                    if (titleField && titleField.value.startsWith('PENDING: ')) {
                        titleField.value = titleField.value.replace('PENDING: ', '');
                    }
                    var statusRadios = document.querySelectorAll('input[name="post_status"]');
                    statusRadios.forEach(function(radio) {
                        if (radio.value === 'publish') radio.checked = true;
                    });
                    var statusDisplay = document.getElementById('post-status-display');
                    if (statusDisplay) statusDisplay.textContent = 'Published';
                    var visibilityRadios = document.querySelectorAll('input[name="event_visibility"]');
                    var selectedVisibility = document.querySelector('input[name="event_visibility"]:checked');
                    alert('✅ Booking approved! Click "Update" to save changes and clean up the display.');
                }
            });
            </script>
            <?php
        } else {
            echo '<p>✅ This booking has been approved and is live on the calendar.</p>';
            $contact_person = get_post_meta($post->ID, '_booking_contact_person', true);
            $email = get_post_meta($post->ID, '_booking_email', true);
            $phone = get_post_meta($post->ID, '_booking_phone', true);
            if ($contact_person) {
                echo '<div style="background: #d4edda; padding: 10px; border-radius: 5px;">';
                echo '<strong>📞 Contact Details:</strong><br>';
                echo "Contact: {$contact_person}<br>";
                echo "Email: <a href='mailto:{$email}'>{$email}</a><br>";
                echo "Phone: <a href='tel:{$phone}'>{$phone}</a>";
                echo '</div>';
            }
        }
    }

    //////////////////////
    // Admin notification
    //////////////////////

private function send_admin_notification($event_id, $contact_person, $space, $event_date, $event_title, $invoice_id) {
    $admin_email = get_option('admin_email');
    if (empty($admin_email)) {
        error_log("HallBookingIntegration: admin_email option not set.");
        return;
    }
    if (empty($event_id) || empty($invoice_id)) {
        error_log("HallBookingIntegration: Missing event_id or invoice_id for admin notification.");
        return;
    }
    $edit_url = admin_url("post.php?post={$event_id}&action=edit");
    $invoice_url = admin_url("post.php?post={$invoice_id}&action=edit");
    $subject = "🏢 New Hall Booking: {$contact_person} - {$space}";
    $message = "A new booking request has been received and automatically created as a pending event.\n\n";
    $message .= "📋 BOOKING DETAILS:\nContact: {$contact_person}\nSpace: {$space}\nDate: {$event_date}\nEvent: {$event_title}\n\n";
    $message .= "Invoice: {$invoice_url}\n";
    $message .= "⚡ QUICK APPROVAL:\n1. Verify payment\n2. Click here to approve: {$edit_url}\n3. Use the 'Quick Approve' button\n4. Set visibility\n\n";
    $message .= "The event will automatically appear on your public calendar once approved.";
    wp_mail($admin_email, $subject, $message);
}
}

// Initialize plugin
new HallBookingIntegration();
?>
