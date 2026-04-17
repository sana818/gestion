<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once 'model.php';

$user = User::findByEmail('azizbenamara@gmail.com');
echo json_encode($user);