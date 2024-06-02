<?php
/*
Plugin Name: Simple Event Manager
Description: Allows logged-in users to create and manage events.
Version: 1.0
Author: Claudiu Meiu
*/

// Enqueue CSS file
function simple_event_manager_enqueue_styles() {
    wp_enqueue_style('simple-event-manager-style', plugin_dir_url(__FILE__) . 'simple-event-manager.css');
}
add_action('wp_enqueue_scripts', 'simple_event_manager_enqueue_styles');

// Register activation hook
register_activation_hook(__FILE__, 'simple_event_manager_create_tables');

// Create tables in the database
function simple_event_manager_create_tables() {
    global $wpdb;
    
    $table_name_events = $wpdb->prefix . 'user_events';
    $table_name_clicks = $wpdb->prefix . 'event_clicks';
    $charset_collate = $wpdb->get_charset_collate();

    // SQL statement for events table
    $sql_events = "CREATE TABLE $table_name_events (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id mediumint(9) NOT NULL,
        event_title varchar(255) NOT NULL,
        event_description text NOT NULL,
        start_date date NOT NULL,
        end_date date NOT NULL,
        event_location varchar(255) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // SQL statement for clicks table
    $sql_clicks = "CREATE TABLE $table_name_clicks (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        event_id mediumint(9) NOT NULL,
        user_id mediumint(9) NOT NULL,
        click_time datetime NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_click (event_id, user_id),
        FOREIGN KEY (event_id) REFERENCES $table_name_events(id)
    ) $charset_collate;";

    // Include upgrade.php
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Create events table
    $result_events = dbDelta($sql_events);
    
    // Create clicks table
    $result_clicks = dbDelta($sql_clicks);

    if ($result_events === false) {
        error_log("Failed to create events table: " . $wpdb->last_error);
    }
    
    if ($result_clicks === false) {
        error_log("Failed to create clicks table: " . $wpdb->last_error);
    }
}

// Event form shortcode function
function event_form_shortcode() {
    if (!is_user_logged_in()) {
        return '<p>You must be logged in to access this form.</p>';
    }
    ob_start(); // Start output buffering

    // Process form submission and add event
    if (isset($_POST['submit_event'])) {
        $message = add_event();
        // Redirect to the same page to prevent form resubmission
        wp_redirect($_SERVER['REQUEST_URI']);
        exit;
    }

    // Process event deletion
    if (isset($_POST['delete_event'])) {
        $event_id = isset($_POST['event_id']) ? sanitize_text_field($_POST['event_id']) : null;
        if ($event_id) {
            $deleted = delete_event($event_id);
        }
        // Redirect to the same page to prevent form resubmission
        wp_redirect($_SERVER['REQUEST_URI']);
        exit;
    }

    // Process unregister click
    if (isset($_POST['unregister_click'])) {
        $event_id = isset($_POST['unregister_click']) ? intval($_POST['unregister_click']) : null;
        if ($event_id) {
            unregister_click($event_id);
        }
        // Redirect to the same page to prevent form resubmission
        wp_redirect($_SERVER['REQUEST_URI']);
        exit;
    }

    // Display form
    ?>
    <div class="event-form">
        <form id="event-form" method="post">
            <p>
                <label for="event-title">Title:</label>
                <input type="text" id="event-title" name="event_title" required>
            </p>
            <p>
                <label for="event-description">Description:</label>
                <textarea id="event-description" name="event_description" required></textarea>
            </p>
            <p>
                <label for="start-date">Start date:</label>
                <input type="date" id="start-date" name="start_date" required>
            </p>
            <p>
                <label for="end-date">End date:</label>
                <input type="date" id="end-date" name="end_date" required>
            </p>
            <p>
                <label for="event-location">Location:</label>
                <textarea id="event-location" name="event_location" required></textarea>
            </p>
            <p>
                <input type="submit" name="submit_event" value="Add event">
            </p>
        </form>
    </div>
    <?php

    // Display all events
    $all_events = get_all_events();
    if ($all_events) {
        echo '<div class="all-events">';
        foreach ($all_events as $event) {
            echo '<div class="event">';
            echo '<div class="event-title"><h3>' . esc_html($event['event_title']) . '</h3></div>';
            echo '<p><strong>➡️ </strong> ';
            $event_description = preg_replace('/(https?:\/\/\S+)/', '<a href="$1" target="_blank">$1</a>', $event['event_description']);
            echo nl2br(html_entity_decode(esc_html($event_description))) . '</p>';
            echo '<p><strong>Start date: </strong> ' . esc_html($event['start_date']) . '</p>';
            echo '<p><strong>End date: </strong> ' . esc_html($event['end_date']) . '</p>';
            echo '<p><strong>Location: </strong> ';
            $event_location = preg_replace('/(https?:\/\/\S+)/', '<a href="$1" target="_blank">$1</a>', $event['event_location']);
            echo nl2br(html_entity_decode(esc_html($event_location))) . '</p>';
            
            // Get user nickname
            $user_info = get_userdata($event['user_id']);
            $user_nickname = $user_info->nickname;
            echo '<p><strong>Created by:</strong> ' . esc_html($user_nickname) . '</p>';

            // Display the users who clicked the button for this event
            $click_users = get_event_click_users($event['id']);
            if ($click_users) {
                echo '<p><strong>Participants:</strong> ';
                foreach ($click_users as $click_user) {
                    $click_user_info = get_userdata($click_user['user_id']);
                    $click_user_nickname = $click_user_info->nickname;
                    echo esc_html($click_user_nickname) . ', ';
                }
                echo '</p>';
            } else {
                echo '<p>No participants yet!</p>';
            }

            // Check if the logged-in user is the owner of the event
            $current_user_id = get_current_user_id();
            if ($current_user_id == $event['user_id']) {
                // If yes, display the delete button
                echo '<form method="post" class="delete-form">';
                echo '<input type="hidden" name="event_id" value="' . esc_attr($event['id']) . '">';
                echo '<input type="submit" name="delete_event" value="Delete" class="delete-button" style="background-color: #ff5733; padding: 5px 10px;">';
                echo '</form>';
            }

            // Check if the logged-in user has already clicked the button for this event
            $has_clicked = has_user_clicked_event($event['id'], $current_user_id);
            if (!$has_clicked) {
                // If not, display the register click button
                echo '<form method="post">';
                echo '<input type="hidden" name="register_click" value="' . esc_attr($event['id']) . '">';
                echo '<button type="submit" class="register-click-button" style="padding: 10px 10px;">Participate</button>';
                echo '</form>';
            } else {
                // If yes, display the unregister click button
                echo '<form method="post">';
                echo '<input type="hidden" name="unregister_click" value="' . esc_attr($event['id']) . '">';
                echo '<button type="submit" class="unregister-click-button" style="padding: 10px 10px; background: #ff5733;">Unregister</button>';
                echo '</form>';
            }

            echo '</div>';
        }
        echo '</div>';
    }

    return ob_get_clean(); // Return buffered output
}

add_shortcode('event_form', 'event_form_shortcode');


// Unregister Click
function unregister_click($event_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'event_clicks';
    $user_id = get_current_user_id();
    $unregistered = $wpdb->delete(
        $table_name,
        array(
            'event_id' => $event_id,
            'user_id' => $user_id
        ),
        array('%d', '%d')
    );
    return $unregistered;
}


// Delete Event
function delete_event($event_id) {
    global $wpdb;
    $table_name_events = $wpdb->prefix . 'user_events';
    $table_name_clicks = $wpdb->prefix . 'event_clicks';

    // Delete corresponding click records from event_clicks table
    $wpdb->delete($table_name_clicks, array('event_id' => $event_id), array('%d'));

    // Delete event
    $deleted_event = $wpdb->delete($table_name_events, array('id' => $event_id), array('%d'));

    return $deleted_event !== false; // Return true if event deletion was successful
}


// Retrieve All Events
function get_all_events() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'user_events';
    $all_events = $wpdb->get_results("SELECT * FROM $table_name ORDER BY start_date ASC", ARRAY_A);
    return $all_events;
}

// Add Event
function add_event() {
    if (isset($_POST['submit_event'])) {
        $user_id = get_current_user_id();
        $event_title = sanitize_text_field($_POST['event_title']);
        $event_description = sanitize_text_field($_POST['event_description']);
        $start_date = sanitize_text_field($_POST['start_date']);
        $end_date = sanitize_text_field($_POST['end_date']);
        $event_location = sanitize_text_field($_POST['event_location']);


        global $wpdb;
        $table_name = $wpdb->prefix . 'user_events';
        $insert_result = $wpdb->insert(
            $table_name,
            array(
                'user_id' => $user_id,
                'event_title' => $event_title,
                'event_description' => $event_description,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'event_location' => $event_location
            ),
            array('%d', '%s', '%s', '%s', '%s', '%s')
        );

        // No message returned
        return;
    }
}



// Register click action
function register_click_action() {
    if (isset($_POST['register_click'])) {
        $event_id = intval($_POST['register_click']);
        $user_id = get_current_user_id();

        // Check if the user has already clicked for this event
        if (has_user_clicked_event($event_id, $user_id)) {
            // User has already clicked, do nothing
            return;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'event_clicks';

        $inserted = $wpdb->insert(
            $table_name,
            array(
                'event_id' => $event_id,
                'user_id' => $user_id,
                'click_time' => current_time('mysql')
            ),
            array(
                '%d',
                '%d',
                '%s'
            )
        );

        if ($inserted !== false) {
            // Return true if click is successfully registered
            return true;
        } else {
            // Return false if there's an error registering click
            return false;
        }
    }
    // Return false if the register_click variable is not set
    return false;
}

add_action('init', 'register_click_action');

// Retrieve users who clicked on the button for a specific event
function get_event_click_users($event_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'event_clicks';
    $click_users = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT user_id FROM $table_name WHERE event_id = %d",
            $event_id
        ),
        ARRAY_A
    );
    return $click_users;
}

// Check if a user has already clicked for a specific event
function has_user_clicked_event($event_id, $user_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'event_clicks';
    $click_count = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE event_id = %d AND user_id = %d",
            $event_id,
            $user_id
        )
    );
    return $click_count > 0;
}

?>
