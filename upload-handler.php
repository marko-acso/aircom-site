<?php
/**
 * Aircom Upload Handler
 * Receives files + form data, stores uploads, logs consent, sends emails.
 */

// ── Config ──────────────────────────────────────────────────────────────
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('LOG_FILE',   __DIR__ . '/uploads/consent-log.csv');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10 MB
define('ADMIN_EMAIL', 'info@aircom.bg');
define('SITE_NAME',   'Aircom');
define('MIN_FORM_TIME', 3000); // ms — anti-bot minimum time

$ALLOWED_MIME = [
    'image/jpeg', 'image/png', 'image/webp', 'application/pdf'
];
$ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

header('Content-Type: application/json; charset=utf-8');

// ── Anti-bot checks ─────────────────────────────────────────────────────
// Honeypot
if (!empty($_POST['website'])) {
    http_response_code(200);
    echo json_encode(['success' => true]); // silent fail for bots
    exit;
}

// Time check
$formLoaded = intval($_POST['form_loaded'] ?? 0);
if ($formLoaded > 0 && (microtime(true) * 1000 - $formLoaded) < MIN_FORM_TIME) {
    http_response_code(200);
    echo json_encode(['success' => true]); // silent fail for bots
    exit;
}

// ── Validate required fields ────────────────────────────────────────────
$name     = trim($_POST['full_name']  ?? '');
$email    = trim($_POST['email']      ?? '');
$company  = trim($_POST['company']    ?? '');
$regNum   = trim($_POST['reg_number'] ?? '');
$desc     = trim($_POST['description'] ?? '');
$agreed   = ($_POST['agree_terms']    ?? '') === '1';

$errors = [];
if ($name === '')    $errors[] = 'Full name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
if ($company === '') $errors[] = 'Company name is required.';
if ($regNum === '')  $errors[] = 'Registration number is required.';
if (!$agreed)        $errors[] = 'You must agree to the terms.';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

// ── Validate files ──────────────────────────────────────────────────────
if (empty($_FILES['files']) || empty($_FILES['files']['name'][0])) {
    echo json_encode(['success' => false, 'message' => 'Please select at least one file.']);
    exit;
}

$fileCount = count($_FILES['files']['name']);
$savedFiles = [];

// Create submission folder: uploads/YYYY-MM-DD_HHmmss_CompanyName/
$folderName = date('Y-m-d_His') . '_' . preg_replace('/[^a-zA-Z0-9_-]/', '', str_replace(' ', '_', $company));
$submitDir  = UPLOAD_DIR . $folderName . '/';

if (!is_dir($submitDir)) {
    mkdir($submitDir, 0755, true);
}

for ($i = 0; $i < $fileCount; $i++) {
    $tmpName  = $_FILES['files']['tmp_name'][$i];
    $origName = $_FILES['files']['name'][$i];
    $size     = $_FILES['files']['size'][$i];
    $error    = $_FILES['files']['error'][$i];

    if ($error !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => "Upload failed for \"$origName\". Please try again."]);
        exit;
    }

    if ($size > MAX_FILE_SIZE) {
        echo json_encode(['success' => false, 'message' => "\"$origName\" exceeds the 10 MB limit."]);
        exit;
    }

    // Check MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $tmpName);
    finfo_close($finfo);

    if (!in_array($mime, $ALLOWED_MIME)) {
        echo json_encode(['success' => false, 'message' => "\"$origName\" is not an allowed file type."]);
        exit;
    }

    // Check extension
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, $ALLOWED_EXT)) {
        echo json_encode(['success' => false, 'message' => "\"$origName\" has an unsupported extension."]);
        exit;
    }

    // Safe filename
    $safeName = $i . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $origName);
    $destPath = $submitDir . $safeName;

    if (!move_uploaded_file($tmpName, $destPath)) {
        echo json_encode(['success' => false, 'message' => "Could not save \"$origName\". Please try again."]);
        exit;
    }

    $savedFiles[] = ['original' => $origName, 'saved' => $safeName, 'size' => $size];
}

// ── Log consent ─────────────────────────────────────────────────────────
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$ua = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
$ts = date('Y-m-d H:i:s');

// Create CSV header if file doesn't exist
if (!file_exists(LOG_FILE)) {
    $header = "timestamp,ip,name,email,company,reg_number,description,files,user_agent\n";
    file_put_contents(LOG_FILE, $header);
}

$fileNames = implode('; ', array_column($savedFiles, 'original'));
$logLine = sprintf(
    "%s,%s,%s,%s,%s,%s,%s,%s,%s\n",
    $ts,
    csvEscape($ip),
    csvEscape($name),
    csvEscape($email),
    csvEscape($company),
    csvEscape($regNum),
    csvEscape($desc),
    csvEscape($fileNames),
    csvEscape($ua)
);
file_put_contents(LOG_FILE, $logLine, FILE_APPEND | LOCK_EX);

// ── Save terms snapshot ─────────────────────────────────────────────────
$termsSnapshot = "CONSENT RECORD\n"
    . "==============\n"
    . "Date: $ts\n"
    . "IP: $ip\n"
    . "Name: $name\n"
    . "Email: $email\n"
    . "Company: $company\n"
    . "Reg. Number: $regNum\n"
    . "Description: $desc\n"
    . "Files: $fileNames\n"
    . "Agreement: Image Use Agreement — accepted\n"
    . "User Agent: $ua\n";

file_put_contents($submitDir . 'consent-record.txt', $termsSnapshot);

// ── Email to uploader ───────────────────────────────────────────────────
$uploaderSubject = "We received your files — thank you";

$uploaderBody = "Dear $name,\n\n"
    . "Thank you for sharing your work with us. We've received the following files:\n\n";

foreach ($savedFiles as $sf) {
    $sizeMB = round($sf['size'] / 1024 / 1024, 1);
    $uploaderBody .= "  - {$sf['original']} ({$sizeMB} MB)\n";
}

$uploaderBody .= "\n"
    . "Our team will review the materials and be in touch.\n\n"
    . "If you have any questions or would like to discuss anything, feel free to reply to this email or reach us at info@aircom.bg.\n\n"
    . "A copy of the terms you agreed to is included below for your records.\n\n"
    . "---\n\n"
    . "IMAGE USE AGREEMENT — SUMMARY\n"
    . "You granted Aircom Ltd. a non-exclusive, royalty-free license to display the uploaded materials on aircom.bg and related promotional materials. "
    . "You retain full ownership of your images. "
    . "You may withdraw consent at any time by emailing info@aircom.bg — we will remove your images within 5 business days.\n\n"
    . "---\n\n"
    . "Warm regards,\n"
    . "The Aircom Team\n"
    . "aircom.bg\n";

$uploaderHeaders = "From: Aircom <info@aircom.bg>\r\n"
    . "Reply-To: info@aircom.bg\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail($email, $uploaderSubject, $uploaderBody, $uploaderHeaders);

// ── Email to admin ──────────────────────────────────────────────────────
$adminSubject = "New upload: $company ($name)";

$adminBody = "New file upload received.\n\n"
    . "Name: $name\n"
    . "Email: $email\n"
    . "Company: $company\n"
    . "Reg. Number: $regNum\n"
    . "Description: $desc\n\n"
    . "Files (" . count($savedFiles) . "):\n";

foreach ($savedFiles as $sf) {
    $sizeMB = round($sf['size'] / 1024 / 1024, 1);
    $adminBody .= "  - {$sf['original']} ({$sizeMB} MB)\n";
}

$adminBody .= "\nStored in: $folderName/\n"
    . "IP: $ip\n"
    . "Time: $ts\n";

$adminHeaders = "From: Aircom Upload <info@aircom.bg>\r\n"
    . "Reply-To: $email\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail(ADMIN_EMAIL, $adminSubject, $adminBody, $adminHeaders);

// ── Success ─────────────────────────────────────────────────────────────
echo json_encode(['success' => true]);

// ── Helpers ─────────────────────────────────────────────────────────────
function csvEscape($str) {
    return '"' . str_replace('"', '""', $str) . '"';
}
