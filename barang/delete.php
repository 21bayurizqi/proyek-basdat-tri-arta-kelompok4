<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
requireApproverAdmin('/barang/index.php');
require_once __DIR__ . '/../config/db.php';

$id = $_GET['id'] ?? '';
if ($id !== '') {
    try {
        $stmt = $pdo->prepare("DELETE FROM material WHERE id_material = :id");
        $stmt->execute([':id' => $id]);
        header('Location: /barang/index.php?msg=' . urlencode("Material $id berhasil dihapus."));
        exit;
    } catch (PDOException $e) {
        header('Location: /barang/index.php?err=' . urlencode("Tidak dapat menghapus $id karena masih terkait transaksi/kemasan QR."));
        exit;
    }
}
header('Location: /barang/index.php');
exit;