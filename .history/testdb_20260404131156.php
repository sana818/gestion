<?php
header('Content-Type: application/json; charset=utf-8');

echo json_encode(['success' => true, 'message' => 'Test 1: Header OK']);
exit;
?>