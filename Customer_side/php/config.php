<?php
    define("MYSQL_HOSTNAME", "localhost");
    define("MYSQL_USER", "root");
    define("MYSQL_PASSWORD", "");
    define("MYSQL_DATABASE", "fizz_and_bits_db");

    // Mailtrap Email Sandbox settings.
    // Replace these placeholders locally; do not commit the token publicly.
    define("MAILTRAP_API_TOKEN", "03e05f729c0b6137705af8a2952e9ca8");
    define("MAILTRAP_INBOX_ID", "5042024");
    define("MAILTRAP_FROM_EMAIL", "fizzandbits@example.com");
    define("MAILTRAP_FROM_NAME", "Fizz & Bits");

    $db = new mysqli(MYSQL_HOSTNAME, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE);

    if ($db -> connect_error) {
        die("Connection Failed: " . $db->connect_error);
    }
?>
