<?php

declare(strict_types=1);

use Behat\Config\Config;

// Every suite Sylius itself ships, from whichever file this install has: Sylius 2.3 turned its
// suites.yml into suites.php, and one static import cannot name a file only one minor ships.
$suites = dirname(__DIR__, 3) . '/vendor/sylius/sylius/src/Sylius/Behat/Resources/config/suites';

return (new Config())->import(is_file($suites . '.php') ? $suites . '.php' : $suites . '.yml');
