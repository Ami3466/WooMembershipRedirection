<?php
/*
Plugin Name: Custom Membership Redirects
Description: Set up custom redirections based on membership plans and page URLs.
Version: 1.1
Author: Your Name
*/

// Add a settings page to the WordPress admin menu
function cmr_add_admin_menu() {
    add_menu_page(
        'Membership Redirects', 
        'Membership Redirects', 
        'manage_options', 
        'cmr_settings', 
        'cmr_settings_page', 
        'dashicons-admin-generic', 
        80
    );
}
add_action('admin_menu', 'cmr_add_admin_menu');

// Create the settings page
function cmr_settings_page() {
    ?>
    <div class="wrap">
        <h1>Custom Membership Redirects</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('cmr_settings_group');
            do_settings_sections('cmr_settings');
            submit_button('Save Redirects');
            ?>
        </form>
        <div id="cmr-redirects-list">
            <h2>Configured Redirects</h2>
            <?php cmr_display_redirects(); ?>
        </div>
    </div>
    <?php
}

// Display existing redirects
function cmr_display_redirects() {
    $redirects = get_option('cmr_redirects', []);
    if (!empty($redirects)) {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Page URL</th><th>Membership Plans</th><th>Target URL</th><th>Actions</th></tr></thead>';
        echo '<tbody>';
        foreach ($redirects as $index => $redirect) {
            echo '<tr>';
            echo '<td>' . esc_html($redirect['page']) . '</td>';
            
            // Show membership plan names instead of IDs
            $plan_names = cmr_get_membership_plan_names($redirect['plans']);
            echo '<td>' . esc_html(implode(', ', $plan_names)) . '</td>';
            
            echo '<td>' . esc_html($redirect['target']) . '</td>';
            echo '<td>';
            echo '<button type="button" class="button cmr-delete-redirect" data-index="' . esc_attr($index) . '">Delete</button>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No redirects have been configured yet.</p>';
    }
}

// Helper function to get membership plan names
function cmr_get_membership_plan_names($plan_ids) {
    $plans = cmr_get_membership_plans();
    $plan_names = [];
    foreach ($plan_ids as $plan_id) {
        if (isset($plans[$plan_id])) {
            $plan_names[] = $plans[$plan_id];
        }
    }
    return $plan_names;
}

// Register settings, sections, and fields
function cmr_settings_init() {
    register_setting('cmr_settings_group', 'cmr_redirects');

    add_settings_section(
        'cmr_settings_section',
        'Configure Redirects',
        'cmr_settings_section_callback',
        'cmr_settings'
    );

    add_settings_field(
        'cmr_redirects_field',
        'Page Redirects',
        'cmr_redirects_field_callback',
        'cmr_settings',
        'cmr_settings_section'
    );
}
add_action('admin_init', 'cmr_settings_init');

function cmr_settings_section_callback() {
    echo '<p>Configure redirections for membership plans on specific pages.</p>';
}

// Display the redirection form in the settings page
function cmr_redirects_field_callback() {
    $redirects = get_option('cmr_redirects', []);

    // Output form fields
    echo '<div id="cmr-redirects-container">';
    cmr_redirect_row('0', '', [], ''); // Add an initial row for the user
    echo '</div>';

    echo '<button type="button" class="button" id="cmr-add-redirect">Add Redirect</button>';
}

// Helper function to generate each redirection row
function cmr_redirect_row($index, $page, $plans, $target) {
    $membership_plans = cmr_get_membership_plans();

    echo '<div class="cmr-row" data-index="' . esc_attr($index) . '">';
    
    // Page URL field
    echo '<label>Page URL: </label>';
    echo '<input type="text" name="cmr_redirects[' . esc_attr($index) . '][page]" value="' . esc_attr($page) . '" placeholder="e.g., /booking/" />';

    // Membership plans dropdown (multiple selection)
    echo '<label> Membership Plans: </label>';
    echo '<select name="cmr_redirects[' . esc_attr($index) . '][plans][]" multiple>';
    foreach ($membership_plans as $plan_id => $plan_name) {
        $selected = in_array($plan_id, $plans) ? 'selected' : '';
        echo '<option value="' . esc_attr($plan_id) . '" ' . $selected . '>' . esc_html($plan_name) . '</option>';
    }
    echo '</select>';

    // Target URL field
    echo '<label> Target URL: </label>';
    echo '<input type="text" name="cmr_redirects[' . esc_attr($index) . '][target]" value="' . esc_attr($target) . '" placeholder="e.g., /new-url/" />';

    echo '</div>';
}

// Get membership plans from WooCommerce Memberships (SkyVerge)
function cmr_get_membership_plans() {
    if (!function_exists('wc_memberships_get_membership_plans')) {
        return [];
    }

    $plans = wc_memberships_get_membership_plans();
    $options = [];

    foreach ($plans as $plan) {
        $options[$plan->get_id()] = $plan->get_name();
    }

    return $options;
}

// Handle the redirections
function cmr_custom_user_redirect() {
    $redirects = get_option('cmr_redirects', []);
    $curr_user_id = get_current_user_id();

    // Get all active memberships for the current user
    $customer_memberships = wc_memberships_get_user_memberships($curr_user_id);

    // Get active membership plan IDs for the user
    $user_plan_ids = [];
    foreach ($customer_memberships as $membership) {
        if ($membership->get_status() === 'active') {
            $user_plan_ids[] = $membership->get_plan_id();
        }
    }

    // Loop through saved redirects
    foreach ($redirects as $redirect_data) {
        $page = $redirect_data['page'];
        $plans = $redirect_data['plans'];
        $target = $redirect_data['target'];

        // Check if the current page matches and if any user plan is in the configured plans
        if (strpos($_SERVER['REQUEST_URI'], $page) !== false && array_intersect($user_plan_ids, $plans)) {
            wp_redirect($target);
            exit;
        }
    }
}
add_action('template_redirect', 'cmr_custom_user_redirect');

// Add JavaScript for adding/removing rows and improving UI
function cmr_enqueue_admin_scripts($hook_suffix) {
    if ($hook_suffix !== 'toplevel_page_cmr_settings') {
        return;
    }
    ?>
    <script type="text/javascript">
        document.addEventListener('DOMContentLoaded', function () {
            const container = document.getElementById('cmr-redirects-container');
            const addButton = document.getElementById('cmr-add-redirect');

            // Add new redirect row
            addButton.addEventListener('click', function () {
                const rowCount = container.children.length;
                const newRow = container.querySelector('.cmr-row').cloneNode(true);
                newRow.querySelectorAll('input, select').forEach(input => {
                    input.name = input.name.replace('[0]', '[' + rowCount + ']');
                    input.value = '';
                });
                container.appendChild(newRow);
            });

            // Remove redirect row
            container.addEventListener('click', function (e) {
                if (e.target.classList.contains('cmr-remove-redirect')) {
                    e.target.closest('.cmr-row').remove();
                }
            });

            // Delete redirect row from server
            document.querySelectorAll('.cmr-delete-redirect').forEach(deleteButton => {
                deleteButton.addEventListener('click', function () {
                    const index = this.dataset.index;
                    const confirmed = confirm("Are you sure you want to delete this redirect?");
                    if (!confirmed) return;

                    let redirects = <?php echo json_encode(get_option('cmr_redirects', [])); ?>;
                    redirects.splice(index, 1); // Remove the specific rule by index

                    jQuery.post(ajaxurl, {
                        action: 'cmr_save_redirects',
                        redirects: redirects
                    }, function(response) {
                        location.reload(); // Reload page to reflect changes
                    });
                });
            });
        });
    </script>
    <?php
            }
add_action('admin_enqueue_scripts', 'cmr_enqueue_admin_scripts');

// Save redirects
function cmr_save_redirects() {
    if (isset($_POST['redirects'])) {
        update_option('cmr_redirects', $_POST['redirects']);
        wp_send_json_success();
    }
    wp_send_json_error();
}
add_action('wp_ajax_cmr_save_redirects', 'cmr_save_redirects');

