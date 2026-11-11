<?php
require_once __DIR__ . '/configdatabase.php';
if (isset($pdo)) {
    echo "<h3>Database connection successful via PDO!</h3>";
}