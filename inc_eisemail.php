<?php

class eiseMail {

public $arrMessages = array();
static $Boundary = "==Multipart_Boundary_eiseMail";

function __construct($arrConfig){
    $arrDefaultConfig = Array(
          "Content-Type" => "text/plain"
          , 'charset' => "utf-8"
          , "host" => "localhost"
          , "port" => "25"
          , "login" => ""
          , "password" => ""
          , "localhost" => "localhost"
          , 'tls' => false
          , 'subjPrefix' => ''
          , 'debug' => true
          );
    
    $this->conf = array_merge($arrDefaultConfig, $arrConfig);
    
    $this->debug = $this->conf['debug'];
    
}


function addMessage ($arrMsg){

    $msgDefault = array(
        'mail_from' => $this->conf['mail_from']
        , 'rcpt_to' => $this->conf['rcpt_to']
        , 'Content-Type' => $this->conf['Content-Type']
        , 'charset' => $this->conf['charset']
        , 'subjPrefix' => $this->conf['subjPrefix']
        , 'Attachments' => array()
    );

    $arrMsg = array_merge($msgDefault, $arrMsg);

    if ($arrMsg['subjPrefix'])
        $arrMsg['Subject'] = $arrMsg['subjPrefix'].($arrMsg['Subject'] ? ' '.$arrMsg['Subject'] : '');

    $this->arrMessages[] = $arrMsg;

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

        if($this->debug){
            $msg['mail_from'] = ($this->conf['mail_from_debug'] ? $this->conf['mail_from_debug'] : $msg['mail_from']);
            $msg['rcpt_to'] = ($this->conf['rcpt_to_debug'] ? $this->conf['rcpt_to_debug'] : $msg['rcpt_to']);
        }

        if (!$msg['mail_from']){
            $this->arrMessages[$ix]['error'] = 'MAIL FROM is not set for the message'; continue;
        }
        if (!$msg['rcpt_to']){
            $this->arrMessages[$ix]['error'] = 'RCPT TO is not set for the message'; continue;
        }

        $strMessage = $this->msg2String($msg);        

        if ($this->debug){
            echo $strMessage;
        }

        $msg['mail_from'] = self::checkAddressFormat($msg['mail_from']);
        $msg['rcpt_to'] = self::checkAddressFormat($msg['rcpt_to']);

        try {
            $size_msg=strlen($strMessage); 
            $strMailFrom = "MAIL FROM:".preg_replace("/^(.+)(\<)/", "\\2", $msg['mail_from'])." SIZE={$size_msg}\r\n";
            $this->say( $strMailFrom, array(250) );

            $strRcptTo = "RCPT TO:".preg_replace("/^(.+)(\<)/", "\\2", $msg['rcpt_to'])."\r\n";
            $this->say($strRcptTo, array(250));
            
            $this->say( "DATA\r\n", array(354));
            
            $this->say( $strMessage."\r\n.\r\n", array(250));
            
            $strReset = "RSET\r\n";
            $this->say( $strReset, array(250));

            $this->arrMessages[$ix]['send_time'] = mktime();
        } catch(eiseMailException $e){
            $this->arrMessages[$ix]['error'] = $e->getMessage();
        }

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
            .$msg['Text']."\r\n\r\n"
            .$strMessage
            //."----".self::$Boundary."--\r\n"
            ;

    } else { // if there're no attachments
        $strMessage = 
            "Content-Type: ".$msg["Content-Type"]."\r\n\r\n"
            .$msg['Text']."\r\n\r\n";
           
    }
    
    
    $strMessage = "Subject: ".$msg['Subject']."\r\n"
        ."From: ".($msg['From'] ? $msg['From'] : $msg['mail_from'])."\r\n"
        ."To: ".$msg['rcpt_to']."\r\n"
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
        ."Reply-To: ".($msg["Reply-To"]!="" ? $msg["Reply-To"] : $msg['mail_from'])."\r\n"
        ."X-Mailer: PHP\r\nX-Priority: 3\r\nX-Priority: 3\r\nMIME-Version: 1.0\r\n"
        .$strMessage;

    return $strMessage;

}

private function say($words, $arrExpectedReplyCode=array()){
    if ($this->debug){
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
    if ($this->debug)
        echo "> {$data}"; ob_flush();
    
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

static function checkAddressFormat($addr){
    if(!preg_match('/^(.+)\<([^\s\<]+\@[^\s\<]+)\>$/', $addr)){
        $addr = preg_replace('/(([^\<\s\@])+\@([^\s\<])+)/', "<\\1>", $addr);
    }
    return $addr;
}

}

// this kind of Expetion should allow us to keep messages array in its actual state
class eiseMailException extends Exception {

function __construct($usrMsg, $arrMessages=array()){
    parent::__construct($usrMsg);
    $this->arrMessages = $arrMessages;
}

function getMessages(){ // this function shoulbe used in 'catch' block after eiseMail::send() activation to obtain eiseMail::arrMessages array
    return $this->arrMessages;
}

}
?>