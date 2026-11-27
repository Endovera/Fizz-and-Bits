<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$navbar_notifications = [];
$navbar_unread_count = 0;

if (isset($_SESSION["user_id"])) {
    require_once __DIR__ . "/config.php";

    $navbar_user_id = (int) $_SESSION["user_id"];
    $navbar_query = "
        SELECT notification_id, order_id, message, created_at, is_read
        FROM notifications
        WHERE user_id = ?
        ORDER BY created_at DESC
        LIMIT 10
    ";

    $navbar_stmt = $db->prepare($navbar_query);
    $navbar_stmt->bind_param("i", $navbar_user_id);
    $navbar_stmt->execute();
    $navbar_result = $navbar_stmt->get_result();

    while ($navbar_row = $navbar_result->fetch_assoc()) {
        $navbar_notifications[] = $navbar_row;

        if ((int) $navbar_row["is_read"] === 0) {
            $navbar_unread_count++;
        }
    }
}
?>


<link rel="stylesheet" href="../css/navbar.css?v=1.1">

<header class="navbar">
    <div class="nav-text left">
        <!-- <i class='bx bxs-note'></i> -->
        <a href="aboutus.php">About Us</a>
        <a href="contactus.php">Contact Us</a>
    </div>

    <div class="nav-logo">
        <a href="front.php" class="brand">
            <img src="/Fizz_and_Bits_Website/images/imagotype_logo.png" alt="Fizz & Bits Logo">
        </a>
    </div>

    <div class="nav-icons right">
    
        <a href="menu.php"><i class='bx bx-restaurant'></i></a>

        <div class="user-dropdown">
            <i class='bx bxs-user user-icon' onclick="toggleUserMenu()"></i>

            <div class="user-menu" id="userMenu">
                <p class="user-name">
                    <?php if (isset($_SESSION['fname']) && isset($_SESSION['lname'])): ?>
                        <?= $_SESSION['fname'] . ' ' . $_SESSION['lname']; ?>
                    <?php else: ?>
                        <?= $_SESSION['username'] ?? 'User'; ?>
                    <?php endif; ?>
                </p>

                <a href="logout.php" class="logout-btn">Log out</a>
            </div>
        </div>

        <div class="notification-dropdown">
            <button
                type="button"
                class="notification-button"
                onclick="toggleNotificationMenu(event)"
                aria-label="Notifications"
            >
                <i class='bx bxs-bell'></i>

                <?php if ($navbar_unread_count > 0): ?>
                    <span class="notification-badge">
                        <?= $navbar_unread_count > 9 ? "9+" : $navbar_unread_count ?>
                    </span>
                <?php endif; ?>
            </button>

            <div class="notification-menu" id="notificationMenu">
                <div class="notification-title">Notifications</div>

                <?php if (empty($navbar_notifications)): ?>
                    <p class="notification-empty">No notifications yet.</p>
                <?php else: ?>
                    <?php foreach ($navbar_notifications as $notification): ?>
                        <a
                            class="notification-item <?= (int) $notification["is_read"] === 0 ? "unread" : "" ?>"
                            href="<?= $notification["order_id"]
                                ? "receipt.php?order_id=" . (int) $notification["order_id"]
                                : "#" ?>"
                            data-notification-id="<?= (int) $notification["notification_id"] ?>"
                        >
                            <span class="notification-message">
                                <?= htmlspecialchars($notification["message"]) ?>
                            </span>
                            <small>
                                <?= htmlspecialchars(date(
                                    "M j, g:i A",
                                    strtotime($notification["created_at"])
                                )) ?>
                            </small>
                        </a>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <a href="cart.php"><i class='bx bxs-cart'></i></a>

    </div>
</header>

<script>
    function toggleUserMenu() {
        document.getElementById("userMenu").classList.toggle("active");
    }

    function toggleNotificationMenu(event) {
        event.stopPropagation();
        document.getElementById("notificationMenu").classList.toggle("active");
    }

    document.querySelectorAll(".notification-item").forEach(function (item) {
        item.addEventListener("click", function (event) {
            const notificationId = item.dataset.notificationId;
            const destination = item.href;

            event.preventDefault();

            fetch("mark_notification_read.php", {
                method: "POST",
                headers: {
                    "Content-Type": "application/x-www-form-urlencoded"
                },
                body: "notification_id=" + encodeURIComponent(notificationId)
            }).finally(function () {
                if (destination && !destination.endsWith("#")) {
                    window.location.href = destination;
                }
            });
        });
    });

    document.addEventListener("click", function(event) {
        const dropdown = document.querySelector(".user-dropdown");
        const menu = document.getElementById("userMenu");
        const notificationDropdown = document.querySelector(
            ".notification-dropdown"
        );
        const notificationMenu = document.getElementById("notificationMenu");

        if (!dropdown.contains(event.target)) {
            menu.classList.remove("active");
        }

        if (!notificationDropdown.contains(event.target)) {
            notificationMenu.classList.remove("active");
        }
    });
</script>
