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
 * Verifies the Firebase ID token sent as "Authorization: Bearer <token>".
 * Exits with 401 if it's missing, expired, or doesn't check out.
 * This is what makes the returned uid trustworthy for everything else —
 * a hacker can send any uid they want in a request body, but they
 * cannot forge a token that verifies as someone else's.
 */
function require_firebase_uid(): string {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Missing Authorization header']);
    exit;
  }
  try {
    $verified = firebase()->createAuth()->verifyIdToken($m[1]);
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
    // 'https://your-custom-domain.com',
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
