<?php
error_reporting(0);
ini_set('display_errors', 0);
header('Content-Type: application/json');

// api/purchase/balance.php
//
// The browser sends only a `sku`. It has no say over price — that
// comes from catalog.php — and no say over balance — that's read
// fresh from Firestore inside a transaction, so two rapid clicks
// can't both succeed against a balance that only covers one of them.
// This is the endpoint your old client-side code used to trust
// (price, and a JS balance variable) for the exact exploit you
// described — editing price from 120 to 0 and buying.

require __DIR__ . '/../../src/firebase.php';
require __DIR__ . '/../../src/catalog.php';

apply_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$uid = require_firebase_uid(); // exits with 401 if the token doesn't check out

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$sku = (string)($body['sku'] ?? '');
$product = catalog_find($sku);

if (!$product) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Unknown product']);
  exit;
}
if (!$product['external']) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'This product does not support auto key. Use eSewa.']);
  exit;
}

$buyerName = trim((string)($body['name'] ?? ''));
$waNum = trim((string)($body['waNum'] ?? ''));
if ($buyerName === '' || $waNum === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Fill in Name and WhatsApp!']);
  exit;
}

$db = firestore();
$userRef = $db->collection('users')->document($uid);

try {
  $outcome = $db->runTransaction(function ($transaction) use ($userRef, $product, $sku, $buyerName, $waNum) {
    $snapshot = $transaction->snapshot($userRef);
    if (!$snapshot->exists()) {
      throw new \RuntimeException('Account not found');
    }

    // The ONLY balance check that matters. $balance comes from
    // Firestore, read inside this transaction — never from anything
    // the request sent.
    $balance = (int)($snapshot['balance'] ?? 0);
    if ($balance < $product['price']) {
      throw new \RuntimeException('Insufficient balance');
    }

    $newBalance = $balance - $product['price'];
    $key = strtoupper(bin2hex(random_bytes(8)));

    $transaction->update($userRef, [
      ['path' => 'balance', 'value' => $newBalance],
      ['path' => 'purchaseHistory', 'value' => \Google\Cloud\Firestore\FieldValue::arrayUnion([[
        'sku' => $sku,
        'name' => $product['name'],
        'row' => $product['row'],
        'duration' => $product['duration'],
        'price' => $product['price'],   // the REAL price, never the one the page sent
        'key' => $key,
        'buyerName' => $buyerName,
        'whatsapp' => $waNum,
        'at' => (new \DateTime())->format(DATE_ATOM),
      ]])],
    ]);

    return ['newBalance' => $newBalance, 'key' => $key];
  });

  echo json_encode([
    'success' => true,
    'newBalance' => $outcome['newBalance'],
    'key' => $outcome['key'],
  ]);

} catch (\RuntimeException $e) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Purchase failed. Please try again.']);
}
