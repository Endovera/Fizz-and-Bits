<?php
    define("MYSQL_HOSTNAME", "localhost");
    define("MYSQL_USER", "root");
    define("MYSQL_PASSWORD", "");
    define("MYSQL_DATABASE", "fizz_and_bits_db");

    // Use the same Mailtrap Sandbox values as Customer_side/php/config.php.
    // Keep these local and do not commit the token publicly.
    define("MAILTRAP_API_TOKEN", "insert_here");
    define("MAILTRAP_INBOX_ID", "insert_here");
    define("MAILTRAP_FROM_EMAIL", "fizzandbits@example.com");
    define("MAILTRAP_FROM_NAME", "Fizz & Bits");

    $db = new mysqli(MYSQL_HOSTNAME, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE);

    if ($db -> connect_error) {
        die("Connection Failed: " . $db->connect_error);
    }
?>
