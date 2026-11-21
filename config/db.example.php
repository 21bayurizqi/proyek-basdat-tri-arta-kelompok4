<?php
// Salin file ini menjadi db.php lalu sesuaikan dengan konfigurasi PostgreSQL lokal masing-masing
$host = 'localhost';
$port = '5432';
$dbname = 'nama_database_fase3';
$user = 'postgres';
$password = 'password_postgres_kamu';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$dbname;";
    $pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Koneksi PostgreSQL gagal: " . $e->getMessage());
}