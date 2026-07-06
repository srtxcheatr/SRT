<?php
// api/purchase/esewa.php
//
// Doesn't touch balance — it records a claimed eSewa payment for you
// to manually verify and approve, same as your existing top-up flow.
// Still derives the real price from `sku` server-side, so an edited
// on-page price can't misrepresent what the buyer actually owes when
// you review it.

require __DIR__ . '/../../src/firebase.php';
require __DIR__ . '/../../src/catalog.php';

apply_cors();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$uid = require_firebase_uid();

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$sku = (string)($body['sku'] ?? '');
$product = catalog_find($sku);
if (!$product) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Unknown product']);
  exit;
}

$txCode    = trim((string)($body['txCode'] ?? ''));
$esewaId   = trim((string)($body['esewaId'] ?? ''));
$buyerName = trim((string)($body['name'] ?? ''));
$waNum     = trim((string)($body['waNum'] ?? ''));

if ($txCode === '' || $esewaId === '' || $buyerName === '' || $waNum === '') {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'Please fill in every field']);
  exit;
}

$db = firestore();
$userRef = $db->collection('users')->document($uid);

try {
  $userRef->update([
    ['path' => 'esewaOrders', 'value' => \Google\Cloud\Firestore\FieldValue::arrayUnion([[
      'sku' => $sku,
      'name' => $product['name'],
      'duration' => $product['duration'],
      'price' => $product['price'],
      'txCode' => $txCode,
      'esewaId' => $esewaId,
      'buyerName' => $buyerName,
      'whatsapp' => $waNum,
      'status' => 'PENDING',
      'at' => (new \DateTime())->format(DATE_ATOM),
    ]])],
  ]);
  echo json_encode(['success' => true]);
} catch (\Throwable $e) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Submission failed. Please try again.']);
}
