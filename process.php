#!/usr/bin/php -q
<?php

chdir(dirname(__FILE__));

require_once './Classes/Swift-4.3.0/lib/swift_required.php';
require_once './Classes/FileHashScanner.php';

$scanner = new FileHashScanner;
$log = $scanner->doScan('/var/www', array(/* excludes  */));

$configuration = include './Configurations/Mailer.php';

$transport = Swift_SmtpTransport::newInstance($configuration['hostname'], $configuration['port'])
    ->setUsername($configuration['username'])
    ->setPassword($configuration['password']);

$mailer = Swift_Mailer::newInstance($transport);

$message = Swift_Message::newInstance('Scan results')
    ->setFrom(array('no-reply@netinventors.de' => 'File Hash Scanner'))
    ->setTo(array('server@netinventors.de' => 'Net Inventors Server'))
    ->setBody('Logfiles attached.')
    ->attach(Swift_Attachment::fromPath($log));

$result = $mailer->send($message);

?>
