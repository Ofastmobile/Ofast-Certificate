<?php

/**
 * Shortcodes for Certificate System
 * Contains: Student Request, Vendor Request, Verification, My Certificates
 */

if (!defined('ABSPATH')) exit;

/**
 * =====================================================
 * STUDENT REQUEST FORM SHORTCODE
 * Shortcode: [cert_student_request]
 * =====================================================
 */
function ofst_cert_student_request_form()
{
    if (!is_user_logged_in()) {
        return '<div class="ofst-cert-notice error">Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to request a certificate.</div>';
    }

    $user_id = get_current_user_id();
    $user = wp_get_current_user();
    $products = ofst_cert_get_user_products($user_id);
    $min_days = ofst_cert_get_setting('min_days_after_purchase', 3);
    $turnstile_site_key = ofst_cert_get_setting('turnstile_site_key');

    ob_start();
?>

    <div class="ofst-cert-form-container">
        <div class="ofst-cert-card">
            <h2 class="ofst-cert-title">Request Certificate</h2>
            <p class="ofst-cert-subtitle">Complete the form below to request your course completion certificate.</p>

            <?php if (empty($products)): ?>
                <div class="ofst-cert-notice info">
                    <p><strong>No courses available for certification yet.</strong></p>
                    <p>You can request a certificate for courses purchased at least <?php echo esc_html($min_days); ?> days ago and completed.</p>
                    <p>If you believe this is an error, please contact <a href="mailto:<?php echo esc_attr(ofst_cert_get_setting('support_email')); ?>">support</a>.</p>
                </div>
            <?php else: ?>

                <form id="ofst-student-cert-form" method="post" class="ofst-cert-form">
                    <?php wp_nonce_field('ofst_student_cert_request', 'ofst_student_cert_nonce'); ?>

                    <div class="ofst-form-row">
                        <div class="ofst-form-group ofst-half">
                            <label for="first_name">First Name <span class="required">*</span></label>
                            <input type="text" id="first_name" name="first_name" value="<?php echo esc_attr($user->first_name); ?>" required>
                        </div>

                        <div class="ofst-form-group ofst-half">
                            <label for="last_name">Last Name <span class="required">*</span></label>
                            <input type="text" id="last_name" name="last_name" value="<?php echo esc_attr($user->last_name); ?>" required>
                        </div>
                    </div>

                    <div class="ofst-form-row">
                        <div class="ofst-form-group ofst-half">
                            <label for="email">Email Address <span class="required">*</span></label>
                            <input type="email" id="email" name="email" value="<?php echo esc_attr($user->user_email); ?>" required>
                        </div>

                        <div class="ofst-form-group ofst-half">
                            <label for="phone">Phone Number <span class="required">*</span></label>
                            <input type="tel" id="phone" name="phone" placeholder="+1 234 567 8900" required>
                        </div>
                    </div>

                    <div class="ofst-form-group">
                        <label for="product_id">Course Completed <span class="required">*</span></label>
                        <select id="product_id" name="product_id" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo esc_attr($product['id']); ?>">
                                    <?php echo esc_html($product['name']); ?>
                                    (Purchased: <?php echo esc_html(date('M d, Y', strtotime($product['purchased_date']))); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ofst-form-group">
                        <label for="project_link">Project Link (Optional)</label>
                        <input type="url" id="project_link" name="project_link" placeholder="https://your-project-url.com">
                        <small>If you completed a project as part of this course, you can share the link here.</small>
                    </div>

                    <div class="ofst-form-group ofst-checkbox">
                        <label>
                            <input type="checkbox" name="declaration" required>
                            <span>I confirm that I have completed all course materials and requirements. <span class="required">*</span></span>
                        </label>
                    </div>

                    <?php if (!empty($turnstile_site_key)): ?>
                        <div class="ofst-form-group">
                            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"></div>
                        </div>
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    <?php endif; ?>

                    <div class="ofst-form-actions">
                        <button type="submit" name="ofst_submit_student_request" class="ofst-btn ofst-btn-primary">
                            <span class="btn-text">Request Certificate</span>
                            <span class="btn-loader" style="display:none;">Processing...</span>
                        </button>
                    </div>
                </form>

            <?php endif; ?>
        </div>
    </div>

<?php
    return ob_get_clean();
}
add_shortcode('cert_student_request', 'ofst_cert_student_request_form');

// Process student certificate request
add_action('init', 'ofst_cert_process_student_request');
function ofst_cert_process_student_request()
{
    if (!isset($_POST['ofst_submit_student_request'])) {
        return;
    }

    // Verify nonce
    if (
        !isset($_POST['ofst_student_cert_nonce']) ||
        !wp_verify_nonce($_POST['ofst_student_cert_nonce'], 'ofst_student_cert_request')
    ) {
        wp_die('Security check failed');
    }

    // Check user is logged in
    if (!is_user_logged_in()) {
        wp_die('You must be logged in to request a certificate');
    }

    $user_id = get_current_user_id();

    // Verify Turnstile
    $turnstile_token = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
    if (!empty(ofst_cert_get_setting('turnstile_secret_key')) && !ofst_cert_verify_turnstile($turnstile_token)) {
        wp_die('Security verification failed. Please try again.');
    }

    // Sanitize and validate inputs
    $first_name = sanitize_text_field($_POST['first_name']);
    $last_name = sanitize_text_field($_POST['last_name']);
    $email = sanitize_email($_POST['email']);
    $phone = ofst_cert_sanitize_phone($_POST['phone']);
    $product_id = absint($_POST['product_id']);
    $project_link = !empty($_POST['project_link']) ? esc_url_raw($_POST['project_link']) : null;

    // Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($phone) || empty($product_id)) {
        wp_die('All required fields must be filled');
    }

    if (!is_email($email)) {
        wp_die('Invalid email address');
    }

    // Check for duplicate
    $duplicate = ofst_cert_check_duplicate($user_id, $product_id);
    if ($duplicate) {
        $status_text = $duplicate->status === 'pending' ? 'pending review' : 'already issued';
        wp_die('You already have a certificate request for this course that is ' . $status_text . '. Please contact ' . ofst_cert_get_setting('support_email') . ' for assistance.');
    }

    // Verify user purchased this product
    $has_purchased = false;
    $purchase_date = null;
    $orders = wc_get_orders(array(
        'customer_id' => $user_id,
        'status' => array('wc-completed', 'wc-processing'),
        'limit' => -1
    ));

    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            if ($item->get_product_id() == $product_id) {
                $has_purchased = true;
                $purchase_date = $order->get_date_created()->date('Y-m-d H:i:s');
                break 2;
            }
        }
    }

    if (!$has_purchased) {
        wp_die('You have not purchased this course');
    }

    // Check minimum days requirement
    $min_days = (int) ofst_cert_get_setting('min_days_after_purchase', 3);
    $min_date = date('Y-m-d H:i:s', strtotime("-$min_days days"));

    if ($purchase_date > $min_date) {
        wp_die("You can request a certificate $min_days days after purchase. Please try again later.");
    }

    // Get product details
    $product = wc_get_product($product_id);
    $product_name = $product ? $product->get_name() : 'Unknown Product';

    // Get vendor/instructor info
    $vendor_id = null;
    $instructor_name = null;

    if (function_exists('dokan_get_vendor_by_product')) {
        $vendor = dokan_get_vendor_by_product($product_id);
        if ($vendor) {
            $vendor_id = $vendor->get_id();
            $vendor_user = get_userdata($vendor_id);
            $instructor_name = $vendor_user->display_name;
        }
    }

    // Generate certificate ID
    $cert_id = ofst_cert_generate_id();

    // Insert into database
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $inserted = $wpdb->insert($table, array(
        'user_id' => $user_id,
        'certificate_id' => $cert_id,
        'request_type' => 'student',
        'first_name' => $first_name,
        'last_name' => $last_name,
        'email' => $email,
        'phone' => $phone,
        'product_id' => $product_id,
        'product_name' => $product_name,
        'project_link' => $project_link,
        'instructor_name' => $instructor_name,
        'vendor_id' => $vendor_id,
        'status' => 'pending',
        'requested_date' => current_time('mysql')
    ));

    if ($inserted) {
        // Send confirmation email to student
        ofst_cert_send_student_confirmation($email, $first_name, $product_name, $cert_id);

        // Send notification to admin
        ofst_cert_send_admin_notification($cert_id, 'student', $first_name . ' ' . $last_name, $product_name);

        // Send notification to vendor (if exists)
        if ($vendor_id) {
            ofst_cert_send_vendor_notification($vendor_id, $first_name . ' ' . $last_name, $product_name, $cert_id);
        }

        // Redirect with success message
        $redirect_url = add_query_arg('cert_success', 'student_request', wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    } else {
        wp_die('Failed to submit request. Please try again or contact support.');
    }
}

// Display success message
add_action('wp_head', 'ofst_cert_display_messages');
function ofst_cert_display_messages()
{
    if (isset($_GET['cert_success'])) {
        $type = sanitize_text_field($_GET['cert_success']);
        $messages = array(
            'student_request' => 'Your certificate request has been submitted successfully! You will receive your certificate via email once verified by our team.',
            'vendor_request' => 'Certificate request submitted successfully! The student will be notified once approved.',
            'cert_verified' => 'Certificate verified successfully!'
        );

        if (isset($messages[$type])) {
            echo '<div class="ofst-cert-notice success" style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 15px; margin: 20px; border-radius: 4px;">' . esc_html($messages[$type]) . '</div>';
        }
    }
}

/**
 * =====================================================
 * VENDOR REQUEST FORM SHORTCODE  
 * Shortcode: [cert_vendor_request]
 * =====================================================
 */
function ofst_cert_vendor_request_form()
{
    if (!is_user_logged_in()) {
        return '<div class="ofst-cert-notice error">Please log in to access this page.</div>';
    }

    $user = wp_get_current_user();
    $is_vendor = in_array('seller', $user->roles) || in_array('vendor', $user->roles) || in_array('dokandar', $user->roles) || in_array('instructor', $user->roles);

    if (!$is_vendor && !current_user_can('manage_options')) {
        return '<div class="ofst-cert-notice error">Only vendors can access this page.</div>';
    }

    $vendor_id = get_current_user_id();
    $products = ofst_cert_get_vendor_products($vendor_id);
    $turnstile_site_key = ofst_cert_get_setting('turnstile_site_key');

    ob_start();
?>

    <div class="ofst-cert-form-container">
        <div class="ofst-cert-back-btn">
            <a href="#" onclick="history.back(); return false;" class="ofst-btn-back">← Back to Dashboard</a>
        </div>

        <div class="ofst-cert-card">
            <h2 class="ofst-cert-title">Request Student Certificate</h2>
            <p class="ofst-cert-subtitle">Submit a certificate request for a student who has completed your course.</p>

            <?php if (empty($products)): ?>
                <div class="ofst-cert-notice info">
                    <p>You don't have any published products yet.</p>
                </div>
            <?php else: ?>

                <form id="ofst-vendor-cert-form" method="post" class="ofst-cert-form">
                    <?php wp_nonce_field('ofst_vendor_cert_request', 'ofst_vendor_cert_nonce'); ?>

                    <div class="ofst-form-row">
                        <div class="ofst-form-group ofst-half">
                            <label for="student_first_name">Student First Name <span class="required">*</span></label>
                            <input type="text" id="student_first_name" name="student_first_name" required>
                        </div>

                        <div class="ofst-form-group ofst-half">
                            <label for="student_last_name">Student Last Name <span class="required">*</span></label>
                            <input type="text" id="student_last_name" name="student_last_name" required>
                        </div>
                    </div>

                    <div class="ofst-form-row">
                        <div class="ofst-form-group ofst-half">
                            <label for="student_email">Student Email <span class="required">*</span></label>
                            <input type="email" id="student_email" name="student_email" required>
                            <small>Student must be registered on the website</small>
                        </div>

                        <div class="ofst-form-group ofst-half">
                            <label for="student_phone">Student Phone <span class="required">*</span></label>
                            <input type="tel" id="student_phone" name="student_phone" required>
                        </div>
                    </div>

                    <div class="ofst-form-group">
                        <label for="vendor_product_id">Course/Product <span class="required">*</span></label>
                        <select id="vendor_product_id" name="vendor_product_id" required>
                            <option value="">-- Select Course --</option>
                            <?php foreach ($products as $id => $name): ?>
                                <option value="<?php echo esc_attr($id); ?>"><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="ofst-form-row">
                        <div class="ofst-form-group ofst-half">
                            <label for="instructor_name">Instructor Full Name <span class="required">*</span></label>
                            <input type="text" id="instructor_name" name="instructor_name" value="<?php echo esc_attr($user->display_name); ?>" required>
                            <small>Your name or the course instructor's name</small>
                        </div>

                        <div class="ofst-form-group ofst-half">
                            <label for="completion_date">Completion Date <span class="required">*</span></label>
                            <input type="date" id="completion_date" name="completion_date" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                    </div>

                    <div class="ofst-form-group">
                        <label for="vendor_notes">Additional Notes (Optional)</label>
                        <textarea id="vendor_notes" name="vendor_notes" rows="4" placeholder="Any additional information about the student's completion..."></textarea>
                    </div>

                    <?php if (!empty($turnstile_site_key)): ?>
                        <div class="ofst-form-group">
                            <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"></div>
                        </div>
                        <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                    <?php endif; ?>

                    <div class="ofst-form-actions">
                        <button type="submit" name="ofst_submit_vendor_request" class="ofst-btn ofst-btn-primary">
                            <span class="btn-text">Submit Certificate Request</span>
                            <span class="btn-loader" style="display:none;">Processing...</span>
                        </button>
                    </div>
                </form>

            <?php endif; ?>
        </div>
    </div>

<?php
    return ob_get_clean();
}
add_shortcode('cert_vendor_request', 'ofst_cert_vendor_request_form');

// Process vendor request (implementation continues...)
add_action('init', 'ofst_cert_process_vendor_request');
function ofst_cert_process_vendor_request()
{
    if (!isset($_POST['ofst_submit_vendor_request'])) {
        return;
    }

    // Verify nonce
    if (
        !isset($_POST['ofst_vendor_cert_nonce']) ||
        !wp_verify_nonce($_POST['ofst_vendor_cert_nonce'], 'ofst_vendor_cert_request')
    ) {
        wp_die('Security check failed');
    }

    // Check user is vendor
    if (!is_user_logged_in()) {
        wp_die('You must be logged in');
    }

    $user = wp_get_current_user();
    $is_vendor = in_array('seller', $user->roles) || in_array('vendor', $user->roles) || in_array('dokandar', $user->roles) || in_array('instructor', $user->roles);

    if (!$is_vendor && !current_user_can('manage_options')) {
        wp_die('Only vendors can submit certificate requests');
    }

    $vendor_id = get_current_user_id();

    // Verify Turnstile
    $turnstile_token = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
    if (!empty(ofst_cert_get_setting('turnstile_secret_key')) && !ofst_cert_verify_turnstile($turnstile_token)) {
        wp_die('Security verification failed. Please try again.');
    }

    // Sanitize inputs
    $student_first = sanitize_text_field($_POST['student_first_name']);
    $student_last = sanitize_text_field($_POST['student_last_name']);
    $student_email = sanitize_email($_POST['student_email']);
    $student_phone = ofst_cert_sanitize_phone($_POST['student_phone']);
    $product_id = absint($_POST['vendor_product_id']);
    $instructor_name = sanitize_text_field($_POST['instructor_name']);
    $completion_date = sanitize_text_field($_POST['completion_date']);
    $vendor_notes = !empty($_POST['vendor_notes']) ? sanitize_textarea_field($_POST['vendor_notes']) : null;

    // Validation
    if (
        empty($student_first) || empty($student_last) || empty($student_email) ||
        empty($student_phone) || empty($product_id) || empty($instructor_name) || empty($completion_date)
    ) {
        wp_die('All required fields must be filled');
    }

    if (!is_email($student_email)) {
        wp_die('Invalid email address');
    }

    // Verify student exists
    $student = get_user_by('email', $student_email);
    if (!$student) {
        wp_die('Student not found. Please ensure the student is registered on the website.');
    }

    $student_id = $student->ID;

    // Verify product belongs to vendor
    $product = wc_get_product($product_id);
    if (!$product || $product->get_post_data()->post_author != $vendor_id) {
        wp_die('Invalid product selection');
    }

    // Check for duplicate
    $duplicate = ofst_cert_check_duplicate($student_id, $product_id);
    if ($duplicate) {
        wp_die('A certificate for this student and course already exists or is pending. Certificate ID: ' . $duplicate->certificate_id . '. Please contact ' . ofst_cert_get_setting('support_email') . ' for assistance.');
    }

    // Verify student purchased the product
    $has_purchased = false;
    $orders = wc_get_orders(array(
        'customer_id' => $student_id,
        'status' => array('wc-completed', 'wc-processing'),
        'limit' => -1
    ));

    foreach ($orders as $order) {
        foreach ($order->get_items() as $item) {
            if ($item->get_product_id() == $product_id) {
                $has_purchased = true;
                break 2;
            }
        }
    }

    if (!$has_purchased) {
        wp_die('This student has not purchased the selected course');
    }

    // Generate certificate ID
    $cert_id = ofst_cert_generate_id();

    // Insert into database
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $inserted = $wpdb->insert($table, array(
        'user_id' => $student_id,
        'certificate_id' => $cert_id,
        'request_type' => 'vendor',
        'first_name' => $student_first,
        'last_name' => $student_last,
        'email' => $student_email,
        'phone' => $student_phone,
        'product_id' => $product_id,
        'product_name' => $product->get_name(),
        'instructor_name' => $instructor_name,
        'vendor_id' => $vendor_id,
        'vendor_notes' => $vendor_notes,
        'completion_date' => $completion_date,
        'status' => 'pending',
        'requested_date' => current_time('mysql')
    ));

    if ($inserted) {
        // Send notification to admin
        ofst_cert_send_admin_notification($cert_id, 'vendor', $student_first . ' ' . $student_last, $product->get_name(), $user->display_name);

        // Redirect with success
        $redirect_url = add_query_arg('cert_success', 'vendor_request', wp_get_referer());
        wp_redirect($redirect_url);
        exit;
    } else {
        wp_die('Failed to submit request. Please try again.');
    }
}

/**
 * =====================================================
 * PUBLIC VERIFICATION SHORTCODE
 * Shortcode: [cert_verify]
 * =====================================================
 */
function ofst_cert_verification_form()
{
    $turnstile_site_key = ofst_cert_get_setting('turnstile_site_key');

    ob_start();
?>
    <div class="ofst-cert-verify-container">
        <div class="ofst-cert-card">
            <h2 class="ofst-cert-title">Verify Certificate</h2>
            <p class="ofst-cert-subtitle">Enter certificate details to verify authenticity</p>

            <form id="ofst-verify-form" method="post" class="ofst-cert-form">
                <?php wp_nonce_field('ofst_verify_cert', 'ofst_verify_nonce'); ?>

                <div class="ofst-form-group">
                    <label for="cert_search">Certificate ID or Student Name</label>
                    <input type="text" id="cert_search" name="cert_search" placeholder="OFSHDG2024001 or John Doe" required>
                </div>

                <?php if (!empty($turnstile_site_key)): ?>
                    <div class="ofst-form-group">
                        <div class="cf-turnstile" data-sitekey="<?php echo esc_attr($turnstile_site_key); ?>"></div>
                    </div>
                    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
                <?php endif; ?>

                <button type="submit" name="ofst_submit_verify" class="ofst-btn ofst-btn-primary">Verify Certificate</button>
            </form>

            <?php
            // Display verification results if form submitted
            if (isset($_POST['ofst_submit_verify'])) {
                ofst_cert_process_verification();
            }
            ?>
        </div>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('cert_verify', 'ofst_cert_verification_form');

function ofst_cert_process_verification()
{
    // Nonce verification
    if (!wp_verify_nonce($_POST['ofst_verify_nonce'], 'ofst_verify_cert')) {
        echo '<div class="ofst-cert-notice error">Security check failed</div>';
        return;
    }

    // Turnstile verification
    $turnstile_token = isset($_POST['cf-turnstile-response']) ? $_POST['cf-turnstile-response'] : '';
    if (!empty(ofst_cert_get_setting('turnstile_secret_key')) && !ofst_cert_verify_turnstile($turnstile_token)) {
        echo '<div class="ofst-cert-notice error">Security verification failed</div>';
        return;
    }

    $search = sanitize_text_field($_POST['cert_search']);

    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    // Search by certificate ID or name
    $cert = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table 
        WHERE (certificate_id = %s OR CONCAT(first_name, ' ', last_name) LIKE %s OR email = %s)
        AND status = 'issued'
        LIMIT 1",
        $search,
        '%' . $wpdb->esc_like($search) . '%',
        $search
    ));

    if ($cert) {
        // Log successful verification
        ofst_cert_log_verification($cert->certificate_id, 'search', $search, 'found');

        $current_user_id = get_current_user_id();
        $is_owner = ($current_user_id == $cert->user_id);

        echo '<div class="ofst-cert-result success">';
        echo '<h3>✓ Certificate Verified</h3>';
        echo '<div class="cert-details">';
        echo '<p><strong>Certificate ID:</strong> ' . esc_html($cert->certificate_id) . '</p>';
        echo '<p><strong>Student:</strong> ' . esc_html($cert->first_name . ' ' . $cert->last_name) . '</p>';
        echo '<p><strong>Course:</strong> ' . esc_html($cert->product_name) . '</p>';
        echo '<p><strong>Issued:</strong> ' . esc_html(date('F d, Y', strtotime($cert->processed_date))) . '</p>';

        if ($is_owner && !empty($cert->certificate_file)) {
            echo '<p><a href="' . esc_url($cert->certificate_file) . '" class="ofst-btn ofst-btn-primary" download>Download Certificate</a></p>';
        }
        echo '</div></div>';
    } else {
        // Log failed verification
        ofst_cert_log_verification('N/A', 'search', $search, 'not_found');

        echo '<div class="ofst-cert-result error">';
        echo '<h3>Certificate Not Found</h3>';
        echo '<p>No certificate found matching your search. Please verify:</p>';
        echo '<ul><li>Certificate ID is correct</li><li>Certificate has been issued (not pending)</li></ul>';
        echo '<p>For assistance, contact <a href="mailto:' . esc_attr(ofst_cert_get_setting('support_email')) . '">support</a>.</p>';
        echo '</div>';
    }
}

/**
 * =====================================================
 * MY CERTIFICATES PAGE SHORTCODE
 * Shortcode: [cert_my_certificates]
 * =====================================================
 */
function ofst_cert_my_certificates_page()
{
    if (!is_user_logged_in()) {
        return '<div class="ofst-cert-notice error">Please <a href="' . wp_login_url(get_permalink()) . '">log in</a> to view your certificates.</div>';
    }

    $user_id = get_current_user_id();

    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $certificates = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE user_id = %d ORDER BY requested_date DESC",
        $user_id
    ));

    ob_start();
?>
    <div class="ofst-cert-form-container">
        <div class="ofst-cert-card">
            <h2 class="ofst-cert-title">My Certificates</h2>
            <p class="ofst-cert-subtitle">View and download your course completion certificates</p>

            <?php if (empty($certificates)): ?>
                <div class="ofst-cert-notice info">
                    <p>You don't have any certificates yet.</p>
                    <p><a href="#">Request a certificate</a> for courses you've completed.</p>
                </div>
            <?php else: ?>
                <table class="ofst-cert-table">
                    <thead>
                        <tr>
                            <th>Certificate ID</th>
                            <th>Course</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($certificates as $cert): ?>
                            <tr>
                                <td><?php echo esc_html($cert->certificate_id); ?></td>
                                <td><?php echo esc_html($cert->product_name); ?></td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    if ($cert->status == 'issued') {
                                        $status_class = 'status-issued';
                                    } elseif ($cert->status == 'pending') {
                                        $status_class = 'status-pending';
                                    } elseif ($cert->status == 'rejected') {
                                        $status_class = 'status-rejected';
                                    }
                                    ?>
                                    <span class="cert-status <?php echo $status_class; ?>"><?php echo esc_html(ucfirst($cert->status)); ?></span>
                                </td>
                                <td><?php echo esc_html(date('M d, Y', strtotime($cert->requested_date))); ?></td>
                                <td>
                                    <?php if ($cert->status == 'issued' && !empty($cert->certificate_file)): ?>
                                        <a href="<?php echo esc_url($cert->certificate_file); ?>" class="ofst-btn-small" download>Download</a>
                                    <?php elseif ($cert->status == 'pending'): ?>
                                        <span class="text-muted">Pending</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
<?php
    return ob_get_clean();
}
add_shortcode('cert_my_certificates', 'ofst_cert_my_certificates_page');
