<?php


require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirectToDashboard(currentRole());
} else {
    header('Location: login.php');
    exit;
};