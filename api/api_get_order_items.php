<?php
header('Content-Type: application/json');

require_once __DIR__ . '/Auth.php';
Auth::init();
Auth::requireLogin();

$orderId = (int)($_GET['order_id'] ?? 0);

if (!$orderId) {
    http_response_code(400);
    echo json_encode(['error' => 'Order ID required']);
    exit;
}

$db = Database::get();

// Verify order belongs to user or user is owner
$stmt = $db->prepare("SELECT * FROM orders WHERE id=?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    echo json_encode(['error' => 'Order not found']);
    exit;
}

// Check permissions
$isOwner = Auth::isOwner();
$isOwnOrder = ($order['cashier_id'] === Auth::user()['id']);

if (!$isOwner && !$isOwnOrder) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied']);
    exit;
}

// Get order items
$stmt = $db->prepare("
    SELECT oi.id, m.name, oi.quantity, oi.unit_price
    FROM order_items oi
    JOIN menu_items m ON m.id = oi.menu_item_id
    WHERE oi.order_id = ?
    ORDER BY oi.id ASC
");
$stmt->execute([$orderId]);
$items = $stmt->fetchAll();

echo json_encode($items);
