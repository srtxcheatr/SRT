<?php
// firebase.php — the trust boundary. Every purchase endpoint requires
// this file, which verifies WHO is calling (via their Firebase ID
// token) and gives access to Firestore with full admin trust
// (bypassing your Security Rules, the same way a Cloud Function
// would). No endpoint should touch Firestore any other way.
//
// SETUP — do this before deploying:
// 1. Firebase Console → Project Settings (gear icon) → Service
//    Accounts tab → "Generate new private key". Downloads a JSON file.
// 2. On Render: your service → Environment → add an environment
//    variable named FIREBASE_SERVICE_ACCOUNT_JSON, and paste the
//    ENTIRE content of that JSON file as its value.
//    Locally: save the same file as serviceAccountKey.json right next
//    to this one (already in .gitignore — never commit the real key).

// ------------------------------------------------------------------
// HARDENING — this must run before anything else in this file.
//
// What you hit ("Unexpected token '<' ... is not valid JSON") happens
// when PHP prints a warning/notice/deprecation straight into the HTTP
// response, ahead of the real JSON — your browser then tries to parse
// that warning's HTML as data and fails. This makes that class of bug
// structurally impossible from here on: warnings get logged instead
// of printed, and even a hard fatal error still comes back as clean
// JSON instead of raw PHP output.
// ------------------------------------------------------------------
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start(); // buffer everything; nothing reaches the client except what we deliberately echo

set_error_handler(function ($errno, $errstr, $errfile, $errline) {
  error_log("[srtx-backend] $errstr in $errfile:$errline");
  return true; // handled — prevents PHP's default (which would print it)
});

register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    error_log('[srtx-backend] FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Internal server error. Please try again.']);
  }
});

require __DIR__ . '/../vendor/autoload.php';

use Kreait\Firebase\Factory;

function firebase(): Factory {
  static $factory = null;
  if ($factory === null) {
    $json = getenv('FIREBASE_SERVICE_ACCOUNT_JSON');
    if ($json) {
      $creds = json_decode($json, true);
      if (!$creds) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfigured: FIREBASE_SERVICE_ACCOUNT_JSON is not valid JSON']);
        exit;
      }
      $factory = (new Factory())->withServiceAccount($creds);
    } else {
      $keyPath = __DIR__ . '/../serviceAccountKey.json';
      if (!file_exists($keyPath)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Server misconfigured: no service account credentials found']);
        exit;
      }
      $factory = (new Factory())->withServiceAccount($keyPath);
    }
  }
  return $factory;
}

/**
 * Reads the Authorization header from wherever it actually lands.
 * Apache (and some proxies in front of it, including on some hosts)
 * strip the Authorization header from $_SERVER by default — PHP never
 * sees it even though the browser sent it correctly. This checks every
 * place it might have ended up instead of assuming just one.
 */
function get_bearer_token(): ?string {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

  // Some Apache/CGI setups only expose it under this alternate key
  // when a rewrite/redirect happened internally.
  if ($header === '' && !empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
    $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
  }

  // Last resort: ask Apache directly for the raw request headers.
  // Header name casing can vary by client/proxy, so match case-insensitively.
  if ($header === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $name => $value) {
      if (strcasecmp($name, 'Authorization') === 0) {
        $header = $value;
        break;
      }
    }
  }

  if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    return $m[1];
  }
  return null;
}

/**
 * Verifies the Firebase ID token sent as "Authorization: Bearer <token>".
 * Exits with 401 if it's missing, expired, or doesn't check out.
 * This is what makes the returned uid trustworthy for everything else —
 * a hacker can send any uid they want in a request body, but they
 * cannot forge a token that verifies as someone else's.
 */
function require_firebase_uid(): string {
  $token = get_bearer_token();
  if ($token === null) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing Authorization header']);
    exit;
  }
  try {
    $verified = firebase()->createAuth()->verifyIdToken($token);
    return (string)$verified->claims()->get('sub');
  } catch (\Throwable $e) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired login. Please refresh and try again.']);
    exit;
  }
}

function firestore() {
  return firebase()->createFirestore()->database();
}

/**
 * Your frontend and this backend are on different domains, so every
 * request is cross-origin — the browser blocks it unless this backend
 * explicitly allows the frontend's origin. Add every real domain your
 * site is served from to this list (Firebase Hosting gives you two by
 * default; add a custom domain here too if you use one).
 */
function apply_cors(): void {
  $allowed = [
    'https://bronzx.web.app',
    'https://bronzx.firebaseapp.com',
    'https://reselle.onrender.com',
  ];
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
  }
  header('Access-Control-Allow-Headers: Content-Type, Authorization');
  header('Access-Control-Allow-Methods: POST, OPTIONS');
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
  }
}
