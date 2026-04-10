<?php
/**
 * Aircom Contact Form Handler
 * Receives POST with name, email, subject, message.
 * Sends admin notification and sender confirmation.
 */

// ── Config ──────────────────────────────────────────────────────────────
define('ADMIN_EMAIL', 'info@aircom.bg');
define('SITE_NAME',   'Aircom');

header('Content-Type: application/json; charset=utf-8');

// ── Anti-bot: honeypot ──────────────────────────────────────────────────
if (!empty($_POST['website'])) {
    http_response_code(200);
    echo json_encode(['success' => true]);
    exit;
}

// ── Collect & validate fields ───────────────────────────────────────────
$name    = str_replace(["\r", "\n", "\t"], '', trim($_POST['name']    ?? ''));
$email   = trim($_POST['email']   ?? '');
$subject = str_replace(["\r", "\n", "\t"], '', trim($_POST['subject'] ?? ''));
$message = trim($_POST['message'] ?? '');

$errors = [];
if ($name === '')    $errors[] = 'Name is required.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if ($subject === '') $errors[] = 'Subject is required.';
if ($message === '') $errors[] = 'Message is required.';

if (!empty($errors)) {
    echo json_encode(['success' => false, 'message' => implode(' ', $errors)]);
    exit;
}

$ts = date('Y-m-d H:i:s');

// ── Email to admin ──────────────────────────────────────────────────────
$adminSubject = "Contact form: $subject ($name)";

$adminBody = "New contact form submission.\n\n"
    . "Name: $name\n"
    . "Email: $email\n"
    . "Subject: $subject\n"
    . "Time: $ts\n\n"
    . "Message:\n"
    . "----------\n"
    . "$message\n"
    . "----------\n\n"
    . "Reply directly to this email to respond to $name.\n";

$adminHeaders = "From: Aircom <info@aircom.bg>\r\n"
    . "Reply-To: $name <$email>\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail(ADMIN_EMAIL, $adminSubject, $adminBody, $adminHeaders);

// ── Confirmation email to sender ────────────────────────────────────────
$senderSubject = "We received your message — $subject";

$senderBody = "Dear $name,\n\n"
    . "Thank you for reaching out. We received your message and will be in touch.\n\n"
    . "If anything else comes to mind in the meantime, feel free to reply to this email.\n\n"
    . "Warm regards,\n"
    . "The Aircom Team\n"
    . "aircom.bg\n";

$senderHeaders = "From: Aircom <info@aircom.bg>\r\n"
    . "Reply-To: info@aircom.bg\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail($email, $senderSubject, $senderBody, $senderHeaders);

// ── Success ─────────────────────────────────────────────────────────────
echo json_encode(['success' => true, 'message' => 'Your message has been sent.']);
