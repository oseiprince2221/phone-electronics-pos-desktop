<?php

$dbDirectory = __DIR__ . '/../data';

if (!is_dir($dbDirectory)) {
    mkdir($dbDirectory, 0775, true);
}

$dbPath = $dbDirectory . '/phone_electronics_pos.sqlite';

try {
    $pdo = new PDO('sqlite:' . $dbPath);

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $pdo->exec('PRAGMA foreign_keys = ON');

} catch (PDOException $e) {
    die('Database connection failed. Check the database file and folder permissions.');
}