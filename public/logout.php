<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/src/Auth.php';

use BiaMiddleware\Auth;

Auth::logout();
header('Location: login.php');
exit;
