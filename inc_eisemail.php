<?php
/****************************************************************/
/*
eiseMail class
    
    This class is designed to send mail messages with specified SMTP server.

    It can be used in the following way:
    1)  initialize object with connection settings to the SMTP server
    2)  Add messages to send queue using addMessage() method (attachments can be added to messages as associative array members, class will handle them in proper way)
    3)  Send queue using send() method, it returns message array with timestamps when messages were actually sent.
    
    send() method uses sockets to connect to SMTP server. It supports host authentication and TLS channel encryption. It pushes the message queue to the server right after connection is established, channel encrypted and authentication succeeded. 
    
    Class has been tested for proper communication with the following SMTP servers:
    -   Postfix/Sendmail
    -   Microsoft Exchange
    -   Google mail
    -   Microsoft Office365
    -   Yandex mail
    
    Actual example can be found in eiseMail_demo.php script attached to this package.
    
    In case of exception, class methods are throwing eiseMailException objects with mail message queue in its actual state so you can trace what messages were sent and what were not.
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
class eiseMail {

public $arrMessages = array();
static $Boundary = "==Multipart_Boundary_eiseMail";
const keyEscapePrefix = '##';
const keyEscapeSuffix = '##';

function __construct($arrConfig){
    
    $arrDefaultConfig = Array(

          "Content-Type" => "text/plain" // message body content type
          , 'charset' => "utf-8" // message body charset
          , "host" => "localhost" // SMTP server host name / IP address
          , "port" => "25" // SMTP server port
          , 'tls' => false // flag use TLS channel encryption
          , "login" => ""  // SMPT server login
          , "password" => "" // SMPT server password
          , "localhost" => "localhost" // defines how to introduce yourself to SMTP server with HELO/EHLO SMTP command
          
          , 'Subject' => '' // default subject for message queue 
            , 'flagEscapeSubject' => true
          , 'Head' => '' // default message body head
          , 'Bottom' => '' // default message bottom 

          , 'verbose' => false // when set to TRUE class methods are sending actual conversation data to standard output
          , 'debug' => false // when set to TRUE mail is actually sent to 'rcpt_to_debug' address + verbose
          //, 'mail_from_debug' => 'developer@e-ise.com' // MAIL FROM: to be used when debug is TRUE
          //, 'rcpt_to_debug' => 'mailbox_for_test_messages@e-ise.com' // RCPT TO: to be used when debug is TRUE

          );
    
    $this->conf = array_merge($arrDefaultConfig, $arrConfig);
    
}


function addMessage ($msg){

    $msgDefault = array(
        'From' => $this->conf['From']
        , 'mail_from' => $this->conf['mail_from'] // service field, argument to MAIL FROM command
        , 'rcpt_to' => $this->conf['rcpt_to'] // service field, argument to RCPT TO command, could be array
        , 'Content-Type' => $this->conf['Content-Type']
        , 'charset' => $this->conf['charset']
        , 'To' => $this->conf['To']
        , 'Reply-To' => $this->conf['Reply-To']
        , 'CC' => null
        , 'BCC' => null
          , 'Subject' => $this->conf['Subject']
            , 'flagEscapeSubject' => $this->conf['flagEscapeSubject']
          , 'Head' => $this->conf['Head']
          , 'Text' => ''
          , 'Bottom' => $this->conf['Bottom']
        , 'Attachments' => array()
    );

    $msg = array_merge($msgDefault, $msg);

    if ($msg['subjPrefix'])
        $msg['Subject'] = $msg['subjPrefix'].($msg['Subject'] ? ' '.$msg['Subject'] : '');

    $msg['From'] = ($msg['From'] ? $msg['From'] : $msg['mail_from']); // if no From, we use mail_from
    $msg['mail_from'] = ($msg['mail_from'] ? $msg['mail_from'] : $msg['From']); // vice-versa

    $msg['mail_from'] = self::getClearAddress( self::clearAddressFormat($msg['mail_from']) ); // prepare mail_from to be told to the SMTP server

    if(!$msg['rcpt_to']){
        $msg['rcpt_to'] = array_merge(
            (isset($msg['To']) ? self::explodeAddresses($msg['To']) : array())
            , (isset($msg['CC']) ? self::explodeAddresses($msg['CC']) : array())
            , (isset($msg['BCC']) ? self::explodeAddresses($msg['BCC']) : array())
            );
    }

    if(!is_array($msg['rcpt_to'])){
        $msg['rcpt_to'] = array($msg['rcpt_to']);
    }

    foreach($msg['rcpt_to'] as &$rcpt){
        $rcpt = self::getClearAddress( self::clearAddressFormat( $rcpt ) );
    }
    
    $msg['rcpt_to'] = array_unique($msg['rcpt_to']);
    
    $this->arrMessages[] = $msg;

}


function send($arrMsg=null){

    if($arrMsg)
        $this->addMessage($arrMsg);

    if(count($this->arrMessages)==0)
        throw new Exception('Message queue is empty');

    // connect
    $this->connect = fsockopen ($this->conf["host"], $this->conf["port"], $errno, $errstr, 30);

    if (!$this->connect) throw new Exception("Cannot connect to mail server ".$this->conf["host"].':'.$this->conf["port"]);

    $this->listen();
    
    $this->say("EHLO ".$this->conf["localhost"]."\r\n");
    
    // TLSing
    if ($this->conf['tls']){
        $this->say( "STARTTLS\r\n" , array(220));
        stream_socket_enable_crypto($this->connect, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $this->say( "HELO ".$this->conf["localhost"]."\r\n", array(250));
    }

    // auth
    if ($this->conf["login"]) {
        $this->say( "AUTH LOGIN\r\n" , array(334));
        $strLogin = base64_encode($this->conf["login"])."\r\n";
        $this->say( $strLogin , array(334));

        $strPassword = base64_encode($this->conf["password"])."\r\n";
        $this->say( $strPassword, array(235));

    }

    foreach($this->arrMessages as $ix=>$msg){

        if($this->conf['debug']){
            $msg['mail_from'] = ($this->conf['mail_from_debug'] ? $this->conf['mail_from_debug'] : $msg['mail_from']);
            $msg['rcpt_to'] = array( ($this->conf['rcpt_to_debug'] ? $this->conf['rcpt_to_debug'] : $msg['rcpt_to']) );
        }

        if (!$msg['mail_from']){
            $this->arrMessages[$ix]['error'] = 'MAIL FROM is not set for the message'; continue;
        }
        if (!$msg['rcpt_to']){
            $this->arrMessages[$ix]['error'] = 'RCPT TO is not set for the message'; continue;
        }

        $strMessage = $this->msg2String($msg);        

        if ($this->conf['debug']){
            echo $strMessage;
        }


        try {
            $size_msg=strlen($strMessage); 
            $strMailFrom = "MAIL FROM:".$msg['mail_from']." SIZE={$size_msg}\r\n";
            $this->say( $strMailFrom, array(250) );

            foreach($msg['rcpt_to'] as $rcpt){
                $strRcptTo = "RCPT TO:".$rcpt."\r\n";
                $this->say($strRcptTo, array(250));
            }
            
            $this->say( "DATA\r\n", array(354));
            
            $this->say( $strMessage."\r\n.\r\n", array(250));
            
            $this->arrMessages[$ix]['send_time'] = mktime();
        } catch(eiseMailException $e){
            $this->arrMessages[$ix]['error'] = $e->getMessage();
        }

        $strReset = "RSET\r\n";
        $this->say( $strReset, array(250));

    }

    $strQuit = "QUIT\r\n";
    $this->say( $strQuit, array(221) );
    
    fclose($this->connect);

    return $this->arrMessages;

}

private function msg2String($msg){

    $conf = $this->conf;

    $msg['Content-Type'] = ($msg['Content-Type'] ? $msg['Content-Type'] : 'text/plain')
        .($msg['charset'] ? "; charset=".$msg['charset'] : '');
    
    // escaped vars replacements
    $msg['Subject'] = self::doReplacements($msg['Subject'], $msg);        
    $msg['Head'] = self::doReplacements($msg['Head'], $msg);        
    $msg['Text'] = self::doReplacements($msg['Text'], $msg);        
    $msg['Bottom'] = self::doReplacements($msg['Bottom'], $msg);     

    if($msg['charset'] && $msg['flagEscapeSubject']){
        $msg['Subject'] = "=?{$msg['charset']}?B?".base64_encode($msg['Subject'])."?=";
    }   

    $strMessage = '';
    if(is_array($msg['Attachments']))
        foreach ($msg['Attachments'] as $att){

            if (!$att['content'])
                contunue;

            $strAttachment = chunk_split ( base64_encode($att['content']) );

            $strMessage .= "--".self::$Boundary."\r\nContent-Type: ".$att['Content-Type']."; name=\"".$att['filename']."\"\r\n".
                        "Content-Transfer-Encoding: base64\r\n".
                        "Content-Disposition: attachment;\r\n\r\n";

            $strMessage .= $strAttachment."\r\n\r\n";
            
        }

    // if we have attachments
    if ($strMessage) {

        $strMessage = 
            "Content-Type: multipart/mixed;\tboundary=\"".self::$Boundary."\"\r\n\r\n"
            ."--".self::$Boundary."\r\nContent-Type: ".$msg["Content-Type"]."\r\n"
            ."Content-Transfer-Encoding: 8bit\r\n"
            ."Content-Disposition: inline;\r\n\r\n"
            .($msg['Head'] ? $msg['Head']."\r\n\r\n" : '')
            .$msg['Text']."\r\n\r\n"
            .($msg['Bottom'] ? $msg['Bottom']."\r\n\r\n" : '')
            .$strMessage
            //."----".self::$Boundary."--\r\n"
            ;

    } else { // if there're no attachments
        $strMessage = 
            "Content-Type: ".$msg["Content-Type"]."\r\n\r\n"
            .($msg['Head'] ? $msg['Head']."\r\n\r\n" : '')
            .$msg['Text']."\r\n\r\n"
            .($msg['Bottom'] ? $msg['Bottom']."\r\n\r\n" : '');
           
    }
    
    $msg['rcpt_to_string'] = is_array($msg['rcpt_to'] ? implode(', ', $msg['rcpt_to']) : $msg['rcpt_to']);
    
    $strMessage = "Subject: ".$msg['Subject']."\r\n"
        ."From: ".($msg['From'] ? $msg['From'] : $msg['mail_from'])."\r\n"
        ."To: ".($msg['To'] ? $msg['To'] : $msg['rcpt_to_string'])."\r\n"
        .($msg["CC"] ? "CC: {$msg["CC"]}\r\n" : "")
        .($msg["Bcc"] ? "Bcc: {$msg["Bcc"]}\r\n" : "")
        ."X-Sender: ".$msg['mail_from']."\r\n"
        ."Return-Path: ".($msg["Return-Path"]!=""
                ? $msg["Return-Path"] 
                : ($msg["Reply-To"]!=""
                    ? $msg["Reply-To"] 
                    : $msg['mail_from']))."\r\n"
        ."Errors-To: ".($msg["Erorrs-To"]!=""
                ? $msg["Erorrs-To"] 
                : ($msg["Reply-To"]!=""
                    ? $msg["Reply-To"]
                    : $msg['mail_from']))."\r\n"
        .($msg["Reply-To"]!="" ? 'Reply-To: '.$msg["Reply-To"]."\r\n" : '')
        ."X-Mailer: PHP\r\nX-Priority: 3\r\nX-Priority: 3\r\nMIME-Version: 1.0\r\n"
        .$strMessage;

    return $strMessage;

}

private function say($words, $arrExpectedReplyCode=array()){
    if ($this->conf['verbose'] || $this->conf['debug']){
        echo $words;ob_flush();
    }
    fputs($this->connect, $words);
    $this->lastTransmission = $words;
    $this->listen($arrExpectedReplyCode);
}

private function listen($arrExpectedReplyCode=array()){
    $data="";
    while($str = fgets($this->connect,515)){
        $data .= $str;
        if(substr($str,3,1) == " ") { break; }
    }
    if ($this->conf['verbose'] || $this->conf['debug']){
        echo "> {$data}"; ob_flush();
    }

    $this->isItOk($data, $arrExpectedReplyCode);

    return $data;
}

private function isItOk($rcv, $arrExpectedReplyCode){

    if(count($arrExpectedReplyCode)==0){
        return;
    }

    preg_match("/^([0-9]{3})/", $rcv, $arr);
    $code = (int)$arr[1];

    if (!in_array($code, $arrExpectedReplyCode)){
        throw new eiseMailException("Bad response: ".$rcv, $this->arrMessages);
    }

}

/* 
checks email for compliance to "Name Surname" <mailbox@host.domain> format
in case of incompliance adds angular brackets (<>) to mail address and returns it 
*/
static function clearAddressFormat($addr){
    if(!preg_match('/^(.+)\<([^\s\<]+\@[^\s\<]+)\>$/', $addr)){
        $addr = preg_replace('/(([^\<\s\@])+\@([^\s\<])+)/', "<\\1>", $addr);
    }
    return $addr;
}

/*
gets clear address from RFC2822 mail address string:
 "John Doe" <john@doe.com> -> <john@doe.com>
 or, with $flagRemoveAngularBrackets:
 "John Doe" <john@doe.com> -> john@doe.com
*/
static function getClearAddress($addr, $flagRemoveAngularBrackets = false){
    
    $addr = preg_replace("/^(.+)(\<)/", "\\2", $addr );
    
    if($flagRemoveAngularBrackets)
       $addr = preg_replace('/^\<(.+)\>$/', "\\1", $addr);

    return $addr;

}


/*
explodes address list according to RFC2822: 
"Name Surname" <mailbox@host.domain>, "Surname, Name" <mailbox1@host.domain>
etc
*/
static function explodeAddresses($addrList){

    if(preg_match_all('/((\"[^\"]+\")\s+){0,1}([^\s]+\@[^\s\,]+)/', $addrList, $arrMatch))
        return $arrMatch[0];
    else 
        return array($addrList);

}

/*
replaces escaped statements (e.g. ##Sender## or ##orderHref##) in $text
with values from $arrReplacements array (e.g 'Sender' => 'John Doe', 'orderHref' => 'http://mysite.com/orders/12345')
*/
static function doReplacements($text, $arrReplacements){
    foreach($arrReplacements as $key=>$value){
        if(is_object($value) || is_array($value))
            continue;
        $text = str_replace(self::keyEscapePrefix.$key.self::keyEscapeSuffix, $value, $text);
    }
    return $text;
}

}

/*
This extension of Exception class should allow us to pass back messages array in its actual state
*/
class eiseMailException extends Exception {

function __construct($usrMsg, $arrMessages=array()){
    parent::__construct($usrMsg);
    $this->arrMessages = $arrMessages;
}

/* 
This function should be used in 'catch' block after eiseMail::send() activation to obtain eiseMail::arrMessages array in its actual state
*/
function getMessages(){ 
    return $this->arrMessages;
}

}
?>