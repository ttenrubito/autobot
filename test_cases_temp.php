<?php
require_once "config.php";
require_once "includes/Database.php";

try {
    $db = Database::getInstance();
    $result = $db->queryOne("SELECT COUNT(*) as cnt FROM cases");
    echo "Result: ";
    print_r($result);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
