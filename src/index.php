<?php
require 'vendor/autoload.php';

use PPHP\Set;

$set = new Set();
$set->add("Hello World");

var_dump($set->values());
