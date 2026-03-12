<?php
require_once __DIR__ . '/auth/Auth.php';
Auth::init();
Auth::logout();
header('Location: /index.php');
exit;
