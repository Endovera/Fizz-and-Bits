<?php
require 'auth_admin.php';
require 'config.php';

// Validate POST parameters
if (!isset($_POST['order_id'], $_POST['status'])) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

$order_id = intval($_POST['order_id']);
$status = $_POST['status'];

// Validate new status
$validStatuses = ['on process', 'completed', 'canceled'];
if (!in_array($status, $validStatuses)) {
    echo json_encode(['error' => 'Invalid status']);
    exit;
}

// Fetch current order status and customer owner
$orderStmt = $db->prepare(
    "SELECT order_status, user_id, total_amount, order_type, delivery_eta
     FROM orders WHERE order_id = ?"
);
$orderStmt->bind_param("i", $order_id);
$orderStmt->execute();
$order = $orderStmt->get_result()->fetch_assoc();

if (!$order) {
    echo json_encode(['error' => 'Order not found']);
    exit;
}

// Restrict status changes if already completed or canceled
if ($order['order_status'] !== 'on process') {
    echo json_encode(['error' => 'Cannot modify order that is already ' . $order['order_status']]);
    exit;
}

// Update order status
$stmt = $db->prepare("UPDATE orders SET order_status=? WHERE order_id=?");
$stmt->bind_param("si", $status, $order_id);

if (!$stmt->execute()) {
    echo json_encode(['error' => 'Unable to update order status']);
    exit;
}

if ($status === 'completed' || $status === 'canceled') {
    $notification_type = $status === 'completed'
        ? 'order_completed'
        : 'order_canceled';

    $notification_message = $status === 'completed'
        ? 'Your order RYS-' . str_pad($order_id, 6, '0', STR_PAD_LEFT)
            . ' has been completed by Fizz & Bits.'
        : 'Your order RYS-' . str_pad($order_id, 6, '0', STR_PAD_LEFT)
            . ' has been canceled by Fizz & Bits.';

    $notificationQuery = "
        INSERT INTO notifications
            (user_id, order_id, notification_type, message)
        VALUES (?, ?, ?, ?)
    ";

    $notificationStmt = $db->prepare($notificationQuery);
    $customer_user_id = (int) $order['user_id'];
    $notificationStmt->bind_param(
        "iiss",
        $customer_user_id,
        $order_id,
        $notification_type,
        $notification_message
    );
    $notificationStmt->execute();

    $customerStmt = $db->prepare(
        "SELECT cp.email, u.first_name, u.last_name
         FROM customers_profile cp
         LEFT JOIN users u ON u.user_id = cp.user_id
         WHERE cp.user_id = ?"
    );
    $customerStmt->bind_param("i", $customer_user_id);
    $customerStmt->execute();
    $customer = $customerStmt->get_result()->fetch_assoc();

    if (
        $customer
        && filter_var($customer['email'] ?? '', FILTER_VALIDATE_EMAIL)
        && MAILTRAP_API_TOKEN !== "PASTE_SANDBOX_API_TOKEN_HERE"
        && MAILTRAP_INBOX_ID !== "PASTE_SANDBOX_INBOX_ID_HERE"
    ) {
        $order_label = 'RYS-' . str_pad($order_id, 6, '0', STR_PAD_LEFT);
        $customer_name = trim(
            ($customer['first_name'] ?? '') . ' '
            . ($customer['last_name'] ?? '')
        );
        $status_text = $status === 'completed'
            ? 'Your order has been completed by Fizz & Bits.'
            : 'We are sorry, but your order has been canceled by Fizz & Bits.';
        $delivery_text = '';

        if (
            $order['order_type'] === 'delivery'
            && !empty($order['delivery_eta'])
        ) {
            $delivery_text = "\nEstimated delivery: "
                . date('m/d/Y h:i A', strtotime($order['delivery_eta']))
                . "\n";
        }

        if ($status === 'completed') {
            $email_subject = 'Fizz & Bits Order Completed - ' . $order_label;
            $email_text =
                "Hello " . $customer_name . ",\n\n"
                . $status_text . "\n\n"
                . "Order number: " . $order_label . "\n"
                . "Order type: " . ucfirst($order['order_type']) . "\n"
                . "Total amount: ₱"
                . number_format((float) $order['total_amount'], 2)
                . "\n"
                . $delivery_text
                . "\nThank you for ordering from Fizz & Bits!";
        } else {
            $email_subject = 'Fizz & Bits Order Canceled - ' . $order_label;
            $email_text =
                "Hello " . $customer_name . ",\n\n"
                . $status_text . "\n\n"
                . "Order number: " . $order_label . "\n\n"
                . "We apologize for the inconvenience. Please contact Fizz & Bits "
                . "if you need assistance with this order.\n\n"
                . "Thank you for your understanding.";
        }

        $email_payload = [
            'from' => [
                'email' => MAILTRAP_FROM_EMAIL,
                'name' => MAILTRAP_FROM_NAME
            ],
            'to' => [[
                'email' => $customer['email'],
                'name' => $customer_name
            ]],
            'subject' => $email_subject,
            'text' => $email_text
        ];

        $mailtrap_url =
            "https://sandbox.api.mailtrap.io/api/send/"
            . rawurlencode((string) MAILTRAP_INBOX_ID);
        $mailtrap_ch = curl_init($mailtrap_url);

        curl_setopt_array($mailtrap_ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . MAILTRAP_API_TOKEN,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($email_payload)
        ]);

        $mailtrap_response = curl_exec($mailtrap_ch);
        $mailtrap_error = curl_error($mailtrap_ch);
        $mailtrap_status = curl_getinfo(
            $mailtrap_ch,
            CURLINFO_HTTP_CODE
        );
        curl_close($mailtrap_ch);

        if (
            $mailtrap_response === false
            || $mailtrap_error !== ''
            || $mailtrap_status < 200
            || $mailtrap_status >= 300
        ) {
            error_log(
                'Mailtrap status email failed for order '
                . $order_id
                . ': '
                . ($mailtrap_error ?: 'HTTP ' . $mailtrap_status)
            );
        }
    }
}

// If marking as completed, update inventory and availability
if ($status === 'completed') {
    $items = $db->query("
        SELECT menu_item_id, quantity
        FROM order_items
        WHERE order_id = $order_id
    ");

    while ($item = $items->fetch_assoc()) {
        $menu_id = $item['menu_item_id'];
        $qty = $item['quantity'];

        // Deduct stock
        $db->query("
            UPDATE inventory
            SET quantity_in_stock = quantity_in_stock - $qty
            WHERE menu_item_id = $menu_id
        ");

        // Check remaining stock
        $res = $db->query("
            SELECT quantity_in_stock FROM inventory
            WHERE menu_item_id = $menu_id
        ");

        $stock = $res->fetch_assoc()['quantity_in_stock'];

        if ($stock <= 0) {
            $db->query("
                UPDATE menu_items
                SET availability_status='not available'
                WHERE menu_item_id = $menu_id
            ");
        }
    }
}

echo json_encode(['success' => 'Order status updated to ' . $status]);
