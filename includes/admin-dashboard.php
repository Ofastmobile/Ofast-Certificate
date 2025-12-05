<?php

/**
 * Admin Dashboard for Certificate Management
 * Handles all admin-facing functionality
 */

if (!defined('ABSPATH')) exit;

/**
 * Add admin menu
 */
add_action('admin_menu', 'ofst_cert_add_admin_menu');
function ofst_cert_add_admin_menu()
{
    add_menu_page(
        'Certificate Management',
        'Certificates',
        'manage_options',
        'ofst-certificates',
        'ofst_cert_admin_dashboard',
        'dashicons-awards',
        30
    );

    add_submenu_page(
        'ofst-certificates',
        'Pending Requests',
        'Pending Requests',
        'manage_options',
        'ofst-certificates',
        'ofst_cert_admin_dashboard'
    );

    add_submenu_page(
        'ofst-certificates',
        'Issued Certificates',
        'Issued Certificates',
        'manage_options',
        'ofst-certificates-issued',
        'ofst_cert_issued_page'
    );

    add_submenu_page(
        'ofst-certificates',
        'Verification Log',
        'Verification Log',
        'manage_options',
        'ofst-certificates-log',
        'ofst_cert_verification_log'
    );

    add_submenu_page(
        'ofst-certificates',
        'Settings',
        'Settings',
        'manage_options',
        'ofst-certificates-settings',
        'ofst_cert_settings_page'
    );
}

/**
 * Main Admin Dashboard - Pending Requests
 */
function ofst_cert_admin_dashboard()
{
    // Handle bulk actions
    if (isset($_POST['ofst_bulk_action']) && isset($_POST['cert_ids'])) {
        check_admin_referer('ofst_bulk_action_nonce');

        $action = sanitize_text_field($_POST['ofst_bulk_action']);
        $cert_ids = array_map('absint', $_POST['cert_ids']);

        foreach ($cert_ids as $cert_id) {
            if ($action === 'approve') {
                ofst_cert_approve_request($cert_id);
            } elseif ($action === 'reject') {
                ofst_cert_reject_request($cert_id, 'Bulk rejection');
            }
        }

        echo '<div class="notice notice-success"><p>Bulk action completed successfully.</p></div>';
    }

    // Handle single approval/rejection (not view)
    if (isset($_GET['action']) && isset($_GET['cert_id']) && $_GET['action'] !== 'view') {
        check_admin_referer('ofst_cert_action_' . $_GET['cert_id']);

        $cert_id = absint($_GET['cert_id']);
        $action = sanitize_text_field($_GET['action']);

        if ($action === 'approve') {
            // Handle file upload if present
            $uploaded_file = isset($_FILES['certificate_pdf']) ? $_FILES['certificate_pdf'] : null;
            ofst_cert_approve_request($cert_id, $uploaded_file);
            echo '<div class="notice notice-success"><p>Certificate approved and issued! Email sent to student.</p></div>';
        } elseif ($action === 'reject') {
            $reason = isset($_GET['reason']) ? sanitize_text_field($_GET['reason']) : 'Not specified';
            ofst_cert_reject_request($cert_id, $reason);

            // Send rejection email
            global $wpdb;
            $table = $wpdb->prefix . 'ofst_cert_requests';
            $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $cert_id));
            if ($request) {
                ofst_cert_send_rejection_email($request, $reason);
            }

            echo '<div class="notice notice-warning"><p>Certificate request rejected. Email sent to student.</p></div>';
        }
    }

    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    // Get filter
    $filter = isset($_GET['filter']) ? sanitize_text_field($_GET['filter']) : 'all';
    $where = "status = 'pending'";

    if ($filter === 'student') {
        $where .= " AND request_type = 'student'";
    } elseif ($filter === 'vendor') {
        $where .= " AND request_type = 'vendor'";
    }

    $requests = $wpdb->get_results("SELECT * FROM $table WHERE $where ORDER BY requested_date DESC");

?>
    <div class="wrap">
        <h1>Certificate Management - Pending Requests</h1>

        <div class="tablenav top">
            <div class="alignleft actions">
                <select name="filter_type" id="filter_type" onchange="window.location.href='?page=ofst-certificates&filter='+this.value">
                    <option value="all" <?php selected($filter, 'all'); ?>>All Requests</option>
                    <option value="student" <?php selected($filter, 'student'); ?>>Student Requests</option>
                    <option value="vendor" <?php selected($filter, 'vendor'); ?>>Vendor Requests</option>
                </select>
            </div>
        </div>

        <?php if (empty($requests)): ?>
            <div class="notice notice-info">
                <p>No pending certificate requests.</p>
            </div>
        <?php else: ?>
            <form method="post">
                <?php wp_nonce_field('ofst_bulk_action_nonce'); ?>

                <div class="tablenav top">
                    <div class="alignleft actions bulkactions">
                        <select name="ofst_bulk_action">
                            <option value="">Bulk Actions</option>
                            <option value="approve">Approve</option>
                            <option value="reject">Reject</option>
                        </select>
                        <input type="submit" class="button action" value="Apply">
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="40"><input type="checkbox" id="select-all"></th>
                            <th>Certificate ID</th>
                            <th>Student Name</th>
                            <th>Email</th>
                            <th>Course</th>
                            <th>Type</th>
                            <th>Requested</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($requests as $req): ?>
                            <tr>
                                <td><input type="checkbox" name="cert_ids[]" value="<?php echo $req->id; ?>" class="cert-checkbox"></td>
                                <td><strong><?php echo esc_html($req->certificate_id); ?></strong></td>
                                <td><?php echo esc_html($req->first_name . ' ' . $req->last_name); ?></td>
                                <td><?php echo esc_html($req->email); ?></td>
                                <td><?php echo esc_html($req->product_name); ?></td>
                                <td><span class="cert-type-badge <?php echo $req->request_type; ?>"><?php echo ucfirst($req->request_type); ?></span></td>
                                <td><?php echo date('M d, Y', strtotime($req->requested_date)); ?></td>
                                <td>
                                    <a href="?page=ofst-certificates&action=view&cert_id=<?php echo $req->id; ?>" class="button button-small">View</a>
                                    <a href="?page=ofst-certificates&action=approve&cert_id=<?php echo $req->id; ?>&_wpnonce=<?php echo wp_create_nonce('ofst_cert_action_' . $req->id); ?>"
                                        class="button button-primary button-small"
                                        onclick="return confirm('Approve this certificate request?')">Approve</a>
                                    <a href="#" onclick="rejectCertificate(<?php echo $req->id; ?>); return false;" class="button button-small">Reject</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        <?php endif; ?>

        <!-- View Details Modal -->
        <?php if (isset($_GET['action']) && $_GET['action'] === 'view' && isset($_GET['cert_id'])):
            $cert_id = absint($_GET['cert_id']);
            $cert = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $cert_id));
            if ($cert):
        ?>
                <div class="ofst-cert-modal" style="display:block;">
                    <div class="ofst-cert-modal-content">
                        <span class="ofst-cert-modal-close" onclick="window.location.href='?page=ofst-certificates'">&times;</span>
                        <h2>Certificate Request Details</h2>

                        <table class="form-table">
                            <tr>
                                <th>Certificate ID:</th>
                                <td><?php echo esc_html($cert->certificate_id); ?></td>
                            </tr>
                            <tr>
                                <th>Request Type:</th>
                                <td><?php echo ucfirst($cert->request_type); ?></td>
                            </tr>
                            <tr>
                                <th>Student Name:</th>
                                <td><?php echo esc_html($cert->first_name . ' ' . $cert->last_name); ?></td>
                            </tr>
                            <tr>
                                <th>Email:</th>
                                <td><?php echo esc_html($cert->email); ?></td>
                            </tr>
                            <tr>
                                <th>Phone:</th>
                                <td><?php echo esc_html($cert->phone); ?></td>
                            </tr>
                            <tr>
                                <th>Course:</th>
                                <td><?php echo esc_html($cert->product_name); ?></td>
                            </tr>
                            <?php if ($cert->instructor_name): ?>
                                <tr>
                                    <th>Instructor:</th>
                                    <td><?php echo esc_html($cert->instructor_name); ?></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($cert->project_link): ?>
                                <tr>
                                    <th>Project Link:</th>
                                    <td><a href="<?php echo esc_url($cert->project_link); ?>" target="_blank"><?php echo esc_html($cert->project_link); ?></a></td>
                                </tr>
                            <?php endif; ?>
                            <?php if ($cert->vendor_notes): ?>
                                <tr>
                                    <th>Vendor Notes:</th>
                                    <td><?php echo esc_html($cert->vendor_notes); ?></td>
                                </tr>
                            <?php endif; ?>
                            <tr>
                                <th>Requested Date:</th>
                                <td><?php echo date('F d, Y g:i A', strtotime($cert->requested_date)); ?></td>
                            </tr>
                        </table>

                        <h3>Upload Certificate PDF</h3>
                        <form id="upload-cert-form" method="post" enctype="multipart/form-data"
                            action="?page=ofst-certificates&action=approve&cert_id=<?php echo $cert->id; ?>&_wpnonce=<?php echo wp_create_nonce('ofst_cert_action_' . $cert->id); ?>">

                            <table class="form-table">
                                <tr>
                                    <th>Certificate PDF:</th>
                                    <td>
                                        <input type="file" name="certificate_pdf" id="certificate_pdf" accept=".pdf" required>
                                        <p class="description">Upload the designed certificate PDF (Max 10MB). Required to approve and issue certificate.</p>
                                    </td>
                                </tr>
                            </table>

                            <p>
                                <button type="submit" class="button button-primary button-large"
                                    onclick="return confirm('Upload this certificate and issue to student?')">
                                    📤 Upload & Approve Certificate
                                </button>
                                <a href="#" onclick="rejectCertificate(<?php echo $cert->id; ?>); return false;" class="button button-large">Reject Request</a>
                                <a href="?page=ofst-certificates" class="button button-large">Close</a>
                            </p>
                        </form>

                        <hr style="margin: 20px 0;">

                        <p style="color: #666; font-size: 13px;">
                            <strong>Alternative:</strong> Approve without uploading now - you can upload later from the Issued Certificates page.
                        </p>

                        <p>
                            <a href="?page=ofst-certificates&action=approve&cert_id=<?php echo $cert->id; ?>&_wpnonce=<?php echo wp_create_nonce('ofst_cert_action_' . $cert->id); ?>"
                                class="button"
                                onclick="return confirm('Approve without uploading certificate PDF? You can upload it later.')">
                                Approve Without PDF
                            </a>
                        </p>
                    </div>
                </div>
        <?php endif;
        endif; ?>
    </div>

    <script>
        document.getElementById('select-all')?.addEventListener('change', function() {
            document.querySelectorAll('.cert-checkbox').forEach(cb => cb.checked = this.checked);
        });

        function rejectCertificate(certId) {
            var reason = prompt('Rejection reason (optional):');
            if (reason !== null) {
                window.location.href = '?page=ofst-certificates&action=reject&cert_id=' + certId +
                    '&reason=' + encodeURIComponent(reason) +
                    '&_wpnonce=<?php echo wp_create_nonce('ofst_cert_action_'); ?>' + certId;
            }
        }
    </script>

    <style>
        .cert-type-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .cert-type-badge.student {
            background: #e3f2fd;
            color: #1976d2;
        }

        .cert-type-badge.vendor {
            background: #fff3e0;
            color: #f57c00;
        }

        .ofst-cert-modal {
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
        }

        .ofst-cert-modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 30px;
            border: 1px solid #888;
            width: 80%;
            max-width: 700px;
            border-radius: 8px;
        }

        .ofst-cert-modal-close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .ofst-cert-modal-close:hover {
            color: #000;
        }
    </style>
<?php
}

/**
 * Approve certificate request
 */
function ofst_cert_approve_request($request_id, $uploaded_file = null)
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $request_id));

    if (!$request) {
        return false;
    }

    $certificate_file_path = null;

    // Handle file upload if provided
    if ($uploaded_file && isset($uploaded_file['tmp_name']) && !empty($uploaded_file['tmp_name'])) {
        // Validate file type
        $allowed_types = array('application/pdf');
        $file_type = $uploaded_file['type'];

        if (!in_array($file_type, $allowed_types)) {
            wp_die('Invalid file type. Only PDF files are allowed.');
        }

        // Validate file size (max 10MB)
        $max_size = 10 * 1024 * 1024; // 10MB
        if ($uploaded_file['size'] > $max_size) {
            wp_die('File too large. Maximum size is 10MB.');
        }

        // Create certificates directory if it doesn't exist
        $upload_dir = wp_upload_dir();
        $cert_dir = $upload_dir['basedir'] . '/certificates/';

        if (!file_exists($cert_dir)) {
            wp_mkdir_p($cert_dir);
            // Add index.php to prevent directory listing
            file_put_contents($cert_dir . '/index.php', '<?php // Silence is golden');
        }

        // Generate secure filename
        $file_extension = pathinfo($uploaded_file['name'], PATHINFO_EXTENSION);
        $safe_filename = sanitize_file_name($request->certificate_id . '-' . time() . '.' . $file_extension);
        $file_path = $cert_dir . $safe_filename;

        // Move uploaded file
        if (move_uploaded_file($uploaded_file['tmp_name'], $file_path)) {
            $certificate_file_path = $upload_dir['baseurl'] . '/certificates/' . $safe_filename;
        } else {
            wp_die('Failed to upload certificate file. Please try again.');
        }
    }

    // Update status to issued
    $update_data = array(
        'status' => 'issued',
        'processed_date' => current_time('mysql'),
        'processed_by' => get_current_user_id()
    );

    if ($certificate_file_path) {
        $update_data['certificate_file'] = $certificate_file_path;
    }

    $wpdb->update(
        $table,
        $update_data,
        array('id' => $request_id)
    );

    // Get updated request data
    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $request_id));

    // Send email to student with certificate
    ofst_cert_send_certificate_email($request);

    return true;
}

/**
 * Reject certificate request
 */
function ofst_cert_reject_request($request_id, $reason = '')
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $wpdb->update(
        $table,
        array(
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'processed_date' => current_time('mysql'),
            'processed_by' => get_current_user_id()
        ),
        array('id' => $request_id)
    );

    return true;
}

/**
 * Issued Certificates Page
 */
function ofst_cert_issued_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $certificates = $wpdb->get_results("SELECT * FROM $table WHERE status = 'issued' ORDER BY processed_date DESC LIMIT 100");

?>
    <div class="wrap">
        <h1>Issued Certificates</h1>

        <?php if (empty($certificates)): ?>
            <div class="notice notice-info">
                <p>No certificates have been issued yet.</p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Certificate ID</th>
                        <th>Student Name</th>
                        <th>Course</th>
                        <th>Issued Date</th>
                        <th>Issued By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($certificates as $cert):
                        $issued_by = get_userdata($cert->processed_by);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($cert->certificate_id); ?></strong></td>
                            <td><?php echo esc_html($cert->first_name . ' ' . $cert->last_name); ?></td>
                            <td><?php echo esc_html($cert->product_name); ?></td>
                            <td><?php echo date('M d, Y', strtotime($cert->processed_date)); ?></td>
                            <td><?php echo $issued_by ? esc_html($issued_by->display_name) : 'System'; ?></td>
                            <td>
                                <a href="?page=ofst-certificates&action=view&cert_id=<?php echo $cert->id; ?>" class="button button-small">View</a>
                                <?php if ($cert->certificate_file): ?>
                                    <a href="<?php echo esc_url($cert->certificate_file); ?>" class="button button-small" target="_blank">Download PDF</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php
}

/**
 * Verification Log Page
 */
function ofst_cert_verification_log()
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_verifications';

    $logs = $wpdb->get_results("SELECT * FROM $table ORDER BY verified_date DESC LIMIT 100");

?>
    <div class="wrap">
        <h1>Certificate Verification Log</h1>

        <?php if (empty($logs)): ?>
            <div class="notice notice-info">
                <p>No verification attempts logged yet.</p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Certificate ID</th>
                        <th>Search Query</th>
                        <th>Result</th>
                        <th>IP Address</th>
                        <th>User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log):
                        $user = $log->verified_by_user ? get_userdata($log->verified_by_user) : null;
                    ?>
                        <tr>
                            <td><?php echo date('M d, Y g:i A', strtotime($log->verified_date)); ?></td>
                            <td><?php echo esc_html($log->certificate_id); ?></td>
                            <td><?php echo esc_html($log->search_query); ?></td>
                            <td>
                                <span class="result-badge <?php echo $log->result; ?>">
                                    <?php echo ucfirst($log->result); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($log->verified_by_ip); ?></td>
                            <td><?php echo $user ? esc_html($user->display_name) : 'Guest'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <style>
        .result-badge {
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }

        .result-badge.found {
            background: #d4edda;
            color: #155724;
        }

        .result-badge.not_found {
            background: #f8d7da;
            color: #721c24;
        }
    </style>
<?php
}

/**
 * Settings Page
 */
function ofst_cert_settings_page()
{
    if (isset($_POST['ofst_save_settings'])) {
        check_admin_referer('ofst_settings_nonce');

        $settings = array(
            'cert_prefix',
            'min_days_after_purchase',
            'company_name',
            'support_email',
            'from_email',
            'from_name',
            'logo_url',
            'seal_url',
            'signature_url',
            'turnstile_site_key',
            'turnstile_secret_key'
        );

        foreach ($settings as $key) {
            if (isset($_POST[$key])) {
                ofst_cert_update_setting($key, sanitize_text_field($_POST[$key]));
            }
        }

        // Handle delete on uninstall checkbox
        $delete_on_uninstall = isset($_POST['delete_on_uninstall']) ? 'yes' : 'no';
        update_option('ofst_cert_delete_on_uninstall', $delete_on_uninstall);

        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }

?>
    <div class="wrap">
        <h1>Certificate System Settings</h1>

        <form method="post">
            <?php wp_nonce_field('ofst_settings_nonce'); ?>

            <h2>General Settings</h2>
            <table class="form-table">
                <tr>
                    <th>Certificate ID Prefix</th>
                    <td>
                        <input type="text" name="cert_prefix" value="<?php echo esc_attr(ofst_cert_get_setting('cert_prefix', 'OFSHDG')); ?>" class="regular-text">
                        <p class="description">Prefix for certificate IDs (e.g., OFSHDG2024001)</p>
                    </td>
                </tr>
                <tr>
                    <th>Minimum Days After Purchase</th>
                    <td>
                        <input type="number" name="min_days_after_purchase" value="<?php echo esc_attr(ofst_cert_get_setting('min_days_after_purchase', '3')); ?>" min="0" max="365">
                        <p class="description">Students can request certificates this many days after purchase</p>
                    </td>
                </tr>
                <tr>
                    <th>Company Name</th>
                    <td>
                        <input type="text" name="company_name" value="<?php echo esc_attr(ofst_cert_get_setting('company_name')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>Support Email</th>
                    <td>
                        <input type="email" name="support_email" value="<?php echo esc_attr(ofst_cert_get_setting('support_email')); ?>" class="regular-text">
                    </td>
                </tr>
            </table>

            <h2>Email Settings</h2>
            <table class="form-table">
                <tr>
                    <th>From Email</th>
                    <td>
                        <input type="email" name="from_email" value="<?php echo esc_attr(ofst_cert_get_setting('from_email')); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th>From Name</th>
                    <td>
                        <input type="text" name="from_name" value="<?php echo esc_attr(ofst_cert_get_setting('from_name')); ?>" class="regular-text">
                    </td>
                </tr>
            </table>

            <h2>Certificate Design</h2>
            <table class="form-table">
                <tr>
                    <th>Logo URL</th>
                    <td>
                        <input type="url" name="logo_url" value="<?php echo esc_attr(ofst_cert_get_setting('logo_url')); ?>" class="large-text">
                        <p class="description">URL to your company logo (recommended: R2 bucket)</p>
                    </td>
                </tr>
                <tr>
                    <th>Seal/Badge URL</th>
                    <td>
                        <input type="url" name="seal_url" value="<?php echo esc_attr(ofst_cert_get_setting('seal_url')); ?>" class="large-text">
                        <p class="description">URL to certificate seal/badge image</p>
                    </td>
                </tr>
                <tr>
                    <th>Signature URL</th>
                    <td>
                        <input type="url" name="signature_url" value="<?php echo esc_attr(ofst_cert_get_setting('signature_url')); ?>" class="large-text">
                        <p class="description">URL to signature image</p>
                    </td>
                </tr>
            </table>

            <h2>Security (Cloudflare Turnstile)</h2>
            <table class="form-table">
                <tr>
                    <th>Turnstile Site Key</th>
                    <td>
                        <input type="text" name="turnstile_site_key" value="<?php echo esc_attr(ofst_cert_get_setting('turnstile_site_key')); ?>" class="large-text">
                    </td>
                </tr>
                <tr>
                    <th>Turnstile Secret Key</th>
                    <td>
                        <input type="text" name="turnstile_secret_key" value="<?php echo esc_attr(ofst_cert_get_setting('turnstile_secret_key')); ?>" class="large-text">
                    </td>
                </tr>
            </table>

            <h2>Data Management</h2>
            <table class="form-table">
                <tr>
                    <th>Delete Data on Uninstall</th>
                    <td>
                        <label>
                            <input type="checkbox" name="delete_on_uninstall" value="yes" <?php checked(get_option('ofst_cert_delete_on_uninstall', 'no'), 'yes'); ?>>
                            Delete all certificate data when plugin is deleted (not just deactivated)
                        </label>
                        <p class="description" style="color: #d63638;"><strong>Warning:</strong> This will permanently delete all certificates, requests, verification logs, and settings. This action cannot be undone!</p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <input type="submit" name="ofst_save_settings" class="button button-primary" value="Save Settings">
            </p>
        </form>
    </div>
<?php
}
