<?php
namespace Dazz\Legacy;

class Config {
    const CHROME_PATH = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
    const DB_HOST     = '127.0.0.1';
    const DB_USER     = 'root';
    const DB_PASS     = 'M!cr0s0ft';
    const DB_NAME     = 'dazz';

    public static function getDB() {
    // Add the backslash here!
    \mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    echo "=== ATTEMPTING DB CONNECTION ===\n";

    try {
        $conn = new \mysqli(self::DB_HOST, self::DB_USER, self::DB_PASS, self::DB_NAME);
        
        $conn->set_charset("utf8mb4");
        
        echo "=== DB CONNECTION ESTABLISHED ===\n";
        return $conn;
    } catch (\mysqli_sql_exception $e) {
        error_log("Connection failed: " . $e->getMessage());
        die("=== DB CONNECTION FAILED: " . $e->getMessage() . " ===\n");
    }
    }
}