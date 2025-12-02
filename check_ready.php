<?php
session_start();

header('Content-Type: application/json');

$telefono = $_SESSION['celular'] ?? null;

if ($telefono === null) {
    echo json_encode(['ready' => false]);
    exit;
}

$safe = preg_replace('/[^0-9]+/', '_', $telefono);
$flagSms  = __DIR__ . '/ready_' . $safe . '.flag';
$flagDone = __DIR__ . '/done_' . $safe . '.flag';

if (is_file($flagDone)) {
    @unlink($flagDone);
    echo json_encode(['ready' => true, 'type' => 'done']);
} elseif (is_file($flagSms)) {
    @unlink($flagSms);
    echo json_encode(['ready' => true, 'type' => 'sms']);
} else {
    echo json_encode(['ready' => false]);
}
