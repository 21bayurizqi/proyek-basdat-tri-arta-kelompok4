<?php
require_once __DIR__ . '/config/auth.php';

clearAuthSession();
header('Location: /login.php');
exit;
