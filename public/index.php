<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/app/bootstrap.php';

App\Core\App::run();
