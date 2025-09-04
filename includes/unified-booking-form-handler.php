<?php
if (!defined('ABSPATH')) exit;

// Unified Booking Form Handler for Sandbaai Hall

add_action('admin_post_nopriv_hall_booking_quote_submit', 'hall_booking_quote_submit');
add_action('admin_post_hall_booking_quote_submit', 'hall_booking_quote_submit');

function hall_booking_quote_submit() {
    // 1. Validate/sanitize fields
    $contact_person = sanitize_text_field($_POST['contact_person'] ?? '');
    $organization = sanitize_text_field($_POST['organization'] ?? '');
    $email = sanitize_email($_POST['email'] ?? '');
    $phone = sanitize_text_field($_POST['phone'] ?? '');
    $space = sanitize_text_field($_POST['space'] ?? '');
    $event_title = sanitize_text_field($_POST['event_title'] ?? '');
    $event_description = sanitize_textarea_field($_POST['event_description'] ?? '');
    $event_privacy = strtolower(sanitize_text_field($_POST['event_privacy'] ?? 'private'));
    $guest_count = intval($_POST['guest_count'] ?? 0);

    // Dates
    $event_start_date = sanitize_text_field($_POST['event_start_date'] ?? '');
    $event_end_date = sanitize_text_field($_POST['event_end_date'] ?? '');

    $event_time = sanitize_text_field($_POST['event_time'] ?? '');
    $custom_start = sanitize_text_field($_POST['custom_start'] ?? '');
    $custom_end = sanitize_text_field($_POST['custom_end'] ?? '');
    $setup_requirements = isset($_POST['setup']) ? implode(', ', array_map('sanitize_text_field', $_POST['setup'])) : '';
    $other_setup = sanitize_text_field($_POST['other_setup'] ?? '');
    $catering = sanitize_text_field($_POST['catering'] ?? '');
    $agreement_1 = !empty($_POST['agreement_1']);
    $agreement_2 = !empty($_POST['agreement_2']);

    // 2. Process tariffs/quote
    $tariff_items = $_POST['tariff'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $tariffs = get_option('hall_tariffs', []);
    $items = [];
    $total = 0;
    foreach ($tariff_items as $category => $cat_items) {
        foreach ($cat_items as $label => $checked) {
            $qty = isset($quantities[$category][$label]) ? intval($quantities[$category][$label]) : 1;
            $price = isset($tariffs[$category][$label]) ? floatval($tariffs[$category][$label]) : 0;
            $items[] = [
                'category' => $category,
                'label' => $label,
                'quantity' => $qty,
                'price' => $price,
                'subtotal' => $qty * $price,
            ];
            $total += $qty * $price;
        }
    }

    // 3. Create draft event
    $pending_title = "PENDING: " . ($event_title ?: 'Event');
    $public_description = $event_description ?: 'Private event at Sandbaai Hall';
    $event_id = wp_insert_post([
        'post_title'     => $pending_title,
        'post_content'   => $public_description,
        'post_excerpt'   => $public_description,
        'post_status'    => 'draft',
        'post_type'      => 'event',
        'post_author'    => 1,
        'comment_status' => 'closed'
    ]);
    if ($event_id) {
        update_post_meta($event_id, '_event_start_date', date('Y-m-d', strtotime($event_start_date)));
        update_post_meta($event_id, '_event_end_date', date('Y-m-d', strtotime($event_end_date)));
        update_post_meta($event_id, '_event_start_time', $custom_start ?: '08:00:00');
        update_post_meta($event_id, '_event_end_time', $custom_end ?: '17:00:00');
        update_post_meta($event_id, '_event_timezone', 'Africa/Johannesburg');
        update_post_meta($event_id, '_booking_contact_person', $contact_person);
        update_post_meta($event_id, '_booking_email', $email);
        update_post_meta($event_id, '_booking_phone', $phone);
        update_post_meta($event_id, '_booking_space', $space);
        update_post_meta($event_id, '_booking_guests', $guest_count);
        update_post_meta($event_id, '_booking_status', 'pending_payment');
        update_post_meta($event_id, '_booking_event_title', $event_title);
        update_post_meta($event_id, '_booking_is_private', $event_privacy === 'private' ? 1 : 0);
        update_post_meta($event_id, '_booking_event_description', $event_description);
        update_post_meta($event_id, '_booking_custom_start', $custom_start);
        update_post_meta($event_id, '_booking_custom_end', $custom_end);
        update_post_meta($event_id, '_booking_setup_requirements', $setup_requirements);
        update_post_meta($event_id, '_booking_other_setup', $other_setup);
        update_post_meta($event_id, '_booking_catering', $catering);
        update_post_meta($event_id, '_booking_quote_items', $items);
        update_post_meta($event_id, '_booking_quote_total', $total);
    } else {
        error_log("Sandbaai Hall: Failed to create draft event for booking!");
    }

    // 4. Create draft invoice post
    $invoice_id = wp_insert_post([
        'post_title'   => "Invoice for {$space} booking from {$event_start_date} to {$event_end_date}",
        'post_type'    => 'hall_invoice',
        'post_status'  => 'draft',
        'post_content' => "Booking for {$space} ({$event_time}) from {$event_start_date} to {$event_end_date}. Guests: {$guest_count}.",
        'post_author'  => 1,
    ]);
    if ($invoice_id) {
        update_post_meta($invoice_id, '_linked_event', $event_id);
        update_post_meta($invoice_id, '_invoice_contact', $contact_person);
        update_post_meta($invoice_id, '_invoice_email', $email);
        update_post_meta($invoice_id, '_invoice_items', $items);
        update_post_meta($invoice_id, '_invoice_total', $total);
        update_post_meta($invoice_id, '_invoice_status', 'pending');
    } else {
        error_log("Sandbaai Hall: Failed to create draft invoice for booking!");
    }

    // 5. Send notification email to booking admin
    $admin_email = get_option('admin_email');
    $subject = "🏢 New Hall Booking: {$contact_person} - {$space}";
    $message = "A new booking request has been received and created as a draft event.\n\n";
    $message .= "Contact: {$contact_person}\nEmail: {$email}\nPhone: {$phone}\nSpace: {$space}\nDate: {$event_start_date} to {$event_end_date}\nGuests: {$guest_count}\nEvent: {$event_title}\n\nQuote:\n";
    foreach ($items as $item) {
        $message .= "{$item['category']} - {$item['label']} x{$item['quantity']}: R " . number_format($item['subtotal'], 2) . "\n";
    }
    $message .= "\nTotal: R " . number_format($total, 2);
    $message .= "\n\nReview and send the invoice via the admin dashboard.";
    if ($admin_email) {
        wp_mail($admin_email, $subject, $message);
    } else {
        error_log("Sandbaai Hall: Admin email not set in options.");
    }

    // 6. Send confirmation email to user
    $user_subject = "Sandbaai Hall Booking Request Received";
    $user_message = "Dear {$contact_person},\n\nThank you for your booking request.\n\nBooking Summary:\nSpace: {$space}\nDate: {$event_start_date} to {$event_end_date}\nTime: {$event_time}\nGuests: {$guest_count}\nEvent: {$event_title}\n\nQuote:\n";
    foreach ($items as $item) {
        $user_message .= "{$item['category']} - {$item['label']} x{$item['quantity']}: R " . number_format($item['subtotal'], 2) . "\n";
    }
    $user_message .= "\nTotal: R " . number_format($total, 2);
    $user_message .= "\n\nWe will review your request and send a formal invoice. Your booking is confirmed once payment is received.";
    if ($email) {
        wp_mail($email, $user_subject, $user_message);
    }

    // 7. Redirect to thank you page
    wp_redirect('/thank-you/');
    exit;
}

// No closing PHP tag needed for files containing only PHP.
