<?php

require_once 'rb.php';
require_once 'vendor/autoload.php';

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Message\AMQPMessage;

R::setup(getenv('DB_DSN'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));

$connection = rabbitMqConnection();
$channel = $connection->channel();

$queue = 'student_enrollment';
$channel->exchange_declare('client_enrolled', 'fanout', durable: true, auto_delete: false);
$channel->queue_declare($queue, durable: true, auto_delete: false);
$channel->queue_bind($queue, 'client_enrolled');
$channel->basic_consume($queue, no_ack: true, callback: function (AMQPMessage $msg) {
    $properties = json_decode($msg->body, true);
    $token = bin2hex(random_bytes(32));

    $student = R::dispense('students');
    $student->name = $properties['name'];
    $student->email = $properties['email'];
    $student->password = password_hash(bin2hex(random_bytes(32)), PASSWORD_ARGON2ID);
    $student->password_reset_token = hash('sha256', $token);
    $student->password_reset_expires_at = (new DateTimeImmutable('+24 hours'))->format('Y-m-d H:i:s');
    R::store($student);

    try {
        sendMailTo($student, $token);
        echo 'E-mail enviado' . PHP_EOL;
    } catch (\Throwable $exception) {
        echo 'Falha ao enviar e-mail para ' . $student->email . ': ' . $exception->getMessage() . PHP_EOL;
    }
});

while ($channel->is_open()) {
    $channel->wait();
}

$channel->close();
$connection->close();
