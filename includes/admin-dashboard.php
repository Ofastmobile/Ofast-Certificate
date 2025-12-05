<?php

/**
 * Admin Dashboard for Certificate Management
 * Handles all admin-facing functionality
 */

if (!defined('ABSPATH')) exit;

// Load certificate generator
require_once OFST_CERT_PLUGIN_DIR . 'includes/certificate-generator.php';

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
        'Failed Certificates',
        'Failed Certificates',
        'manage_options',
        'ofst-certificates-failed',
        'ofst_cert_failed_page'
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
    if (isset($_POST['ofst_approve_cert']) && isset($_POST['cert_id'])) {
        check_admin_referer('ofst_cert_approve_' . $_POST['cert_id']);

        $cert_id = absint($_POST['cert_id']);
        $completion_date = isset($_POST['completion_date']) ? sanitize_text_field($_POST['completion_date']) : date('Y-m-d');

        $result = ofst_cert_approve_request($cert_id, $completion_date);

        if ($result['success']) {
            echo '<div class="notice notice-success"><p>✅ Certificate generated and issued! Email sent to student.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['error']) . '</p></div>';
        }
    }

    if (isset($_GET['action']) && isset($_GET['cert_id']) && $_GET['action'] !== 'view') {
        check_admin_referer('ofst_cert_action_' . $_GET['cert_id']);

        $cert_id = absint($_GET['cert_id']);
        $action = sanitize_text_field($_GET['action']);

        if ($action === 'approve-quick') {
            // Quick approve with today's date
            $result = ofst_cert_approve_request($cert_id, date('Y-m-d'));
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>✅ Certificate generated and issued! Email sent to student.</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['error']) . '</p></div>';
            }
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

                        <h3>🎨 Generate & Issue Certificate</h3>
                        <form id="approve-cert-form" method="post">
                            <?php wp_nonce_field('ofst_cert_approve_' . $cert->id); ?>
                            <input type="hidden" name="cert_id" value="<?php echo $cert->id; ?>">

                            <table class="form-table">
                                <tr>
                                    <th>Completion Date:</th>
                                    <td>
                                        <input type="date" name="completion_date" id="completion_date"
                                            value="<?php echo $cert->completion_date ? esc_attr($cert->completion_date) : date('Y-m-d'); ?>"
                                            required>
                                        <p class="description">Date to show on the certificate (when the student completed the course).</p>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Instructor:</th>
                                    <td>
                                        <?php
                                        $instructor_name = ofst_cert_get_instructor_name($cert->product_id, $cert->vendor_id);
                                        echo '<strong>' . esc_html($instructor_name) . '</strong>';
                                        ?>
                                        <p class="description">Auto-detected from course/product author.</p>
                                    </td>
                                </tr>
                            </table>

                            <p>
                                <button type="submit" name="ofst_approve_cert" class="button button-primary button-large"
                                    onclick="return confirm('Generate certificate and send to student?')">
                                    ✅ Generate & Issue Certificate
                                </button>
                                <a href="#" onclick="rejectCertificate(<?php echo $cert->id; ?>); return false;" class="button button-large">Reject Request</a>
                                <a href="?page=ofst-certificates" class="button button-large">Close</a>
                            </p>
                        </form>

                        <div style="margin-top: 20px; padding: 15px; background: #f0f0f1; border-left: 4px solid #2271b1;">
                            <strong>ℹ️ Auto-Generation:</strong> The certificate will be automatically generated using the template with the student's details overlaid.
                        </div>
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
            margin: 2% auto;
            padding: 30px;
            border: 1px solid #888;
            width: 80%;
            max-width: 700px;
            max-height: 90vh;
            overflow-y: auto;
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
 * Approve certificate request - AUTO-GENERATES certificate
 * 
 * @param int $request_id The request ID to approve
 * @param string $completion_date The completion date (Y-m-d format)
 * @return array ['success' => bool, 'error' => string]
 */
function ofst_cert_approve_request($request_id, $completion_date = null)
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $request_id));

    if (!$request) {
        return ['success' => false, 'error' => 'Request not found'];
    }

    // Use today's date if not provided
    if (!$completion_date) {
        $completion_date = date('Y-m-d');
    }

    // Update completion date in the request
    $wpdb->update(
        $table,
        ['completion_date' => $completion_date],
        ['id' => $request_id]
    );

    // Refresh request data
    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $request_id));

    // Generate certificate automatically
    $gen_result = ofst_cert_generate_certificate($request, $completion_date);

    if (!$gen_result['success']) {
        // Log generation failure
        ofst_cert_log_generation_failure($request_id, $gen_result['error']);
        return ['success' => false, 'error' => 'Certificate generation failed: ' . $gen_result['error']];
    }

    // Update status to issued with certificate file
    $wpdb->update(
        $table,
        [
            'status' => 'issued',
            'certificate_file' => $gen_result['file_url'],
            'processed_date' => current_time('mysql'),
            'processed_by' => get_current_user_id(),
            'rejection_reason' => null
        ],
        ['id' => $request_id]
    );

    // Get updated request data for email
    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $request_id));

    // Send email to student with certificate
    $email_sent = ofst_cert_send_certificate_email($request);

    if (!$email_sent) {
        // Log email failure but don't fail the whole process
        ofst_cert_log_email_failure($request_id, 'Email sending failed');
        return ['success' => true, 'error' => 'Certificate generated but email failed to send. You can resend from Failed Certificates page.'];
    }

    return ['success' => true, 'error' => ''];
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

    // Handle resend email action
    if (isset($_GET['action']) && $_GET['action'] === 'resend' && isset($_GET['cert_id'])) {
        check_admin_referer('ofst_resend_email_' . $_GET['cert_id']);
        $cert_id = absint($_GET['cert_id']);
        $result = ofst_cert_retry_email($cert_id);
        if ($result['success']) {
            echo '<div class="notice notice-success"><p>✅ Email resent successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['error']) . '</p></div>';
        }
    }

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
                                <?php if ($cert->certificate_file): ?>
                                    <a href="<?php echo esc_url($cert->certificate_file); ?>" class="button button-small" target="_blank">View</a>
                                <?php endif; ?>
                                <a href="?page=ofst-certificates-issued&action=resend&cert_id=<?php echo $cert->id; ?>&_wpnonce=<?php echo wp_create_nonce('ofst_resend_email_' . $cert->id); ?>"
                                    class="button button-small"
                                    onclick="return confirm('Resend certificate email to <?php echo esc_js($cert->email); ?>?')">
                                    📧 Resend Email
                                </a>
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
 * Failed Certificates Page
 * Shows certificates that failed generation or email sending
 */
function ofst_cert_failed_page()
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    // Handle retry actions
    if (isset($_POST['retry_action']) && isset($_POST['cert_id'])) {
        check_admin_referer('ofst_retry_action_' . $_POST['cert_id']);
        $cert_id = absint($_POST['cert_id']);
        $action = sanitize_text_field($_POST['retry_action']);
        $completion_date = isset($_POST['completion_date']) ? sanitize_text_field($_POST['completion_date']) : date('Y-m-d');

        if ($action === 'regenerate') {
            $result = ofst_cert_retry_generation($cert_id, $completion_date);
            if ($result['success']) {
                // Try sending email after regeneration
                $email_result = ofst_cert_retry_email($cert_id);
                if ($email_result['success']) {
                    echo '<div class="notice notice-success"><p>✅ Certificate regenerated and email sent successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-warning"><p>⚠️ Certificate regenerated but email failed. You can try resending.</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['error']) . '</p></div>';
            }
        } elseif ($action === 'resend') {
            $result = ofst_cert_retry_email($cert_id);
            if ($result['success']) {
                echo '<div class="notice notice-success"><p>✅ Email resent successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['error']) . '</p></div>';
            }
        }
    }

    // Get failed certificates (generation_failed or email_failed status)
    $failed = $wpdb->get_results(
        "SELECT * FROM $table WHERE status IN ('generation_failed', 'email_failed') ORDER BY processed_date DESC"
    );

?>
    <div class="wrap">
        <h1>⚠️ Failed Certificates</h1>
        <p>Certificates that failed during generation or email sending. You can retry these actions below.</p>

        <?php if (empty($failed)): ?>
            <div class="notice notice-success">
                <p>🎉 No failed certificates! All certificates are working properly.</p>
            </div>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th width="130">Certificate ID</th>
                        <th>Student</th>
                        <th>Email</th>
                        <th>Course</th>
                        <th width="120">Status</th>
                        <th width="200">Error</th>
                        <th width="280">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($failed as $cert): ?>
                        <tr>
                            <td><strong><?php echo esc_html($cert->certificate_id); ?></strong></td>
                            <td><?php echo esc_html($cert->first_name . ' ' . $cert->last_name); ?></td>
                            <td><?php echo esc_html($cert->email); ?></td>
                            <td><?php echo esc_html($cert->product_name); ?></td>
                            <td>
                                <?php if ($cert->status === 'generation_failed'): ?>
                                    <span style="background: #f8d7da; color: #721c24; padding: 3px 8px; border-radius: 3px; font-size: 11px;">
                                        Generation Failed
                                    </span>
                                <?php else: ?>
                                    <span style="background: #fff3cd; color: #856404; padding: 3px 8px; border-radius: 3px; font-size: 11px;">
                                        Email Failed
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?php echo esc_html(substr($cert->rejection_reason, 0, 100)); ?></small>
                            </td>
                            <td>
                                <form method="post" style="display: inline-flex; gap: 5px; align-items: center;">
                                    <?php wp_nonce_field('ofst_retry_action_' . $cert->id); ?>
                                    <input type="hidden" name="cert_id" value="<?php echo $cert->id; ?>">

                                    <?php if ($cert->status === 'generation_failed'): ?>
                                        <input type="date" name="completion_date" value="<?php echo date('Y-m-d'); ?>" style="width: 130px;">
                                        <button type="submit" name="retry_action" value="regenerate" class="button button-primary button-small">
                                            🔄 Regenerate
                                        </button>
                                    <?php else: ?>
                                        <button type="submit" name="retry_action" value="resend" class="button button-primary button-small">
                                            📧 Resend Email
                                        </button>
                                        <button type="submit" name="retry_action" value="regenerate" class="button button-small"
                                            onclick="return confirm('This will regenerate the certificate. Continue?')">
                                            🔄 Regenerate
                                        </button>
                                        <input type="hidden" name="completion_date" value="<?php echo $cert->completion_date ?: date('Y-m-d'); ?>">
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <style>
        .ofst-failed-table td {
            vertical-align: middle;
        }
    </style>
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
