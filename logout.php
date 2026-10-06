<?php
require_once 'config/config.php';
startSessionSafely();
session_destroy();
header('Location: login.php');
exit;
