<?php
session_name("customer_session");
session_start();

header("Content-Type: application/json");

if (!isset($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false]);
    exit();
}

require_once "config.php";

$notification_id = filter_input(
    INPUT_POST,
    "notification_id",
    FILTER_VALIDATE_INT
);

if (!$notification_id || $notification_id < 1) {
    http_response_code(400);
    echo json_encode(["success" => false]);
    exit();
}

$user_id = (int) $_SESSION["user_id"];
$query = "
    UPDATE notifications
    SET is_read = 1
    WHERE notification_id = ? AND user_id = ?
";

$stmt = $db->prepare($query);
$stmt->bind_param("ii", $notification_id, $user_id);
$success = $stmt->execute();

echo json_encode(["success" => $success]);
