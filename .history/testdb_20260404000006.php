<?php
ob_start();
require_once 'model.php';

$user = User::findByEmail('azizbenamara@gmail.com');
ob_clean();
echo json_encode($user);