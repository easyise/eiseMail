<?php
header('Content-Type: text/plain');

for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);

include_once('inc_eisemail.php');

$sender  = new eiseMail(array(
			'subjPrefix'=>'[eiseMail demo]'
			, 'host' => "smtp.gmail.com"
            , 'port' => 587 //465
            , 'login' => 'youraccount@yoursmtpserver.com'
            , 'password' => 'yourpassword'
			, 'debug' => true // if set to TRUE send() method outputs all negotiations to std output as plain text
			, 'tls' => true // if TRUE, then TLS encryption applied
));

$msg = array('mail_from'=> '"Ilya Eliseev" <easyise@gmail.com>'
            , 'rcpt_to' => '"Also Ilya Eliseev" <ie@e-ise.com>'
            , 'Subject' => 'Message 1'
            , 'Text' => 'Hello there'
            );
$sender->addMessage($msg);

$msg = array('mail_from'=> '"Again Ilya Eliseev" <easyise@gmail.com>'
            , 'rcpt_to' => 'error should occur'
            , 'Subject' => 'Message with error'
            , 'Text' => 'You should not receive this mail'
            );
$sender->addMessage($msg);

$msg = array('mail_from'=> '"Ilya Eliseev" <easyise@gmail.com>'
            , 'rcpt_to' => '"Also Ilya Eliseev" <ie@e-ise.com>'
            , 'Subject' => 'Message with attachments'
            , 'Text' => 'Hello there'
            , 'Attachments' => array(
            	array ('filename'=>'file1.txt'
	            	, 'content'=>"Hello\r\nfrom the text file"
	            	, 'Content-Type'=>'text/plain')
            	, array ('filename'=>'file2.txt'
	            	, 'content'=>"Hello\r\nfrom the text file again"
	            	, 'Content-Type'=>'text/plain')
            	)
            , 'Bottom' => '##bottom_to_be_added##'
            , 'bottom_to_be_added' => "\r\n\r\nThis is the bottom!"
            );
$sender->addMessage($msg);

$msg = array('mail_from'=> '"drakon" <easyise@yandex.ru>'
            , 'rcpt_to' => '"me" <ie@e-ise.com>'
            , 'Subject' => 'Message with 2 kinds of attachments'
            , 'Text' => 'Hello there'
            , 'Attachments' => array(
            	array ('filename'=>'file1.txt'
	            	, 'content'=>"Hello\r\nfrom the text file"
	            	, 'Content-Type'=>'text/plain')
            	, array ('filename'=>'file2.txt'
	            	, 'content'=>"Hello\r\nfrom the text file again"
	            	, 'Content-Type'=>'text/plain')
            	, array ('filename'=>'welcome_to_the_internet.jpg'
	            	, 'content'=>file_get_contents('welcome_to_the_internet.jpg')
	            	, 'Content-Type'=>'image/jpeg')

            	)
            );
$sender->addMessage($msg);

try {
    $arrMessages = $sender->send();
} catch (eiseMailException $e){
    $strError = $e->getMessage();
    $arrMessages = $e->getMessages();
    echo 'Message send global failure: '.$strError."\r\n";
}


foreach($arrMessages as $msg){
    if($msg['send_time']){
    	echo 'Message '.$msg['Subject'].' sent at '.date('d.m.Y H:i:s', $msg['send_time'])."\r\n";
    } else {
    	echo 'Message '.$msg['Subject'].' NOT sent because of error '.($msg['error'] ? $msg['error'] : $strError)."\r\n";
    }
    
}


?>