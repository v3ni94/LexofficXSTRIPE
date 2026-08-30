<?php
require_once __DIR__ . '/app/bootstrap.php';
require_once __DIR__ . '/app/auth.php';

if (current_user()) {
    redirect('dashboard.php');
}
redirect('login.php');
