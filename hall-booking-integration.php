<?php
/**
 * Plugin Name: Hall Booking Integration
 * Description: Converts Contact Form 7 booking submissions into Events Manager events
 * Version: 1.2
 * Author: Christopher Reid
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class HallBookingIntegration {
    
    public function __construct() {
        add_action('wpcf7_mail_sent', array($this, 'create_event_from_booking'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('add_meta_boxes', array($this, 'add_approval_metabox'));
        add_action('wp_ajax_approve_booking', array($this, 'approve_booking_ajax'));
        add_action('save_post', array($this, 'handle_event_approval'), 10, 3);
    }
    
    /**
     * Create Events Manager event from CF7 submission
     */
    public function create_event_from_booking($contact_form) {
        // Get the form ID - convert to string to match CF7 format
        $booking_form_id = get_option('hall_booking_form_id', 0);
        
        if ($contact_form->id() != $booking_form_id) {
            return; // Only process our booking form
        }
        
        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            return;
        }
        
        $posted_data = $submission->get_posted_data();

        // Extract and sanitize form data
        $contact_person = sanitize_text_field($posted_data['contact-person'] ?? '');
        $organization = sanitize_text_field($posted_data['organization'] ?? '');
        $email = sanitize_email($posted_data['your-email'] ?? '');
        $phone = sanitize_text_field($posted_data['phone'] ?? '');
        $space = sanitize_text_field($posted_data['space'] ?? '');
        $event_date = sanitize_text_field($posted_data['event-date'] ?? '');
        $event_time = sanitize_text_field($posted_data['event-time'] ?? '');
        $custom_hours = sanitize_text_field($posted_data['custom-hours'] ?? '');
        $guest_count = intval($posted_data['guest-count'] ?? 0);
        $event_title = sanitize_text_field($posted_data['event-title'] ?? ''); // NEW FIELD
        $description = sanitize_textarea_field($posted_data['event-description'] ?? '');
        $setup_requirements = $this->format_array_field($posted_data['setup'] ?? []);
        $other_setup = sanitize_text_field($posted_data['other-setup'] ?? '');
        $catering = sanitize_text_field($posted_data['catering'] ?? '');
        $event_privacy = sanitize_text_field($posted_data['event-privacy'] ?? 'private'); // NEW FIELD, expects 'private' or 'public'
        $is_private = ($event_privacy === 'private');

        // Set public-facing title
        $public_title = $is_private ? 'Private Event' : ($event_title ?: 'Event');
        $pending_title = "PENDING: {$public_title}";

        // Create public event description (what visitors see on calendar)
        $public_description = $description ?: 'Private event at Sandbaai Hall';

        // Create admin notes (internal use only)
        $admin_notes = $this->build_admin_notes([
            'contact_person' => $contact_person,
            'organization' => $organization,
            'email' => $email,
            'phone' => $phone,
            'space' => $space,
            'guest_count' => $guest_count,
            'event_time' => $event_time,
            'custom_hours' => $custom_hours,
            'setup_requirements' => $setup_requirements,
            'other_setup' => $other_setup,
            'catering' => $catering
        ]);
        
        // Determine location
        $location_id = $this->get_location_id($space);
        
        // Get event times
        $times = $this->parse_event_times($event_time, $custom_hours);
        
        // Create the event post
        $event_data = [
            'post_title' => $pending_title,
            'post_content' => $admin_notes,
            'post_excerpt' => $public_description,
            'post_status' => 'pending',
            'post_type' => 'event',
            'post_author' => 1,
            'comment_status' => 'closed'
        ];
        
        $event_id = wp_insert_post($event_data);
        
        if ($event_id) {
            // Add Events Manager meta data
            update_post_meta($event_id, '_event_start_date', date('Y-m-d', strtotime($event_date)));
            update_post_meta($event_id, '_event_end_date', date('Y-m-d', strtotime($event_date)));
            update_post_meta($event_id, '_event_start_time', $times['start']);
            update_post_meta($event_id, '_event_end_time', $times['end']);
            update_post_meta($event_id, '_event_timezone', 'Africa/Johannesburg');
            update_post_meta($event_id, '_location_id', $location_id);
            update_post_meta($event_id, '_event_rsvp', 0);
			
    // NEW: Properly set the location using Events Manager API
    if (class_exists('EM_Event')) {
        $em_event = new EM_Event($event_id);
        if ($location_id) {
            $em_event->location_id = $location_id;
            $em_event->save();
        }
    }
            // Store booking details for easy access
            update_post_meta($event_id, '_booking_contact_person', $contact_person);
            update_post_meta($event_id, '_booking_email', $email);
            update_post_meta($event_id, '_booking_phone', $phone);
            update_post_meta($event_id, '_booking_space', $space);
            update_post_meta($event_id, '_booking_guests', $guest_count);
            update_post_meta($event_id, '_booking_status', 'pending_payment');
            update_post_meta($event_id, '_booking_event_title', $event_title);
            update_post_meta($event_id, '_booking_is_private', $is_private ? 1 : 0);
            update_post_meta($event_id, '_booking_event_description', $description);

            // Send notification email
            $this->send_admin_notification($event_id, $contact_person, $space, $event_date, $public_title);
        }
    }
    
    /**
     * Build admin notes (internal details)
     */
    private function build_admin_notes($data) {
        $content = "<div style='background: #fff3cd; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px; margin-bottom: 20px;'>";
        $content .= "<strong>🔍 STATUS: PENDING PAYMENT CONFIRMATION</strong><br>";
        $content .= "This booking requires manual approval once payment is verified.";
        $content .= "</div>";
        
        $content .= "<h3>📞 Contact Information</h3>";
        $content .= "<strong>Contact Person:</strong> {$data['contact_person']}<br>";
        if ($data['organization']) {
            $content .= "<strong>Organization:</strong> {$data['organization']}<br>";
        }
        $content .= "<strong>Email:</strong> <a href='mailto:{$data['email']}'>{$data['email']}</a><br>";
        $content .= "<strong>Phone:</strong> <a href='tel:{$data['phone']}'>{$data['phone']}</a><br>";
        
        $content .= "<h3>🏢 Booking Details</h3>";
        $content .= "<strong>Space Requested:</strong> {$data['space']}<br>";
        $content .= "<strong>Expected Guests:</strong> {$data['guest_count']}<br>";
        $content .= "<strong>Time:</strong> {$data['event_time']}<br>";
        if ($data['custom_hours']) {
            $content .= "<strong>Custom Hours:</strong> {$data['custom_hours']}<br>";
        }
        
        if ($data['setup_requirements']) {
            $content .= "<h3>⚙️ Setup Requirements</h3>";
            $content .= "<p>{$data['setup_requirements']}</p>";
        }
        
        if ($data['other_setup']) {
            $content .= "<strong>Additional Setup:</strong> {$data['other_setup']}<br>";
        }
        
        if ($data['catering'] && $data['catering'] != 'No catering') {
            $content .= "<h3>🍽️ Catering</h3>";
            $content .= "<p>{$data['catering']}</p>";
        }
        
        $content .= "<div style='background: #d4edda; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; margin-top: 20px;'>";
        $content .= "<h4>✅ To Approve This Booking:</h4>";
        $content .= "<ol>";
        $content .= "<li>Verify payment has been received</li>";
        $content .= "<li>Use the 'Quick Approve' button in the sidebar →</li>";
        $content .= "<li>Set visibility (Private/Public)</li>";
        $content .= "<li>Adjust event title and description as needed</li>";
        $content .= "</ol>";
        $content .= "</div>";
        
        return $content;
    }
    
    /**
     * Parse event times from form selection
     */
    private function parse_event_times($event_time, $custom_hours = '') {
        $times = [
            'Full Day (8am-12:00am)' => ['start' => '08:00:00', 'end' => '24:00:00'],
            'Morning (8am-1pm)' => ['start' => '08:00:00', 'end' => '13:00:00'],
            'Afternoon (1pm-6pm)' => ['start' => '13:00:00', 'end' => '18:00:00'],
            'Evening (6pm-12am)' => ['start' => '18:00:00', 'end' => '24:00:00']
        ];
        
        if (isset($times[$event_time])) {
            return $times[$event_time];
        }
        
        // Default times if custom or unknown
        return ['start' => '08:00:00', 'end' => '17:00:00'];
    }
    
    /**
     * Get location ID based on space selection
     */
    private function get_location_id($space) {
        $main_hall_id = get_option('hall_booking_main_hall_location', 0);
        $meeting_room_id = get_option('hall_booking_meeting_room_location', 0);
        
        if (strpos($space, 'Main Hall') !== false) {
            return $main_hall_id;
        } elseif (strpos($space, 'Meeting Room') !== false) {
            return $meeting_room_id;
        } elseif (strpos($space, 'Both') !== false) {
            return $main_hall_id; // Default to main hall for both
        }
        
        return $main_hall_id; // Default fallback
    }
    
    /**
     * Format checkbox arrays into readable text
     */
    private function format_array_field($field_data) {
        if (is_array($field_data)) {
            return implode(', ', array_map('sanitize_text_field', $field_data));
        }
        return sanitize_text_field($field_data);
    }
    
    /**
     * Send admin notification email
     */
    private function send_admin_notification($event_id, $contact_person, $space, $event_date, $public_title) {
        $admin_email = get_option('admin_email');
        $edit_url = admin_url("post.php?post={$event_id}&action=edit");
        
        $subject = "🏢 New Hall Booking: {$contact_person} - {$space}";
        
        $message = "A new booking request has been received and automatically created as a pending event.\n\n";
        $message .= "📋 BOOKING DETAILS:\n";
        $message .= "Contact: {$contact_person}\n";
        $message .= "Space: {$space}\n";
        $message .= "Date: {$event_date}\n";
        $message .= "Event: {$public_title}\n\n";
        $message .= "⚡ QUICK APPROVAL:\n";
        $message .= "1. Verify payment received\n";
        $message .= "2. Click here to approve: {$edit_url}\n";
        $message .= "3. Use the 'Quick Approve' button\n";
        $message .= "4. Set visibility (Private/Public)\n\n";
        $message .= "The event will automatically appear on your public calendar once approved.";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Handle event approval when post is saved
     */
    public function handle_event_approval($post_id, $post, $update) {
        if ($post->post_type !== 'event' || !$update) {
            return;
        }
        
        // If event was just published and has booking data, clean it up
        if ($post->post_status === 'publish' && get_post_meta($post_id, '_booking_status', true) === 'pending_payment') {
            // Update booking status
            update_post_meta($post_id, '_booking_status', 'approved');
            
            // Clean up the content - remove admin instructions
            $content = $post->post_content;
            if (strpos($content, 'STATUS: PENDING PAYMENT') !== false) {
                // Get the public description
                $public_description = get_post_meta($post_id, '_booking_event_description', true);
                if (!$public_description) {
                    $public_description = $post->post_excerpt ?: 'Private event at Sandbaai Hall';
                }
                
                // Check if this should be private or public
                $is_private = get_post_meta($post_id, '_booking_is_private', true);
                $public_title = $is_private ? 'Private Event' : (get_post_meta($post_id, '_booking_event_title', true) ?: $post->post_title);

                // Update the post title to reflect privacy setting
                wp_update_post([
                    'ID' => $post_id,
                    'post_title' => $public_title,
                    'post_content' => $public_description
                ]);
            }
        }
    }
    
    /**
     * Add admin menu for plugin settings
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=event',
            'Hall Booking Settings',
            'Booking Settings',
            'manage_options',
            'hall-booking-settings',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Plugin settings admin page
     */
    public function admin_page() {
        // Save settings
        if (isset($_POST['submit'])) {
            update_option('hall_booking_form_id', sanitize_text_field($_POST['form_id']));
            update_option('hall_booking_main_hall_location', intval($_POST['main_hall_location']));
            update_option('hall_booking_meeting_room_location', intval($_POST['meeting_room_location']));
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }

        // Handle delete booking action
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
        
        // Get CF7 forms
        $cf7_forms = get_posts(['post_type' => 'wpcf7_contact_form', 'posts_per_page' => -1]);
        
        // Get EM locations
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
                'post_status' => ['pending', 'publish']
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
                    echo '<td>' . ($booking->post_status === 'pending' ? 'Pending' : 'Approved') . '</td>';
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
    
    /**
     * Add quick approval metabox to event edit screen
     */
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
    
    /**
     * Quick approval metabox content
     */
    public function approval_metabox_content($post) {
        $booking_status = get_post_meta($post->ID, '_booking_status', true);
        
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
            echo '<strong>⚠️ Payment Status:</strong><br>';
            echo 'Verify payment before approving!';
            echo '</div>';
            
            echo '<div style="margin-bottom: 15px;">';
            echo '<label><strong>Event Visibility:</strong></label><br>';
            echo '<label><input type="radio" name="event_visibility" value="private"' . ($is_private ? ' checked' : '') . '> Private Event</label><br>';
            echo '<label><input type="radio" name="event_visibility" value="public"' . (!$is_private ? ' checked' : '') . '> Public Event</label>';
            echo '</div>';
            
            wp_nonce_field('approve_booking_nonce', 'approve_booking_nonce');
            echo '<button type="button" id="quick-approve-btn" class="button button-primary button-large" style="width: 100%; margin-bottom: 10px;">
                    ✅ Approve Booking
                  </button>';
            echo '<p style="font-size: 12px; color: #666;">This will publish the event and clean up the display for public viewing.</p>';
            
            ?>
            <script>
            document.getElementById('quick-approve-btn').addEventListener('click', function() {
                if (confirm('⚠️ CONFIRM: Has payment been received and verified?\n\nThis will approve the booking and make it visible based on your privacy setting.')) {
                    // Remove PENDING from title
                    var titleField = document.getElementById('title');
                    if (titleField && titleField.value.startsWith('PENDING: ')) {
                        titleField.value = titleField.value.replace('PENDING: ', '');
                    }
                    
                    // Change status to published
                    var statusRadios = document.querySelectorAll('input[name="post_status"]');
                    statusRadios.forEach(function(radio) {
                        if (radio.value === 'publish') {
                            radio.checked = true;
                        }
                    });
                    
                    // Update the status display
                    var statusDisplay = document.getElementById('post-status-display');
                    if (statusDisplay) {
                        statusDisplay.textContent = 'Published';
                    }
                    
                    // Set visibility meta
                    var visibilityRadios = document.querySelectorAll('input[name="event_visibility"]');
                    var selectedVisibility = document.querySelector('input[name="event_visibility"]:checked');
                    if (selectedVisibility) {
                        // This would need additional handling to save the meta value
                    }
                    
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
}

// Initialize the plugin
new HallBookingIntegration();

?>
