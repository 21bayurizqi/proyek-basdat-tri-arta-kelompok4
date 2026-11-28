<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();
requireApproverAdmin('/barang/index.php');

header('Location: index.php?open=new_material');
exit;
