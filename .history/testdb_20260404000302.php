<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'Database.php';
require_once 'model.php';

echo "etape 1";
$user = User::findByEmail('azizbenamara@gmail.com');
echo "etape 2";
var_dump($user);