<?php
require_once __DIR__ . '/../config/auth.php';
requireAuth();

header('Location: index.php?open=tambah_lead');
exit;
