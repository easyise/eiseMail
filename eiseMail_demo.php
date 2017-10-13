<?php
/****************************************************************/
/*
eiseMail class demo
    
    This class is designed to send mail messages with specified SMTP server.

    Class has been tested for proper communication with the following SMTP servers:
    -   Postfix/Sendmail
    -   Microsoft Exchange
    -   Google mail
    -   Microsoft Office365
    -   Yandex mail
    
    PHP version: >5.1
    PHP extensions required: OpenSSL
    
    author: Ilya Eliseev (ie@e-ise.com)
    contributors: Dmitry Zakharov (dmitry.zakharov@ru.yusen-logistics.com), Igor Zhuravlev (igor.zhuravlev@ru.yusen-logistics.com)
    sponsors: Yusen Logistics Rus LLC, Russia
    version: 1.0

     * Developed under GNU General Public License, version 3:
     * http://www.gnu.org/licenses/lgpl.txt
     
**/
/****************************************************************/


header('Content-Type: text/plain');

for ($i = 0; $i < ob_get_level(); $i++) { ob_end_flush(); }
ob_implicit_flush(1);

include_once('inc_eisemail.php');

define('EMAIL_FROM', '"YOUR_NAME" <YOUR_GOOGLE_LOGIN@gmail.com>');
define('EMAIL_TO1', '"YOUR OTHER NAME" <you@other.address>');
define('EMAIL_TO2', '"...AND OTHER NAME" <you@some_other.address>');

/*
Sender object initialization with server credentials
*/
$sender  = new eiseMail(array(
			'subjPrefix'=>'[eiseMail demo]'
			, 'host' => "smtp.gmail.com"
            , 'port' => 587 //465
            , 'login' => trim(eiseMail::prepareAddressRFC(EMAIL_FROM), '<>') // should be the same as From address
            , 'password' => '*********'
            , 'debug' => false // if set to TRUE send() method outputs all negotiations to std output as plain text
			, 'verbose' => true // if set to TRUE send() method outputs all negotiations to std output as plain text
			, 'tls' => true // if TRUE, then TLS encryption applied
));

/*
Simple message with simple body
*/
$msg = array('From'=> EMAIL_FROM
            , 'To' => EMAIL_TO1
            , 'Subject' => 'Test Message'
            , 'Text' => 'Hello there'
            );
$sender->addMessage($msg);

/*
Simple message that should raise error
*/
$msg = array('From'=> EMAIL_FROM

            , 'To' => 'the error should occur'

            , 'Subject' => 'Message with error'
            , 'Text' => 'You should not receive this mail'
            );
$sender->addMessage($msg);

/*
Message with 2 text attachments
*/
$msg = array('From'=> EMAIL_FROM
            , 'To' => EMAIL_TO1
            , 'CC' => EMAIL_TO2
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

/*
Message with 2 kinds of attachment
*/
$msg = array('mail_from'=> EMAIL_FROM
            , 'rcpt_to' => EMAIL_TO1
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