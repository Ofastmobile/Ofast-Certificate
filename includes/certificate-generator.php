<?php

/**
 * Certificate Generator - Using User's HTML/CSS Template
 * Generates professional certificates with dynamic QR codes
 */

if (!defined('ABSPATH')) exit;

// Include QR code library
require_once OFST_CERT_PLUGIN_DIR . 'includes/phpqrcode/qrlib.php';

/**
 * Generate a certificate image using HTML/CSS
 * 
 * @param object $request The certificate request object
 * @param string $completion_date The completion date (format: Y-m-d)
 * @return array ['success' => bool, 'file_url' => string, 'file_path' => string, 'error' => string]
 */
function ofst_cert_generate_certificate($request, $completion_date)
{
    try {
        // Create certificates directory
        $upload_dir = wp_upload_dir();
        $cert_dir = $upload_dir['basedir'] . '/certificates/';

        if (!file_exists($cert_dir)) {
            wp_mkdir_p($cert_dir);
            file_put_contents($cert_dir . 'index.php', '<?php // Silence is golden');
        }

        // Prepare data
        $student_name = $request->first_name . ' ' . $request->last_name;
        $course_name = $request->product_name;
        $cert_number = $request->certificate_id;
        $formatted_date = date('F d, Y', strtotime($completion_date));
        $instructor_name = ofst_cert_get_instructor_name($request->product_id, $request->vendor_id);
        $company_name = ofst_cert_get_setting('company_name', 'Ofastshop Digitals');

        // Logo and background URLs
        $logo_url = 'https://pub-f02915809d3846b8ab0aaedeab54dbf7.r2.dev/ofastshop/web/2025/12/06071050/ofastlogo2.webp';

        // Static QR Code URL (as requested)
        $qr_url = 'https://pub-f02915809d3846b8ab0aaedeab54dbf7.r2.dev/ofastshop/web/2025/12/05140249/Ofastshop_Certificate-1024.webp';

        // Generate HTML certificate
        $html = ofst_cert_generate_html(
            $student_name,
            $course_name,
            $formatted_date,
            $cert_number,
            $instructor_name,
            $company_name,
            $logo_url,
            $qr_url
        );

        // Save HTML file (this will be the certificate - viewable in browser)
        $html_filename = 'cert-' . $request->certificate_id . '.html';
        $html_path = $cert_dir . $html_filename;
        $html_url = $upload_dir['baseurl'] . '/certificates/' . $html_filename;

        file_put_contents($html_path, $html);

        return [
            'success' => true,
            'file_url' => $html_url,
            'file_path' => $html_path,
            'error' => ''
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Exception: ' . $e->getMessage()
        ];
    }
}

/**
 * Generate the HTML certificate using user's template
 */
function ofst_cert_generate_html($student_name, $course_name, $date, $cert_number, $instructor, $company, $logo_url, $qr_url)
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Certificate - ' . esc_html($cert_number) . '</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&family=Great+Vibes&family=Montserrat:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    background-color: #1a1a2e;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
    font-family: "Montserrat", sans-serif;
}

.certificate-wrapper {
    width: 100%;
    max-width: 900px;
    position: relative;
}

.certificate {
    position: relative;
    background: linear-gradient(135deg, #1e1e3f 0%, #2d1b4e 50%, #1e1e3f 100%);
    border: 3px solid #c9a227;
    border-radius: 8px;
    padding: 50px 60px;
    min-height: 600px;
    overflow: hidden;
    box-shadow: 0 10px 30px rgba(0,0,0,0.3);
}

/* Print Button */
.print-btn-container {
    position: fixed;
    bottom: 20px;
    right: 20px;
    z-index: 1000;
}

.print-btn {
    background: #c9a227;
    color: #1a1a2e;
    border: none;
    padding: 12px 24px;
    border-radius: 50px;
    font-family: "Montserrat", sans-serif;
    font-weight: 600;
    font-size: 16px;
    cursor: pointer;
    box-shadow: 0 4px 15px rgba(201, 162, 39, 0.4);
    transition: transform 0.2s, box-shadow 0.2s;
    display: flex;
    align-items: center;
    gap: 8px;
}

.print-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(201, 162, 39, 0.6);
}

/* Corner Decorations */
.corner-decoration {
    position: absolute;
    width: 120px;
    height: 120px;
    overflow: hidden;
}

.corner-decoration.top-right {
    top: 0;
    right: 0;
}

.corner-decoration.bottom-right {
    bottom: 0;
    right: 0;
    transform: scaleY(-1);
}

.corner-stripe {
    position: absolute;
    width: 200px;
    height: 25px;
    transform-origin: top right;
}

.corner-stripe.purple-1 {
    background: #6b3fa0;
    top: 20px;
    right: -40px;
    transform: rotate(45deg);
}

.corner-stripe.gold-1 {
    background: linear-gradient(90deg, #c9a227, #f4d03f, #c9a227);
    top: 45px;
    right: -40px;
    transform: rotate(45deg);
}

.corner-stripe.purple-2 {
    background: #6b3fa0;
    top: 70px;
    right: -40px;
    transform: rotate(45deg);
}

/* Left Border Decoration */
.left-border {
    position: absolute;
    left: 20px;
    top: 50%;
    transform: translateY(-50%);
    height: 70%;
}

.diamond-pattern {
    display: flex;
    flex-direction: column;
    align-items: center;
    height: 100%;
    justify-content: space-between;
}

.diamond {
    width: 12px;
    height: 12px;
    background: linear-gradient(135deg, #c9a227, #f4d03f);
    transform: rotate(45deg);
}

.line {
    width: 2px;
    height: 40px;
    background: linear-gradient(180deg, #c9a227, #f4d03f);
}

/* Logo */
.logo-area {
    position: absolute;
    top: 30px;
    left: 50px;
    z-index: 10;
}

.logo-img {
    width: 80px;
    height: auto;
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.3));
}

/* Watermark */
.watermark {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    opacity: 0.06;
    pointer-events: none;
    z-index: 1;
}

.watermark-box {
    display: flex;
    flex-direction: column;
    align-items: center;
    font-family: "Playfair Display", serif;
    font-size: 72px;
    font-weight: 700;
    color: #ffffff;
    line-height: 1;
    letter-spacing: 8px;
}

/* Main Content */
.main-content {
    position: relative;
    z-index: 5;
    text-align: center;
    padding-top: 20px;
}

.title-area {
    margin-bottom: 25px;
}

.title {
    font-family: "Playfair Display", serif;
    font-size: 56px;
    font-weight: 700;
    color: #c9a227;
    letter-spacing: 4px;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.3);
    margin-bottom: 5px;
}

.subtitle {
    font-family: "Playfair Display", serif;
    font-size: 24px;
    font-weight: 400;
    color: #ffffff;
    letter-spacing: 6px;
    text-transform: uppercase;
}

.certifies-text {
    font-family: "Montserrat", sans-serif;
    font-size: 14px;
    color: #cccccc;
    letter-spacing: 2px;
    margin-bottom: 15px;
}

.recipient-name {
    font-family: "Great Vibes", cursive;
    font-size: 52px;
    color: #ffffff;
    margin-bottom: 15px;
    text-shadow: 1px 1px 2px rgba(0, 0, 0, 0.3);
}

/* Gold Divider */
.gold-divider {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin: 20px auto;
    max-width: 300px;
}

.divider-line {
    flex: 1;
    height: 2px;
    background: linear-gradient(90deg, transparent, #c9a227, transparent);
}

.divider-diamond {
    width: 8px;
    height: 8px;
    background: #c9a227;
    transform: rotate(45deg);
}

/* Course Details */
.course-details {
    margin-top: 20px;
}

.course-intro {
    font-size: 13px;
    color: #aaaaaa;
    margin-bottom: 10px;
}

.course-name {
    font-family: "Playfair Display", serif;
    font-size: 22px;
    color: #c9a227;
    font-weight: 600;
    margin-bottom: 8px;
}

.course-date {
    font-size: 14px;
    color: #cccccc;
}

/* Certificate Number */
.cert-number-area {
    position: absolute;
    bottom: 25px;
    left: 50px;
    text-align: left;
}

.cert-label {
    display: block;
    font-size: 10px;
    color: #888888;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.cert-number {
    font-size: 12px;
    color: #c9a227;
    font-weight: 600;
    letter-spacing: 1px;
}

/* Footer Area */
.footer-area {
    position: relative;
    z-index: 5;
    margin-top: 40px;
}

.signatures {
    display: flex;
    justify-content: center;
    align-items: flex-end;
    gap: 60px;
}

.signature-block {
    text-align: center;
    min-width: 150px;
}

.signature-name {
    font-size: 12px;
    color: #ffffff;
    font-weight: 600;
    letter-spacing: 1px;
    padding-bottom: 8px;
    border-bottom: 1px solid #c9a227;
    margin-bottom: 5px;
}

.signature-title {
    font-size: 10px;
    color: #888888;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Gold Seal */
.seal-wrapper {
    position: relative;
    width: 100px;
    height: 100px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.seal-img {
    width: 100px;
    height: auto;
    filter: drop-shadow(0 4px 8px rgba(0, 0, 0, 0.3));
}

/* QR Code */
.qr-area {
    position: absolute;
    bottom: 20px;
    right: 30px;
    display: flex;
    flex-direction: column;
    align-items: center;
}

.qr-box {
    width: 60px;
    height: 60px;
    background: #ffffff;
    border-radius: 6px;
    padding: 4px;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
}

.qr-img {
    width: 100%;
    height: 100%;
    object-fit: contain;
}

.scan-text {
    font-size: 10px;
    color: #888888;
    margin-top: 4px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

/* Print styles */
@media print {
    @page {
        size: landscape;
        margin: 0;
    }
    
    body {
        background: none;
        padding: 0;
        display: block;
    }
    
    .certificate-wrapper {
        width: 100%;
        max-width: none;
        height: 100vh;
        display: flex;
        justify-content: center;
        align-items: center;
    }
    
    .certificate {
        width: 100%;
        height: 100%;
        border: none;
        box-shadow: none;
        /* Force background printing */
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .print-btn-container {
        display: none;
    }
}

/* Mobile Responsive */
@media (max-width: 900px) {
    body {
        display: block;
        padding: 10px;
        overflow-x: hidden;
    }

    .certificate-wrapper {
        transform-origin: top left;
        /* Scale down to fit screen width */
        transform: scale(calc(100vw / 940)); 
        margin-bottom: calc(100vw * 0.7); /* Reserve space */
    }
    
    .print-btn-container {
        bottom: 10px;
        right: 10px;
    }
}
    </style>
    <script>
        // Auto-scale for mobile
        function scaleCertificate() {
            if (window.innerWidth < 940) {
                const wrapper = document.querySelector(".certificate-wrapper");
                const scale = (window.innerWidth - 20) / 900;
                wrapper.style.transform = "scale(" + scale + ")";
                wrapper.style.transformOrigin = "top center";
                // Adjust body height to fit scaled content
                document.body.style.height = (600 * scale + 50) + "px";
            }
        }
        window.addEventListener("resize", scaleCertificate);
        window.addEventListener("load", scaleCertificate);
    </script>
</head>
<body>
    <div class="print-btn-container">
        <button onclick="window.print()" class="print-btn">
            🖨️ Print / Save as PDF
        </button>
    </div>

    <div class="certificate-wrapper">
        <div class="certificate">
            <!-- Corner Decorations - Top Right -->
            <div class="corner-decoration top-right">
                <div class="corner-stripe purple-1"></div>
                <div class="corner-stripe gold-1"></div>
                <div class="corner-stripe purple-2"></div>
            </div>

            <!-- Corner Decorations - Bottom Right -->
            <div class="corner-decoration bottom-right">
                <div class="corner-stripe purple-1"></div>
                <div class="corner-stripe gold-1"></div>
                <div class="corner-stripe purple-2"></div>
            </div>

            <!-- Left Border Decoration -->
            <div class="left-border">
                <div class="diamond-pattern">
                    <span class="diamond"></span>
                    <span class="line"></span>
                    <span class="diamond"></span>
                    <span class="line"></span>
                    <span class="diamond"></span>
                    <span class="line"></span>
                    <span class="diamond"></span>
                </div>
            </div>

            <!-- Logo -->
            <div class="logo-area">
                <img src="' . esc_url($logo_url) . '" alt="Logo" class="logo-img">
            </div>

            <!-- Watermark -->
            <div class="watermark">
                <div class="watermark-box">
                    <span>OFAST</span>
                    <span>SHOP</span>
                    <span>DIGITALS</span>
                </div>
            </div>

            <!-- Main Content -->
            <div class="main-content">
                <!-- Title -->
                <div class="title-area">
                    <h1 class="title">Certificate</h1>
                    <h2 class="subtitle">Of Achievements</h2>
                </div>

                <!-- Certifies Text -->
                <p class="certifies-text">This Certifies That</p>

                <!-- Recipient Name -->
                <h3 class="recipient-name">' . esc_html($student_name) . '</h3>

                <!-- Gold Divider -->
                <div class="gold-divider">
                    <span class="divider-line"></span>
                    <span class="divider-diamond"></span>
                    <span class="divider-diamond"></span>
                    <span class="divider-diamond"></span>
                    <span class="divider-line"></span>
                </div>

                <!-- Course Details -->
                <div class="course-details">
                    <p class="course-intro">Has Successfully completed online course on</p>
                    <p class="course-name">' . esc_html($course_name) . '</p>
                    <p class="course-date">On ' . esc_html($date) . '</p>
                </div>
            </div>

            <!-- Certificate Number -->
            <div class="cert-number-area">
                <span class="cert-label">Cert no.</span>
                <span class="cert-number">' . esc_html($cert_number) . '</span>
            </div>

            <!-- Footer Section -->
            <div class="footer-area">
                <div class="signatures">
                    <!-- Instructor -->
                    <div class="signature-block">
                        <p class="signature-name">' . esc_html(strtoupper($instructor)) . '</p>
                        <p class="signature-title">Instructor</p>
                    </div>

                    <!-- Gold Seal -->
                    <div class="seal-wrapper">
                        <img src="https://pub-f02915809d3846b8ab0aaedeab54dbf7.r2.dev/ofastshop/web/2025/12/05195926/certificate-seal.webp" alt="Certificate Seal" class="seal-img">
                    </div>

                    <!-- Authorized By -->
                    <div class="signature-block">
                        <p class="signature-name">' . esc_html(strtoupper($company)) . '</p>
                        <p class="signature-title">Authorised By</p>
                    </div>
                </div>
            </div>

            <!-- QR Code -->
            <div class="qr-area">
                <div class="qr-box">
                    <img src="' . esc_url($qr_url) . '" alt="Scan QR Code" class="qr-img">
                </div>
                <span class="scan-text">Scan</span>
            </div>
        </div>
    </div>
</body>
</html>';
}

/**
 * Get instructor name from product author or vendor
 */
function ofst_cert_get_instructor_name($product_id, $vendor_id = null)
{
    // First try vendor_id if provided
    if ($vendor_id) {
        $vendor = get_user_by('ID', $vendor_id);
        if ($vendor) {
            $display_name = $vendor->display_name;
            if (!empty($display_name) && $display_name !== $vendor->user_login) {
                return $display_name;
            }
            $first = get_user_meta($vendor_id, 'first_name', true);
            $last = get_user_meta($vendor_id, 'last_name', true);
            if ($first || $last) {
                return trim($first . ' ' . $last);
            }
            return $vendor->display_name;
        }
    }

    // Fallback: get product author
    if (function_exists('wc_get_product')) {
        $product = wc_get_product($product_id);
        if ($product) {
            $author_id = get_post_field('post_author', $product_id);
            $author = get_user_by('ID', $author_id);
            if ($author) {
                $display_name = $author->display_name;
                if (!empty($display_name) && $display_name !== $author->user_login) {
                    return $display_name;
                }
                $first = get_user_meta($author_id, 'first_name', true);
                $last = get_user_meta($author_id, 'last_name', true);
                if ($first || $last) {
                    return trim($first . ' ' . $last);
                }
                return $author->display_name;
            }
        }
    }

    return ofst_cert_get_setting('company_name', 'Ofastshop Digitals');
}

/**
 * Log certificate generation failure
 */
function ofst_cert_log_generation_failure($request_id, $error_message)
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $wpdb->update(
        $table,
        [
            'status' => 'generation_failed',
            'rejection_reason' => 'Certificate Generation Failed: ' . $error_message
        ],
        ['id' => $request_id]
    );
}

/**
 * Log email sending failure
 */
function ofst_cert_log_email_failure($request_id, $error_message = 'Email failed to send')
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $wpdb->update(
        $table,
        [
            'status' => 'email_failed',
            'rejection_reason' => 'Email Failed: ' . $error_message
        ],
        ['id' => $request_id]
    );
}

/**
 * Retry certificate generation
 */
function ofst_cert_retry_generation($request_id, $completion_date)
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $request_id));

    if (!$request) {
        return ['success' => false, 'error' => 'Request not found'];
    }

    $result = ofst_cert_generate_certificate($request, $completion_date);

    if ($result['success']) {
        $wpdb->update(
            $table,
            [
                'status' => 'issued',
                'certificate_file' => $result['file_url'],
                'rejection_reason' => null,
                'processed_date' => current_time('mysql'),
                'processed_by' => get_current_user_id()
            ],
            ['id' => $request_id]
        );

        return $result;
    } else {
        ofst_cert_log_generation_failure($request_id, $result['error']);
        return $result;
    }
}

/**
 * Retry sending certificate email
 */
function ofst_cert_retry_email($request_id)
{
    global $wpdb;
    $table = $wpdb->prefix . 'ofst_cert_requests';

    $request = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $request_id));

    if (!$request) {
        return ['success' => false, 'error' => 'Request not found'];
    }

    if (empty($request->certificate_file)) {
        return ['success' => false, 'error' => 'No certificate file to send'];
    }

    $email_sent = ofst_cert_send_certificate_email($request);

    if ($email_sent) {
        $wpdb->update(
            $table,
            [
                'status' => 'issued',
                'rejection_reason' => null
            ],
            ['id' => $request_id]
        );

        return ['success' => true];
    } else {
        ofst_cert_log_email_failure($request_id);
        return ['success' => false, 'error' => 'Failed to send email'];
    }
}
