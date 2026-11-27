<?php
session_name("customer_session");
session_start();
date_default_timezone_set("Asia/Manila");

if (!isset($_SESSION["user_id"])) {
    header("Location: index.php");
    exit();
}

require_once "config.php";

$conn = $db;
$user_id = (int) $_SESSION["user_id"];
$checkout_error = "";

/*
|--------------------------------------------------------------------------
| Mockoon helper
|--------------------------------------------------------------------------
*/

function callMockoon($url, $payload)
{
    if (!function_exists("curl_init")) {
        return [
            "success" => false,
            "error" => "PHP cURL is not enabled."
        ];
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $response_body = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($response_body === false || $curl_error !== "") {
        return [
            "success" => false,
            "error" => "Mockoon connection failed: " . $curl_error
        ];
    }

    $response_data = json_decode($response_body, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            "success" => false,
            "error" => "Mockoon returned invalid JSON."
        ];
    }

    return [
        "success" => true,
        "http_status" => $http_status,
        "data" => $response_data
    ];
}

function callMockoonPayment($order_id, $amount)
{
    $scenario = $_POST["mock_scenario"] ?? "";

    $url = "http://localhost:9001/api/v1/payments";

    if ($scenario !== "") {
        $url .= "?scenario=" . urlencode($scenario);
    }

    return callMockoon($url, [
        "order_id" => $order_id,
        "amount" => (float) $amount,
        "payment_method" => "mockoon_test"
    ]);
}

function callMockoonNotification($order_id, $recipient)
{
    return callMockoon(
        "http://localhost:9001/api/v1/notifications",
        [
            "order_id" => $order_id,
            "recipient" => $recipient,
            "notification_type" => "order_confirmation",
            "message" => "Your Fizz & Bits order has been confirmed."
        ]
    );
}

function geocodeAddress($address)
{
    $url = "https://nominatim.openstreetmap.org/search?"
        . http_build_query([
            "q" => $address,
            "format" => "jsonv2",
            "limit" => 1,
            "countrycodes" => "ph"
        ]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => "FizzAndBitsAcademicProject/1.0",
        CURLOPT_HTTPHEADER => ["Accept: application/json"]
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $error !== "" || $status < 200 || $status >= 300) {
        return ["success" => false, "error" => "Location lookup failed."];
    }

    $results = json_decode($body, true);

    if (!is_array($results) || empty($results[0]["lat"]) || empty($results[0]["lon"])) {
        return ["success" => false, "error" => "Address could not be located."];
    }

    return [
        "success" => true,
        "latitude" => (float) $results[0]["lat"],
        "longitude" => (float) $results[0]["lon"],
        "display_name" => $results[0]["display_name"] ?? $address
    ];
}

function getRouteEstimate($origin_lat, $origin_lon, $destination_lat, $destination_lon)
{
    $url = "https://router.project-osrm.org/route/v1/driving/"
        . $origin_lon . "," . $origin_lat
        . ";" . $destination_lon . "," . $destination_lat
        . "?overview=false";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_USERAGENT => "FizzAndBitsAcademicProject/1.0"
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $error !== "" || $status < 200 || $status >= 300) {
        return ["success" => false, "error" => "Route lookup failed."];
    }

    $data = json_decode($body, true);
    $route = $data["routes"][0] ?? null;

    if (!$route || !isset($route["distance"], $route["duration"])) {
        return ["success" => false, "error" => "No route was found."];
    }

    return [
        "success" => true,
        "distance_km" => round(((float) $route["distance"]) / 1000, 2),
        "estimated_minutes" => max(1, (int) ceil(((float) $route["duration"]) / 60))
    ];
}

function callMockoonDelivery($payload)
{
    return callMockoon(
        "http://localhost:9001/api/v1/deliveries",
        $payload
    );
}

function callMailtrapNotification(
    $order_id,
    $recipient,
    $customer_name,
    $order_type,
    $amount,
    $delivery_eta = null
) {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        return [
            "success" => false,
            "error" => "The contact value is not a valid email address."
        ];
    }

    if (
        MAILTRAP_API_TOKEN === "PASTE_SANDBOX_API_TOKEN_HERE"
        || MAILTRAP_INBOX_ID === "PASTE_SANDBOX_INBOX_ID_HERE"
    ) {
        return [
            "success" => false,
            "error" => "Mailtrap credentials are not configured."
        ];
    }

    $order_label = "RYS-" . str_pad($order_id, 6, "0", STR_PAD_LEFT);

    if ($order_type === "delivery" && $delivery_eta !== null) {
        $fulfillment_details = "Estimated delivery: "
            . date("m/d/Y h:i A", strtotime($delivery_eta));
    } else {
        $fulfillment_details =
            "Pickup details will be shown after order confirmation.";
    }

    $email_payload = [
        "from" => [
            "email" => MAILTRAP_FROM_EMAIL,
            "name" => MAILTRAP_FROM_NAME
        ],
        "to" => [
            ["email" => $recipient, "name" => $customer_name]
        ],
        "subject" => "Fizz & Bits Order Confirmation - " . $order_label,
        "text" =>
            "Hello " . $customer_name . ",\n\n"
            . "Your Fizz & Bits order has been confirmed.\n\n"
            . "Order number: " . $order_label . "\n"
            . "Order type: " . ucfirst($order_type) . "\n"
            . "Total amount: ₱" . number_format($amount, 2) . "\n"
            . $fulfillment_details . "\n\n"
            . "Thank you for ordering from Fizz & Bits!"
    ];

    $ch = curl_init(
        "https://sandbox.api.mailtrap.io/api/send/"
        . rawurlencode((string) MAILTRAP_INBOX_ID)
    );

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . MAILTRAP_API_TOKEN,
            "Content-Type: application/json"
        ],
        CURLOPT_POSTFIELDS => json_encode($email_payload)
    ]);

    $response_body = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response_body === false || $curl_error !== "") {
        return [
            "success" => false,
            "error" => "Mailtrap connection failed: " . $curl_error
        ];
    }

    return [
        "success" => $http_status >= 200 && $http_status < 300,
        "http_status" => $http_status,
        "response" => json_decode($response_body, true)
    ];
}

/*
|--------------------------------------------------------------------------
| Load cart
|--------------------------------------------------------------------------
*/

$submitted_fulfillment = $_POST["fulfillment_type"] ?? "";

if (!in_array($submitted_fulfillment, ["pickup", "delivery"], true)) {
    $submitted_fulfillment = ((float) ($_POST["shipping"] ?? 0) > 0)
        ? "delivery"
        : "pickup";
}

$shipping_cost = $submitted_fulfillment === "delivery"
    ? 50.0
    : 0.0;

$cart_query = "
    SELECT
        cart_items.*,
        menu_items.name,
        menu_items.price,
        menu_items.image_url,
        menu_categories.name AS category_name
    FROM cart_items
    INNER JOIN cart
        ON cart_items.cart_id = cart.cart_id
    INNER JOIN menu_items
        ON cart_items.menu_item_id = menu_items.menu_item_id
    INNER JOIN menu_categories
        ON menu_items.category_id = menu_categories.category_id
    WHERE cart.user_id = ?
";

$cart_stmt = $conn->prepare($cart_query);
$cart_stmt->bind_param("i", $user_id);
$cart_stmt->execute();

$cart_result = $cart_stmt->get_result();

$items = [];
$subtotal = 0;

while ($row = $cart_result->fetch_assoc()) {
    $items[] = $row;
    $subtotal += (float) $row["price"] * (int) $row["quantity"];
}

$grand_total = $subtotal + $shipping_cost;

/*
|--------------------------------------------------------------------------
| Complete order
|--------------------------------------------------------------------------
*/

if (
    $_SERVER["REQUEST_METHOD"] === "POST"
    && isset($_POST["complete_order"])
) {
    $required_fields = [
        "contact",
        "fname",
        "lname",
        "address",
        "postal",
        "city",
        "region",
        "phone"
    ];

    foreach ($required_fields as $field) {
        if (empty(trim($_POST[$field] ?? ""))) {
            $checkout_error = "Please complete all required fields.";
            break;
        }
    }

    if (empty($items)) {
        $checkout_error = "Your cart is empty.";
    }

    $payment_choice = $_POST["pay"] ?? "2";

    if (!in_array($payment_choice, ["1", "2"], true)) {
        $checkout_error = "Invalid payment option.";
    }

    if (
        $payment_choice === "2"
        && ($_POST["payment_confirmed"] ?? "0") !== "1"
    ) {
        $checkout_error = "Please confirm the payment first.";
    }

    if ($checkout_error === "") {
        $order_date = date("Y-m-d H:i:s");

        $contact = trim($_POST["contact"]);
        $full_name = trim($_POST["fname"]) . " " . trim($_POST["lname"]);
        $address = trim($_POST["address"]);

        if (!empty(trim($_POST["suite"] ?? ""))) {
            $address .= ", " . trim($_POST["suite"]);
        }

        $city = trim($_POST["city"]);
        $region = trim($_POST["region"]);
        $postal = trim($_POST["postal"]);
        $phone = trim($_POST["phone"]);

        $order_type = $submitted_fulfillment;

        try {
            $conn->begin_transaction();

            /*
             * Create order.
             * The transaction is rolled back if Mockoon payment fails.
             */
            $order_query = "
                INSERT INTO orders
                (
                    user_id,
                    order_date,
                    order_status,
                    total_amount,
                    delivery_city,
                    delivery_region,
                    postal_code,
                    phone,
                    full_name,
                    address,
                    order_type
                )
                VALUES (?, ?, 'on process', ?, ?, ?, ?, ?, ?, ?, ?)
            ";

            $order_stmt = $conn->prepare($order_query);

            $order_stmt->bind_param(
                "isdsssssss",
                $user_id,
                $order_date,
                $grand_total,
                $city,
                $region,
                $postal,
                $phone,
                $full_name,
                $address,
                $order_type
            );

            if (!$order_stmt->execute()) {
                throw new Exception("Unable to create the order.");
            }

            $new_order_id = $conn->insert_id;

            if ($order_type === "delivery") {
                $delivery_eta = date(
                    "Y-m-d H:i:s",
                    strtotime($order_date . " +30 minutes")
                );

                $delivery_eta_query = "
                    UPDATE orders
                    SET delivery_eta = ?
                    WHERE order_id = ? AND user_id = ?
                ";

                $delivery_eta_stmt = $conn->prepare($delivery_eta_query);
                $delivery_eta_stmt->bind_param(
                    "sii",
                    $delivery_eta,
                    $new_order_id,
                    $user_id
                );

                if (!$delivery_eta_stmt->execute()) {
                    throw new Exception("Unable to save delivery estimate.");
                }
            }

            /*
             * Move cart items into order_items.
             */
            $order_items_query = "
                INSERT INTO order_items
                    (order_id, menu_item_id, quantity, price)
                SELECT
                    ?,
                    cart_items.menu_item_id,
                    cart_items.quantity,
                    menu_items.price
                FROM cart_items
                INNER JOIN cart
                    ON cart_items.cart_id = cart.cart_id
                INNER JOIN menu_items
                    ON cart_items.menu_item_id = menu_items.menu_item_id
                WHERE cart.user_id = ?
            ";

            $order_items_stmt = $conn->prepare($order_items_query);
            $order_items_stmt->bind_param(
                "ii",
                $new_order_id,
                $user_id
            );

            if (!$order_items_stmt->execute()) {
                throw new Exception("Unable to save order items.");
            }

            /*
             * Payment handling.
             */
            if ($payment_choice === "2") {
                $payment_result = callMockoonPayment(
                    $new_order_id,
                    $grand_total
                );

                $payment_data = $payment_result["data"] ?? [];

                if (
                    !$payment_result["success"]
                    || ($payment_result["http_status"] ?? 0) !== 200
                    || ($payment_data["payment_status"] ?? "") !== "paid"
                ) {
                    throw new Exception(
                        $payment_data["message"]
                        ?? $payment_result["error"]
                        ?? "Payment failed."
                    );
                }

                $payment_method = "mockoon_test";
                $amount_paid = $grand_total;
            } else {
                $payment_method = "COD";
                $amount_paid = 0;
            }

            /*
             * Save payment record.
             */
            $payment_query = "
                INSERT INTO payments
                    (order_id, payment_date, payment_method, amount_paid)
                VALUES (?, NOW(), ?, ?)
            ";

            $payment_stmt = $conn->prepare($payment_query);
            $payment_stmt->bind_param(
                "isd",
                $new_order_id,
                $payment_method,
                $amount_paid
            );

            if (!$payment_stmt->execute()) {
                throw new Exception("Unable to save payment information.");
            }

            /*
             * Clear cart only after payment succeeds.
             */
            $clear_cart_query = "
                DELETE cart_items
                FROM cart_items
                INNER JOIN cart
                    ON cart_items.cart_id = cart.cart_id
                WHERE cart.user_id = ?
            ";

            $clear_cart_stmt = $conn->prepare($clear_cart_query);
            $clear_cart_stmt->bind_param("i", $user_id);

            if (!$clear_cart_stmt->execute()) {
                throw new Exception("Unable to clear the cart.");
            }

            $conn->commit();

            /*
             * Notification failure should not cancel the order.
             */
            if ($order_type === "delivery") {
                $delivery_address = $address
                    . ", " . $city
                    . ", " . $region
                    . " " . $postal
                    . ", Philippines";

                // Fixed Fizz & Bits origin in Quezon City, Philippines.
                $store_latitude = 14.6760;
                $store_longitude = 121.0437;
                $delivery_distance_km = 0.0;
                $delivery_minutes = 30;

                $location_result = geocodeAddress($delivery_address);

                if ($location_result["success"]) {
                    $route_result = getRouteEstimate(
                        $store_latitude,
                        $store_longitude,
                        $location_result["latitude"],
                        $location_result["longitude"]
                    );

                    if ($route_result["success"]) {
                        $delivery_distance_km = $route_result["distance_km"];
                        $delivery_minutes = $route_result["estimated_minutes"];
                        $delivery_eta = date(
                            "Y-m-d H:i:s",
                            strtotime($order_date . " +" . $delivery_minutes . " minutes")
                        );
                    }
                }

                $delivery_payload = [
                    "order_id" => $new_order_id,
                    "pickup_address" => "Fizz & Bits, Quezon City, Philippines",
                    "delivery_address" => $delivery_address,
                    "distance_km" => $delivery_distance_km,
                    "estimated_minutes" => $delivery_minutes,
                    "customer_name" => $full_name
                ];

                $delivery_result = callMockoonDelivery($delivery_payload);
                $delivery_data = $delivery_result["data"] ?? [];

                $delivery_id = $delivery_data["delivery_id"]
                    ?? "DEL-" . str_pad($new_order_id, 6, "0", STR_PAD_LEFT);
                $delivery_status = $delivery_data["status"] ?? "assigned";
                $delivery_rider = $delivery_data["rider"] ?? "Simulated Rider";

                $delivery_update_query = "
                    UPDATE orders
                    SET delivery_eta = ?,
                        delivery_id = ?,
                        delivery_status = ?,
                        delivery_rider = ?,
                        delivery_distance_km = ?
                    WHERE order_id = ? AND user_id = ?
                ";

                $delivery_update_stmt = $conn->prepare($delivery_update_query);
                $delivery_update_stmt->bind_param(
                    "ssssdii",
                    $delivery_eta,
                    $delivery_id,
                    $delivery_status,
                    $delivery_rider,
                    $delivery_distance_km,
                    $new_order_id,
                    $user_id
                );
                $delivery_update_stmt->execute();

                if (!$delivery_result["success"]) {
                    error_log(
                        "Mockoon delivery failed for order "
                        . $new_order_id
                    );
                }
            }

            $notification_message = $order_type === "pickup"
                ? "Your pickup order RYS-"
                    . str_pad($new_order_id, 6, "0", STR_PAD_LEFT)
                    . " has been placed."
                : "Your delivery order RYS-"
                    . str_pad($new_order_id, 6, "0", STR_PAD_LEFT)
                    . " has been placed."
                    . " Estimated arrival: "
                    . date("h:i A", strtotime($delivery_eta));

            $mockoon_notification_result = callMockoonNotification(
                $new_order_id,
                $contact
            );

            if ($mockoon_notification_result["success"]) {
                $notification_query = "
                    INSERT INTO notifications
                        (user_id, order_id, notification_type, message)
                    VALUES (?, ?, ?, ?)
                ";

                $notification_type = "order_placed";
                $notification_stmt = $conn->prepare($notification_query);
                $notification_stmt->bind_param(
                    "iiss",
                    $user_id,
                    $new_order_id,
                    $notification_type,
                    $notification_message
                );
                $notification_stmt->execute();
            } else {
                error_log(
                    "Mockoon notification failed for order "
                    . $new_order_id
                );
            }

            $notification_result = callMailtrapNotification(
                $new_order_id,
                $contact,
                $full_name,
                $order_type,
                $grand_total,
                $delivery_eta ?? null
            );

            if (!$notification_result["success"]) {
                error_log(
                    "Notification failed for order "
                    . $new_order_id
                    . ": "
                    . ($notification_result["error"] ?? "Unknown error")
                );
            }

            header(
                "Location: receipt.php?order_id="
                . $new_order_id
            );
            exit();

        } catch (Exception $exception) {
            $conn->rollback();
            $checkout_error = $exception->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Delivery Information</title>

    <link
        href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css"
        rel="stylesheet"
    >

    <link
        rel="stylesheet"
        href="../css/checkout_style.css?v=1.4"
    >

    <link
        rel="stylesheet"
        href="../css/fonts.css?v=1.2"
    >

    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/menu.css">
    <link rel="stylesheet" href="../css/delivery.css">
</head>

<body>

<?php if ($checkout_error !== ""): ?>
<script>
    alert(<?= json_encode($checkout_error) ?>);
</script>
<?php endif; ?>

<?php include "navbar.php"; ?>

<section class="menu-header">
    <h1 class="font-surimi-title">
        DELIVERY INFORMATION
    </h1>

    <div class="progress-container font-poppins">
        <div class="step active">My Cart</div>
        <div class="line active"></div>

        <div class="step active">
            Delivery Information
        </div>

        <div class="line"></div>

        <div class="step">
            E-Receipt
        </div>
    </div>
</section>

<section class="container">
    <form
        action="checkout.php"
        method="POST"
        class="checkout-grid"
    >
        <div class="form-section font-poppins">

            <section>
                <h3 class="font-surimi-subheading">
                    Contact
                </h3>

                <input
                    type="text"
                    name="contact"
                    class="pill-input"
                    placeholder="Email or mobile phone number"
                    value="<?= htmlspecialchars($_POST["contact"] ?? "") ?>"
                    required
                >
            </section>

            <section>
                <h3 class="font-surimi-subheading">
                    Delivery
                </h3>

                <select name="country" class="pill-input">
                    <option value="PH" selected>
                        Philippines
                    </option>
                </select>

                <div class="row">
                    <input
                        type="text"
                        name="fname"
                        class="pill-input"
                        placeholder="First name"
                        value="<?= htmlspecialchars($_POST["fname"] ?? "") ?>"
                        required
                    >

                    <input
                        type="text"
                        name="lname"
                        class="pill-input"
                        placeholder="Last name"
                        value="<?= htmlspecialchars($_POST["lname"] ?? "") ?>"
                        required
                    >
                </div>

                <input
                    type="text"
                    name="address"
                    class="pill-input"
                    placeholder="Address"
                    value="<?= htmlspecialchars($_POST["address"] ?? "") ?>"
                    required
                >

                <input
                    type="text"
                    name="suite"
                    class="pill-input"
                    placeholder="Apartment, suite, etc. (optional)"
                    value="<?= htmlspecialchars($_POST["suite"] ?? "") ?>"
                >

                <div class="row">
                    <input
                        type="text"
                        name="postal"
                        class="pill-input"
                        placeholder="Postal Code"
                        value="<?= htmlspecialchars($_POST["postal"] ?? "") ?>"
                        required
                    >

                    <input
                        type="text"
                        name="city"
                        class="pill-input"
                        placeholder="City"
                        value="<?= htmlspecialchars($_POST["city"] ?? "") ?>"
                        required
                    >
                </div>

                <input
                    type="text"
                    name="region"
                    class="pill-input"
                    placeholder="Region"
                    value="<?= htmlspecialchars($_POST["region"] ?? "") ?>"
                    required
                >

                <input
                    type="tel"
                    name="phone"
                    class="pill-input"
                    placeholder="Phone"
                    value="<?= htmlspecialchars($_POST["phone"] ?? "") ?>"
                    required
                >
            </section>

            <section class="shipping-section">
                <h3 class="font-surimi-subheading">
                    Shipping method
                </h3>

                <div class="shipping-container">
                    <label class="shipping-item font-poppins">
                        <div class="shipping-info">
                            <input
                                type="radio"
                                name="shipping"
                                value="0"
                                <?= $shipping_cost == 0 ? "checked" : "" ?>
                            >

                            <span>Pick Up</span>
                        </div>

                        <span class="shipping-price">
                            Free
                        </span>
                    </label>

                    <label
                        class="shipping-item font-poppins"
                        style="margin-top: 10px;"
                    >
                        <div class="shipping-info">
                            <input
                                type="radio"
                                name="shipping"
                                value="50"
                                <?= $shipping_cost == 50 ? "checked" : "" ?>
                            >

                            <span>Delivery</span>
                        </div>

                        <span class="shipping-price">
                            ₱50.00
                        </span>
                    </label>
                </div>

            </section>

            <section id="payment-section">
                <h3 class="font-surimi-subheading">
                    Payment
                </h3>

                <div
                    class="payment-container"
                    style="display: flex; flex-direction: column; gap: 12px;"
                >
                    <label class="shipping-item font-poppins">
                        <div class="shipping-info">
                            <input
                                type="radio"
                                name="pay"
                                value="1"
                                <?= ($_POST["pay"] ?? "2") === "1"
                                    ? "checked"
                                    : "" ?>
                            >

                            <span>Cash on Delivery</span>
                        </div>
                    </label>

                    <label class="shipping-item font-poppins">
                        <div class="shipping-info">
                            <input
                                type="radio"
                                name="pay"
                                value="2"
                                <?= ($_POST["pay"] ?? "2") === "2"
                                    ? "checked"
                                    : "" ?>
                            >

                            <span>Online Payment Test</span>
                        </div>
                    </label>
                </div>
            </section>

            <button
                type="submit"
                name="complete_order"
                class="btn-complete font-poppins"
            >
                Complete Order
            </button>

            <input
                type="hidden"
                name="fulfillment_type"
                id="fulfillment-type"
                value="<?= htmlspecialchars($submitted_fulfillment) ?>"
            >

            <input
                type="hidden"
                name="payment_confirmed"
                id="payment-confirmed"
                value="0"
            >

        </div>

        <aside class="summary-section">
            <h3 class="font-poppins">
                Order Summary
            </h3>

            <?php foreach ($items as $item): ?>
                <?php
                    $item_total =
                        (float) $item["price"]
                        * (int) $item["quantity"];
                ?>

                <div class="order-item">
                    <div class="img-container">
                        <img
                            src="../../images/<?= htmlspecialchars($item["image_url"]) ?>"
                            class="item-img"
                            alt="<?= htmlspecialchars($item["name"]) ?>"
                        >

                        <span class="qty-badge">
                            <?= (int) $item["quantity"] ?>
                        </span>
                    </div>

                    <div class="item-info">
                        <p class="font-poppins">
                            <?= htmlspecialchars($item["name"]) ?>
                        </p>

                        <span class="category">
                            <?= htmlspecialchars($item["category_name"]) ?>
                        </span>
                    </div>

                    <span class="price">
                        ₱<?= number_format($item_total, 2) ?>
                    </span>
                </div>
            <?php endforeach; ?>

            <div class="totals font-poppins">
                <div class="total-row">
                    <span>Subtotal</span>
                    <span>
                        ₱<?= number_format($subtotal, 2) ?>
                    </span>
                </div>

                <div class="total-row">
                    <span>Shipping</span>
                    <span id="shipping-total">
                        <?= $shipping_cost > 0
                            ? "₱" . number_format($shipping_cost, 2)
                            : "FREE" ?>
                    </span>
                </div>

                <div class="total-row grand-total">
                    <strong>Total</strong>

                    <strong id="grand-total">
                        ₱<?= number_format($grand_total, 2) ?>
                    </strong>
                </div>
            </div>
        </aside>
    </form>
</section>

<div id="payment-modal" class="payment-modal" aria-hidden="true">
    <div class="payment-backdrop"></div>

    <div class="payment-dialog" role="dialog" aria-modal="true">
        <button type="button" id="close-payment-modal" class="payment-close">
            &times;
        </button>

        <h2 class="font-surimi-title">Payment</h2>

        <div id="payment-form-content">
        <label for="mock-card-number">Card Number</label>
        <input
            id="mock-card-number"
            type="text"
            placeholder="XXXX-XXXX-XXXX-XXXX"
            maxlength="19"
                inputmode="numeric"
                pattern="[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}[- ]?[0-9]{4}"
            autocomplete="off"
        >

        <label for="mock-cardholder">Cardholder Name</label>
        <input
            id="mock-cardholder"
            type="text"
            placeholder="Your name"
                maxlength="80"
                autocomplete="off"
                spellcheck="false"
        >

        <div class="payment-row">
            <div>
                <label for="mock-expiry">Expiry Date</label>
                <input
                    id="mock-expiry"
                    type="text"
                    placeholder="MM/YY"
                    maxlength="5"
                        inputmode="numeric"
                        pattern="(0[1-9]|1[0-2])/[0-9]{2}"
                    autocomplete="off"
                >
            </div>

            <div>
                <label for="mock-cvv">CVV</label>
                <input
                    id="mock-cvv"
                    type="text"
                    placeholder="XXX"
                    maxlength="3"
                        inputmode="numeric"
                        pattern="[0-9]{3}"
                    autocomplete="off"
                >
            </div>
        </div>

        <label for="mock-amount">Amount Payable</label>
        <input
            id="mock-amount"
            class="payment-amount"
            type="text"
            value="₱<?= number_format($grand_total, 2) ?>"
            readonly
        >

        <p id="payment-timer" class="payment-timer">
            Expires in 01:00
        </p>

        <div class="payment-actions">
            <button type="button" id="cancel-payment" class="payment-cancel">
                Cancel
            </button>

            <button type="button" id="confirm-payment" class="payment-confirm">
                Confirm Payment
            </button>
        </div>
        </div>

        <div id="payment-failure" class="payment-failure" hidden>
            <p id="payment-failure-message"></p>

            <button type="button" id="back-to-order" class="payment-back">
                Go Back to Order
            </button>
        </div>
    </div>
</div>

<section class="btns-bottom">
    <div class="back-to-cart-container">
        <a href="cart.php" class="browse-btn">
            <i class="bx bx-arrow-back"></i>
            Back to Cart
        </a>
    </div>
</section>

<script>
const subtotalAmount = <?= json_encode((float) $subtotal) ?>;
const shippingOptions = document.querySelectorAll(
    'input[name="shipping"]'
);
const shippingTotal = document.getElementById("shipping-total");
const grandTotal = document.getElementById("grand-total");
const fulfillmentType = document.getElementById("fulfillment-type");

function formatPeso(amount) {
    return new Intl.NumberFormat("en-PH", {
        style: "currency",
        currency: "PHP",
        minimumFractionDigits: 2
    }).format(amount);
}

function updateCheckoutTotals() {
    const selectedShipping = document.querySelector(
        'input[name="shipping"]:checked'
    );

    const shippingAmount = selectedShipping
        ? Number(selectedShipping.value)
        : 0;

    fulfillmentType.value = shippingAmount > 0
        ? "delivery"
        : "pickup";

    shippingTotal.textContent = shippingAmount > 0
        ? formatPeso(shippingAmount)
        : "FREE";

    grandTotal.textContent = formatPeso(
        subtotalAmount + shippingAmount
    );
}

shippingOptions.forEach((option) => {
    option.addEventListener("change", updateCheckoutTotals);
});

updateCheckoutTotals();

const checkoutForm = document.querySelector(".checkout-grid");
const completeButton = document.querySelector(
    'button[name="complete_order"]'
);

const paymentModal = document.getElementById("payment-modal");
const paymentConfirmed = document.getElementById("payment-confirmed");
const confirmPayment = document.getElementById("confirm-payment");
const cancelPayment = document.getElementById("cancel-payment");
const closePaymentModal = document.getElementById("close-payment-modal");
const paymentTimer = document.getElementById("payment-timer");
const paymentFormContent = document.getElementById("payment-form-content");
const paymentFailure = document.getElementById("payment-failure");
const paymentFailureMessage = document.getElementById(
    "payment-failure-message"
);
const backToOrder = document.getElementById("back-to-order");
const cardNumberInput = document.getElementById("mock-card-number");
const cardholderInput = document.getElementById("mock-cardholder");
const expiryInput = document.getElementById("mock-expiry");
const cvvInput = document.getElementById("mock-cvv");

let secondsRemaining = 60;
let timerInterval = null;
let paymentWasConfirmed = false;

function keepDigits(value) {
    return value.replace(/\D/g, "");
}

function formatCardNumber(value) {
    const digits = keepDigits(value).slice(0, 16);
    return digits.match(/.{1,4}/g)?.join("-") ?? "";
}

function formatExpiry(value) {
    const digits = keepDigits(value).slice(0, 4);

    if (digits.length <= 2) {
        return digits;
    }

    return digits.slice(0, 2) + "/" + digits.slice(2);
}

cardNumberInput.addEventListener("input", function () {
    cardNumberInput.value = formatCardNumber(cardNumberInput.value);
});

expiryInput.addEventListener("input", function () {
    expiryInput.value = formatExpiry(expiryInput.value);
});

cvvInput.addEventListener("input", function () {
    cvvInput.value = keepDigits(cvvInput.value).slice(0, 3);
});

cardholderInput.addEventListener("input", function () {
    cardholderInput.value = cardholderInput.value
        .replace(/[^a-zA-Z .'-]/g, "")
        .slice(0, 80);
});

function resetPaymentModal() {
    paymentFormContent.hidden = false;
    paymentFailure.hidden = true;
    paymentTimer.hidden = false;
    closePaymentModal.hidden = false;
}

function showPaymentFailure(message) {
    clearInterval(timerInterval);
    paymentFormContent.hidden = true;
    paymentTimer.hidden = true;
    closePaymentModal.hidden = true;
    paymentFailureMessage.textContent = message;
    paymentFailure.hidden = false;
    paymentWasConfirmed = false;
    paymentConfirmed.value = "0";
}

function updatePaymentTimer() {
    const minutes = Math.floor(secondsRemaining / 60);
    const seconds = secondsRemaining % 60;

    paymentTimer.textContent =
        "Expires in "
        + String(minutes).padStart(2, "0")
        + ":"
        + String(seconds).padStart(2, "0");
}

function openPaymentModal() {
    resetPaymentModal();
    paymentModal.classList.add("is-open");
    paymentModal.setAttribute("aria-hidden", "false");

    secondsRemaining = 60;
    paymentWasConfirmed = false;
    paymentConfirmed.value = "0";
    confirmPayment.disabled = false;
    paymentTimer.classList.remove("expired");
    updatePaymentTimer();

    clearInterval(timerInterval);
    timerInterval = setInterval(() => {
        secondsRemaining--;
        updatePaymentTimer();

        if (secondsRemaining <= 0) {
            showPaymentFailure(
                "Payment failed: the payment session expired."
            );
            paymentTimer.classList.add("expired");
        }
    }, 1000);

    document.getElementById("mock-card-number").focus();
}

function closePaymentModalWindow() {
    clearInterval(timerInterval);
    paymentModal.classList.remove("is-open");
    paymentModal.setAttribute("aria-hidden", "true");
}

checkoutForm.addEventListener("submit", function (event) {
    const paymentChoice = checkoutForm.querySelector(
        'input[name="pay"]:checked'
    );

    const isCompleteOrder =
        event.submitter
        && event.submitter.name === "complete_order";

    if (
        isCompleteOrder
        && paymentChoice
        && paymentChoice.value === "2"
        && !paymentWasConfirmed
    ) {
        event.preventDefault();
        openPaymentModal();
    }
});

confirmPayment.addEventListener("click", function () {
    if (secondsRemaining <= 0) {
        return;
    }

    const cardNumber = document
        .getElementById("mock-card-number")
        .value
        .trim();
    const cardholder = document
        .getElementById("mock-cardholder")
        .value
        .trim();
    const expiry = document
        .getElementById("mock-expiry")
        .value
        .trim();
    const cvv = document
        .getElementById("mock-cvv")
        .value
        .trim();

    const cardDigits = keepDigits(cardNumber);
    const cardholderIsValid = /^[a-zA-Z][a-zA-Z .'-]{1,79}$/.test(
        cardholder
    );
    const expiryIsValid = /^(0[1-9]|1[0-2])\/[0-9]{2}$/.test(expiry);
    const cvvIsValid = /^[0-9]{3}$/.test(cvv);

    if (cardDigits.length !== 16) {
        alert("Card number must contain exactly 16 digits.");
        cardNumberInput.focus();
        return;
    }

    if (!cardholderIsValid) {
        alert("Enter a valid cardholder name using letters only.");
        cardholderInput.focus();
        return;
    }

    if (!expiryIsValid) {
        alert("Expiry date must use the MM/YY format.");
        expiryInput.focus();
        return;
    }

    if (!cvvIsValid) {
        alert("CVV must contain exactly 3 digits.");
        cvvInput.focus();
        return;
    }

    const normalizedCardNumber = cardNumber.replace(/[ -]/g, "");

    if (cvv === "000") {
        showPaymentFailure("Payment failed: incorrect CVV.");
        return;
    }

    const expiryParts = expiry.match(/^(\d{2})\/(\d{2})$/);

    if (!expiryParts) {
        showPaymentFailure("Payment failed: invalid expiry date.");
        return;
    }

    const expiryMonth = Number(expiryParts[1]);
    const expiryYear = 2000 + Number(expiryParts[2]);
    const currentDate = new Date();
    const currentMonth = currentDate.getMonth() + 1;
    const currentYear = currentDate.getFullYear();

    if (
        expiryMonth < 1
        || expiryMonth > 12
        || expiryYear < currentYear
        || (
            expiryYear === currentYear
            && expiryMonth < currentMonth
        )
    ) {
        showPaymentFailure("Payment failed: card has expired.");
        return;
    }

    if (normalizedCardNumber === "4000000000000002") {
        showPaymentFailure("Payment failed: card was declined.");
        return;
    }

    if (normalizedCardNumber === "4000000000000003") {
        showPaymentFailure("Payment failed: insufficient funds.");
        return;
    }

    paymentWasConfirmed = true;
    paymentConfirmed.value = "1";
    closePaymentModalWindow();

    checkoutForm.requestSubmit(completeButton);
});

cancelPayment.addEventListener("click", function () {
    showPaymentFailure("Payment verification was canceled.");
});

backToOrder.addEventListener("click", function () {
    paymentConfirmed.value = "0";
    closePaymentModalWindow();
});

closePaymentModal.addEventListener("click", closePaymentModalWindow);
</script>

</body>
</html>
