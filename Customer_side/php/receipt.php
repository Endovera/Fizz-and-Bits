<?php
    session_name("customer_session");
    session_start();

    date_default_timezone_set("Asia/Manila");

    if (!isset($_SESSION['user_id'])) {
        header("Location: login.php");
        exit();
    }
    include 'config.php';

    $conn = $db;

    if (!isset($_GET['order_id'])) {
        header("Location: menu.php");
        exit();
    }

    $order_id = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT);

    if (!$order_id || $order_id < 1) {
        header("Location: menu.php");
        exit();
    }

    $session_user_id = (int) $_SESSION['user_id'];
    $pickup_store_address = "Quezon City, Philippines";

    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['confirm_pickup'])) {
        $pickup_date = $_POST['pickup_date'] ?? '';
        $pickup_time = $_POST['pickup_time'] ?? '';

        $valid_date = DateTime::createFromFormat('Y-m-d', $pickup_date);
        $valid_time = DateTime::createFromFormat('H:i', $pickup_time);
        $tomorrow = (new DateTime('tomorrow'))->setTime(0, 0);

        if ($valid_date) {
            $valid_date->setTime(0, 0);
        }

        if (
            !$valid_date
            || $valid_date->format('Y-m-d') !== $pickup_date
            || $valid_date < $tomorrow
            || !$valid_time
            || $valid_time->format('H:i') !== $pickup_time
        ) {
            die("Please choose a valid future pickup date and time.");
        }

        $pickup_query = "
            UPDATE orders
            SET pickup_address = ?,
                pickup_date = ?,
                pickup_time = ?,
                pickup_confirmed = 1
            WHERE order_id = ?
              AND user_id = ?
              AND order_type = 'pickup'
        ";

        $pickup_stmt = $conn->prepare($pickup_query);
        $pickup_stmt->bind_param(
            "sssii",
            $pickup_store_address,
            $pickup_date,
            $pickup_time,
            $order_id,
            $session_user_id
        );
        $pickup_stmt->execute();

        header("Location: receipt.php?order_id=" . $order_id);
        exit();
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST'
        && isset($_POST['confirm_delivery'])) {
        $delivery_query = "
            UPDATE orders
            SET delivery_notice_confirmed = 1
            WHERE order_id = ?
              AND user_id = ?
              AND order_type = 'delivery'
        ";

        $delivery_stmt = $conn->prepare($delivery_query);
        $delivery_stmt->bind_param(
            "ii",
            $order_id,
            $session_user_id
        );
        $delivery_stmt->execute();

        header("Location: receipt.php?order_id=" . $order_id);
        exit();
    }

    // 1. Fetch Order General Info (Total, Date, Type)
    $order_query = "SELECT * FROM orders WHERE order_id = ? AND user_id = ?";
    $stmt = $conn->prepare($order_query);
    $stmt->bind_param("ii", $order_id, $session_user_id);
    $stmt->execute();
    $order_data = $stmt->get_result()->fetch_assoc();

    if (!$order_data) {
        die("Order not found.");
    }

    // 2. Fetch Items for this specific Order (The crucial part for the table)
    // This links your order_items table to menu_items to get the name
    $items_query = "SELECT oi.quantity, oi.price, mi.name 
                    FROM order_items oi
                    JOIN menu_items mi ON oi.menu_item_id = mi.menu_item_id 
                    WHERE oi.order_id = ?";
    $stmt_items = $conn->prepare($items_query);
    $stmt_items->bind_param("i", $order_id);
    $stmt_items->execute();
    $items_result = $stmt_items->get_result();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>E-Receipt - Fizz & Bits</title>
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link rel="stylesheet" href="../css/checkout_style.css">
    <link rel="stylesheet" href="../css/fonts.css">
    <link rel="stylesheet" href="../css/menu.css">
    <link rel="stylesheet" href="../css/navbar.css">
    <link rel="stylesheet" href="../css/receipt.css?v=1.1">
    <link rel="stylesheet" href="../css/delivery.css">

</head>
<body>
    <?php include 'navbar.php'; ?>

    <section class="menu-header">
        <h1 class="font-surimi-title">DELIVERY INFORMATION</h1>

        <div class="progress-container font-poppins">
            <div class="step active">My Cart</div>
            <div class="line active"></div>
            <div class="step active">Delivery Information</div>
            <div class="line active"></div>
            <div class="step active">E Receipt</div>
        </div>

    </section>

    <section>
        <div class="receipt-container font-poppins">
            <div class="receipt-header">
                <p>Order ID: #<?php echo $order_data['order_id']; ?></p>
                <p><?php echo date("m/d/Y", strtotime($order_data['order_date'])); ?></p>
                <p class="receipt-status status-<?= htmlspecialchars(
                    str_replace(
                        ' ',
                        '-',
                        strtolower($order_data['order_status'])
                    )
                ) ?>">
                    Status: <?= htmlspecialchars(ucfirst($order_data['order_status'])) ?>
                </p>
                <img src="../../images/receipt_car.png" alt="Logo" style="width: 150px;">
                <h3>Fizz & Bits</h3>
                <p>
                    <strong>
                        <?php if ($order_data['order_status'] === 'completed'): ?>
                            Your order is completed!
                        <?php elseif ($order_data['order_status'] === 'canceled'): ?>
                            Your order was canceled.
                        <?php elseif ($order_data['order_type'] === 'pickup'): ?>
                            Your pickup details are ready!
                        <?php else: ?>
                            Your order is on its way!
                        <?php endif; ?>
                    </strong>
                </p>
            </div>

            <hr>

            <table style="width:100%; text-align:left; border-collapse: collapse;">
                <thead>
                    <tr style="font-size: 0.8rem; color: #666;">
                        <th>ITEMS</th>
                        <th>QTY</th>
                        <th style="text-align:right;">PRICE</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $items_result->data_seek(0); // Reset pointer just in case
                    while($item = $items_result->fetch_assoc()): 
                        $row_total = $item['price'] * $item['quantity'];
                    ?>
                    <tr style="border-bottom: 1px solid #f9f9f9;">
                        <td style="font-size: 0.9rem;">
                            <strong><?php echo htmlspecialchars($item['name']); ?></strong><br>
                            <span style="font-size: 0.75rem; color: #888;">₱<?php echo number_format($item['price'], 2); ?> each</span>
                        </td>
                        <td style="vertical-align: top; text-align: center;"><?php echo $item['quantity']; ?></td>
                        <td style="vertical-align: top; text-align: right;">₱<?php echo number_format($row_total, 2); ?></td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <hr>

            <div class="total-row <?= $order_data['order_type'] === 'pickup' ? 'pickup-fee-row' : '' ?>">
                <span>
                    <?php echo ($order_data['order_type'] == 'delivery')
                        ? "Delivery"
                        : "For Pickup"; ?>

                    <?php if (
                        $order_data['order_type'] == 'pickup'
                        && !empty($order_data['pickup_date'])
                        && !empty($order_data['pickup_time'])
                    ): ?>
                        <small class="pickup-receipt-details">
                            Pickup Date:
                            <?= htmlspecialchars(date('m/d/Y', strtotime($order_data['pickup_date']))) ?><br>
                            Pickup Time:
                            <?= htmlspecialchars(date('h:i A', strtotime($order_data['pickup_time']))) ?>
                        </small>
                    <?php endif; ?>
                </span>
                <span><?php echo ($order_data['order_type'] == 'delivery') ? "₱50.00" : "FREE"; ?></span>
            </div>
            <div class="total-row" style="font-weight: bold; font-size: 1.2rem; margin-top: 10px;">
                <span>TOTAL</span>
                <span>₱<?php echo number_format($order_data['total_amount'], 2); ?></span>
            </div>

            <div style="text-align: center; margin-top: 30px;">
                <img src="https://bwipjs-api.metafloor.com/?bcid=code128&text=<?php echo str_pad($order_data['order_id'], 10, '0', STR_PAD_LEFT); ?>&includetext&scale=2&height=15" 
                    alt="Order Barcode" 
                    style="max-width: 100%; height: auto;">
            </div>
        </div>
    </section>

    <?php if (
        $order_data['order_type'] === 'pickup'
        && $order_data['order_status'] === 'on process'
        && (int) ($order_data['pickup_confirmed'] ?? 0) !== 1
    ): ?>
        <?php
            $default_pickup_date = date('Y-m-d', strtotime('+1 day'));
            $default_pickup_time = '10:30';
        ?>

        <div class="pickup-modal" aria-hidden="false">
            <div class="pickup-backdrop"></div>

            <div class="pickup-dialog" role="dialog" aria-modal="true">
                <h2 class="font-surimi-title">Pickup Details</h2>

                <label>Order Number</label>
                <input
                    type="text"
                    value="RYS-<?= str_pad($order_data['order_id'], 6, '0', STR_PAD_LEFT) ?>"
                    readonly
                >

                <label>Pickup Address</label>
                <input
                    type="text"
                    value="<?= htmlspecialchars($pickup_store_address) ?>"
                    readonly
                >

                <div class="pickup-row">
                    <div>
                        <label for="pickup-date">Pickup Date</label>
                        <input
                            id="pickup-date"
                            name="pickup_date"
                            type="date"
                            form="pickup-confirmation-form"
                            min="<?= htmlspecialchars(date('Y-m-d', strtotime('+1 day'))) ?>"
                            value="<?= $default_pickup_date ?>"
                            required
                        >
                    </div>

                    <div>
                        <label for="pickup-time">Pickup Time</label>
                        <input
                            id="pickup-time"
                            name="pickup_time"
                            type="time"
                            form="pickup-confirmation-form"
                            value="<?= $default_pickup_time ?>"
                            required
                        >
                    </div>
                </div>

                <p class="pickup-note">
                    Kindly save these details and show them at the counter.
                </p>

                <p class="pickup-note">
                    Your pickup details are also saved with your order.
                </p>

                <form
                    id="pickup-confirmation-form"
                    method="POST"
                    action="receipt.php?order_id=<?= (int) $order_id ?>"
                >
                    <input type="hidden" name="confirm_pickup" value="1">

                    <button type="submit" class="pickup-confirm">
                        Confirm Pickup
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if (
        $order_data['order_type'] === 'delivery'
        && $order_data['order_status'] === 'on process'
        && (int) ($order_data['delivery_notice_confirmed'] ?? 0) !== 1
    ): ?>
        <?php
            $delivery_eta = !empty($order_data['delivery_eta'])
                ? new DateTime($order_data['delivery_eta'])
                : (new DateTime($order_data['order_date']))->modify('+30 minutes');

            $delivery_minutes = max(
                1,
                (int) round(
                    ($delivery_eta->getTimestamp()
                        - strtotime($order_data['order_date'])) / 60
                )
            );

            $has_route_estimate = (float) ($order_data['delivery_distance_km'] ?? 0) > 0;

            $delivery_address = trim(
                $order_data['address']
                . ', '
                . $order_data['delivery_city']
                . ', '
                . $order_data['delivery_region']
                . ' '
                . $order_data['postal_code']
            );
        ?>

        <div class="pickup-modal delivery-modal" aria-hidden="false">
            <div class="pickup-backdrop"></div>

            <div class="pickup-dialog" role="dialog" aria-modal="true">
                <h2 class="font-surimi-title">Delivery Details</h2>

                <label>Order Number</label>
                <input
                    type="text"
                    value="RYS-<?= str_pad($order_data['order_id'], 6, '0', STR_PAD_LEFT) ?>"
                    readonly
                >

                <label>Delivery Address</label>
                <input
                    type="text"
                    value="<?= htmlspecialchars($delivery_address) ?>"
                    readonly
                >

                <label>Estimated Delivery Time</label>
                <input
                    type="text"
                    value="<?= htmlspecialchars($delivery_eta->format('m/d/Y h:i A')) ?>"
                    readonly
                >

                <?php if (!empty($order_data['delivery_id'])): ?>
                    <label>Delivery Reference</label>
                    <input
                        type="text"
                        value="<?= htmlspecialchars($order_data['delivery_id']) ?>"
                        readonly
                    >
                <?php endif; ?>

                <?php if (!empty($order_data['delivery_rider'])): ?>
                    <label>Assigned Rider</label>
                    <input
                        type="text"
                        value="<?= htmlspecialchars($order_data['delivery_rider']) ?>"
                        readonly
                    >
                <?php endif; ?>

                <p class="pickup-note">
                    <?php if ($has_route_estimate): ?>
                        Your order is estimated to arrive in
                        <?= $delivery_minutes ?> minutes based on the route estimate.
                    <?php else: ?>
                        Your order uses the default 30-minute delivery estimate
                        because the route service was unavailable.
                    <?php endif; ?>
                </p>

                <form
                    method="POST"
                    action="receipt.php?order_id=<?= (int) $order_id ?>"
                >
                    <input type="hidden" name="confirm_delivery" value="1">

                    <button type="submit" class="pickup-confirm">
                        Got It
                    </button>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <section class="btns-bottom">
        <div class="back-to-cart-container">
            <a href="menu.php" class="browse-btn">
                <i class='bx bx-arrow-back'></i> Back to Browsing
            </a>
        </div>
    </section>

</body>
</html>
