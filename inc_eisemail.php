<?php
/**
 *
 * eiseMail library
 *    
 *    This class is designed to send mail messages with specified SMTP server.
 *
 *  It can be used in the following way:
 *    1)  initialize object with connection settings to the SMTP server
 *    2)  Add messages to send queue using addMessage() method (attachments can be added to messages as associative array members, class will handle them in proper way)
 *    3)  Send queue using send() method, it returns message array with timestamps when messages were actually sent.
 *    
 *    send() method uses sockets to connect to SMTP server. It supports host authentication and TLS channel encryption. It pushes the message queue to the server right after connection is established, channel encrypted and authentication succeeded. 
 *    
 *    Class has been tested for proper communication with the following SMTP servers:
 *    -   Postfix/Sendmail
 *    -   Microsoft Exchange
 *    -   Google mail
 *    -   Microsoft Office365
 *    -   Yandex mail
 *    
 *    Actual example can be found in eiseMail_demo.php script attached to this package.
 *    
 *    In case of exception, class methods are throwing eiseMailException objects with mail message queue in its actual state so you can trace what messages were sent and what were not.
 *    PHP version: >5.1
 *    PHP extensions required: OpenSSL
 *
 *   
 *    author: Ilya Eliseev (ie@e-ise.com)
 *    contributors: Dmitry Zakharov (dmitry.zakharov@ru.yusen-logistics.com), Igor Zhuravlev (igor.zhuravlev@ru.yusen-logistics.com)
 *    sponsors: Yusen Logistics Rus LLC, Russia
 *    version: 1.0
 *
 * Developed under GNU General Public License, version 3:
 * http://www.gnu.org/licenses/lgpl.txt
 */



/**
 * This class containes basic functions to handle mails/addresses/flow etc
 */
class eiseMail_base {

const keyEscapePrefix = '##';
const keyEscapeSuffix = '##';
const passSymbolsToShow = 3;

/**
 * This function echoes if 'verbose' configuration flag is on
 *
 * @param string $string - string to echo.
 *
 * @return void
 */
protected function v($string){
    if($this->conf['verbose']){
        echo ($this->conf['verbose']==='htmlspecialchars' ? '<br>'.htmlspecialchars(Date('Y-m-d H:i:s').': '.$string) : "\r\n".Date('Y-m-d H:i:s').': '.trim($string));
        ob_flush();
        flush();
    }
}

public function coverPassword(){

    $nSymsToShow = min(strlen($this->conf['password']), self::passSymbolsToShow);
    $this->conf['passCovered'] = substr($this->conf['password'], 0, $nSymsToShow).str_repeat("*",strlen($this->conf['password'])-$nSymsToShow);
    
}

/**
 * Explodes address list according to RFC2822: 
 * "Name Surname" <mailbox@host.domain>, "Surname, Name" <mailbox1@host.domain>
 * etc to array. Ordinary explode() will not work, because comma (",") can be a part of personal information.
 * E.g. "John Smith, Mr" <john.smith@domain.com>
 * 
 * @param string $addrList Address list
 *
 * @return array of strings with addresses
 * 
 */
static function explodeAddresses($addrList, $defaultDomain = ''){

    $arr = imap_rfc822_parse_adrlist($addrList, $defaultDomain);
    $arrRet = array();

    foreach($arr as $o){
        $arrRet[] = ($o->personal ? '"'.$o->personal.'" ' : '').'<'.$o->mailbox.'@'.$o->host.'>';
    }
    return $arrRet;

}

/**
 * Retrieves exact unique addresses from list matching according to RFC2822: 
 * "Name Surname" <mailbox@host.domain>, "Surname, Name" <mailbox1@host.domain>
 * etc
 * 
 * @param string $addrList Address list
 *
 * @return array of strings with addresses w/o personal info
 * 
 */
static function getAddresses($addrList, $defaultDomain = ''){

    $arr = imap_rfc822_parse_adrlist($addrList, $defaultDomain);
    $arrRet = array();

    foreach($arr as $o){
        $arrRet[] = strtolower($o->mailbox.'@'.$o->host);
    
    }

    return array_unique($arrRet);

}

/**
 * Gets RFC-compliant mail address to be used with 'MAIL FROM:' and 'RCPT TO:' SMPT commands
 * 
 * @param string $addr - mail address list
 *
 * @return false if $addr contains something wrong. Otherwise removes personal information from the address and returns address like '<mailbox@domain.com>'
 */
static function prepareAddressRFC( $addr, $defaultDomain=''){

    $oAddrs = imap_rfc822_parse_adrlist($addr, $defaultDomain);

    if(!$oAddrs)
        return false;

    $oAddr = $oAddrs[0];

    $addr = '<'.$oAddr->mailbox.($oAddr->host ? '@'.$oAddr->host : '').'>';

    return $addr;

}


/**
 * replaces escaped statements (e.g. ##Sender## or ##orderHref##) in $text
 * with values from $arrReplacements array (e.g 'Sender' => 'John Doe', 'orderHref' => 'http://mysite.com/orders/12345')
 *
 * @param string $text original text
 * @param array $arrReplacements is an associative array of keys and replacements. Each occurence of ##$key## will be replaced with corresponding value.
 *
 * @return string corrected text
 */
static function doReplacements($text, $arrReplacements){
    foreach($arrReplacements as $key=>$value){
        if(is_object($value) || is_array($value))
            continue;
        $text = str_replace(self::keyEscapePrefix.$key.self::keyEscapeSuffix, $value, $text);
    }
    $text = preg_replace('/('.preg_quote(self::keyEscapePrefix, '/').'\S+'.preg_quote(self::keyEscapeSuffix).')/i', '', $text);

    return $text;
}

}

/**
 * Class for mail sending and communications via SMTP
 *
 */
class eiseSMTP extends eiseMail_base{

public $arrMessages = array();
static $Boundary = "==Multipart_Boundary_eiseMail";

public static $arrDefaultConfig = Array(

      "Content-Type" => "text/plain" // message body content type
      , 'charset' => "utf-8" // message body charset
      , "host" => "localhost" // SMTP server host name / IP address
      , "port" => "25" // SMTP server port
      , 'tls' => false // flag use TLS channel encryption
      , "login" => ""  // SMPT server login
      , "password" => "" // SMPT server password
      , "localhost" => "localhost" // defines how to introduce yourself to SMTP server with HELO/EHLO SMTP command
      
      , 'Subject' => '' // default subject for message queue 
      , 'Head' => '' // default message body head
      , 'Bottom' => '' // default message bottom 

      , 'flagAddToSentItems' => false // set to true if you need a copy of message to be saved in user Sent Items
      , 'imap_host' => '' // IMAP host address
      , 'imap_login' => '' // (optional) IMAP login. By default it is set by 'login' field. Specify only if it differs from it.
      , 'imap_password' => '' // (optional) IMAP password.

      , 'verbose' => false // when set to TRUE class methods are sending actual conversation data to standard output
      , 'debug' => false // when set to TRUE mail is actually sent to 'rcpt_to_debug' address + verbose
      //, 'mail_from_debug' => 'developer@e-ise.com' // MAIL FROM: to be used when debug is TRUE
      //, 'rcpt_to_debug' => 'mailbox_for_test_messages@e-ise.com' // RCPT TO: to be used when debug is TRUE

  );

function __construct($arrConfig){
    
    $this->conf = array_merge(self::$arrDefaultConfig, $arrConfig);
    $this->coverPassword();
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

    if($this->conf['login']){
        $arr = (array)imap_rfc822_parse_adrlist($msg['mail_from'], '');
        $from = $arr[0];

        if($from->mailbox.'@'.$from->host!=$this->conf['login']){
            $msg['Reply-To'] = $msg['mail_from'];
            $msg['mail_from'] = $this->conf['login'];
        }
    }

    if ($msg['subjPrefix'])
        $msg['Subject'] = $msg['subjPrefix'].($msg['Subject'] ? ' '.$msg['Subject'] : '');

    $msg['From'] = ($msg['From'] ? $msg['From'] : $msg['mail_from']); // if no From, we use mail_from
    $msg['mail_from'] = ($msg['mail_from'] ? $msg['mail_from'] : $msg['From']); // vice-versa

    $msg['mail_from'] = self::prepareAddressRFC($msg['mail_from'], $this->conf['localhost']); // prepare mail_from to be told to the SMTP server

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
        $rcpt = self::prepareAddressRFC( $rcpt ) ;
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

    $this->v('SMTP session started');

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

    foreach($this->arrMessages as $ix=>&$msg){

        if($this->conf['debug']){
            $msg['mail_from'] = ($this->conf['mail_from_debug'] ? $this->conf['mail_from_debug'] : $msg['mail_from']);
            $msg['rcpt_to'] = array( ($this->conf['rcpt_to_debug'] ? $this->conf['rcpt_to_debug'] : $msg['rcpt_to']) );
        }

        if (!$msg['mail_from']){
            $this->v('MAIL FROM is not set for the message '.var_export($msg, true));
            $this->arrMessages[$ix]['error'] = 'MAIL FROM is not set for the message'; continue;
        }
        if (!$msg['rcpt_to']){
            $this->v('RCPT TO is not set for the message '.var_export($msg, true));
            $this->arrMessages[$ix]['error'] = 'RCPT TO is not set for the message'; continue;
        }

        $msg['fullMessage'] = $this->msg2String($msg);        

        if ($this->conf['debug']){
            echo $msg['fullMessage'];
        }

        if($this->conf['flagAddToSentItems'] && $this->conf['imap_host']){
            $imap = new eiseIMAP(array(
                        'host' => $this->conf['imap_host']
                        , 'login' => $this->conf['imap_login'] ? $this->conf['imap_login'] : $this->conf['login'] 
                        , 'password' => $this->conf['imap_password'] ? $this->conf['imap_password'] : $this->conf['password']
                        , 'verbose' => $this->conf['verbose']
                        , 'mailbox_name' => 'Sent Items'
                        ));

            $imap->connect();
        }

        try {
            $size_msg=strlen($msg['fullMessage']); 
            $strMailFrom = "MAIL FROM:".$msg['mail_from']." SIZE={$size_msg}\r\n";
            $this->say( $strMailFrom, array(250) );

            foreach($msg['rcpt_to'] as $rcpt){
                $strRcptTo = "RCPT TO:".$rcpt."\r\n";
                $this->say($strRcptTo, array(250));
            }
            
            $this->say( "DATA\r\n", array(354));
            
            $this->say( $msg['fullMessage']."\r\n.\r\n", array(250));
            
            $this->arrMessages[$ix]['send_time'] = mktime();

            if($this->conf['flagAddToSentItems'] && $this->conf['imap_host']){

                $imap->save($msg['fullMessage']);

            }

        } catch(eiseMailException $e){
            $this->arrMessages[$ix]['error'] = $e->getMessage();
        }

        $strReset = "RSET\r\n";
        $this->say( $strReset, array(250));

    }

    $strQuit = "QUIT\r\n";
    $this->say( $strQuit, array(221) );
    
    fclose($this->connect);

    $this->v('SMTP session complete');

    return $this->arrMessages;

}

/**
 * This function returns source of a partiqular message in queue, its number (index) is specified by $ixToGet parameter. If this parameter is omitted, first message source is returned. This method uses msg2String private method.
 *
 * @param $ixToGet integer Message number (index) in queue.
 *
 * @return string Message source.
 */
public function getMessageSource($ixToGet = null){

    foreach($this->arrMessages as $ix=>&$msg){
        if($ixToGet!==null){
            if($ix===$ixToGet)
                return $this->msg2String($msg);
                    
        } else 
            return $this->msg2String($msg);
    }

}

/**
 * This function prepares message for sending: it converts it to string with headers and message parts
 *
 * @param array $msg message array
 *  
 * @return string Ready-to-send message data after SMTP DATA command.
 */
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

            switch ($att['Content-Type']) {
                case 'message/rfc822':
                    $strAttachment = $att['content'];
                    $encoding = 'binary';
                    break;
                
                default:
                    $strAttachment = chunk_split ( base64_encode($att['content']) );
                    $encoding = 'base64';
                    break;
            }
            

            $strMessage .= "--".self::$Boundary."\r\nContent-Type: ".$att['Content-Type']."; name=\"".$att['filename']."\"\r\n".
                        "Content-Transfer-Encoding: {$encoding}\r\n".
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

/**
 * This function transmits data to SMTP server
 *
 * @param string $words - data to transmit
 * @param array $arrExpectedReplyCode - array of expected reply codes from the server.
 * 
 * @return void
 */
private function say($words, $arrExpectedReplyCode=array()){
    $this->v($words);
    fputs($this->connect, $words);
    $this->lastTransmission = $words;
    $this->listen($arrExpectedReplyCode);
}

/**
 * This function listens for reply from SMTP server
 *
 * @param array $arrExpectedReplyCode - array of expected reply codes from the server.
 * 
 * @return string $data - string with SMTP reply to last transmission
 */
private function listen($arrExpectedReplyCode=array()){
    $data="";
    while($str = fgets($this->connect,515)){
        $data .= $str;
        if(substr($str,3,1) == " ") { break; }
    }
    $this->v("> {$data}");

    $this->isItOk($data, $arrExpectedReplyCode);

    return $data;
}

/**
 * This function analyze reply from SMTP server and, in case of unexpected reply, it throws the exception
 *
 * @param string $rcv - server reply
 * @param array $arrExpectedReplyCode - array of expected reply codes from the server.
 * 
 * @return void
 */
private function isItOk($rcv, $arrExpectedReplyCode){

    if(count($arrExpectedReplyCode)==0){
        return;
    }

    preg_match("/^([0-9]{3})/", $rcv, $arr);
    $code = (int)$arr[1];

    if (!in_array($code, $arrExpectedReplyCode)){
        throw new eiseMailException("Bad response: ".$rcv." $code", $this->arrMessages);
    }

}


}

/**
 * Class to handle messages from IMAP/POP3 server
 *
 * Uses PHP IMAP extension function
 * @link http://php.net/manual/en/ref.imap.php 
 *
 */
class eiseIMAP extends eiseMail_base{


/** 
 * Default configuration array 
 * 
 * array $arrDefaultConfig {
 *   
 * }
 */
public static $arrDefaultConfig = Array(

      "host" => "localhost" // IMAP server host name / IP address
      , "port" => "993" // IMAP server port
      , 'flags' => '/imap/ssl' // flags according to imap_open() PHP function
      , 'mailbox_name' => 'INBOX' // flags according to imap_open() PHP function
      , "login" => ""  // SMPT server login
      , "password" => "" // SMPT server password
      , 'imap_open_options' => 0 // options for imap_open()
      , 'imap_open_n_retries' => 1 // attempt number for imap_open()
      , 'imap_open_params' => array() // parameters array for imap_open()

      , 'search_criteria' => 'NEW' // search criteria for imap_search() function
      , 'max_messages' => 10 // maximum messages

      , 'set_flags_on_scan' => '\Seen'
      , 'set_flags_on_handle' => '\Answered'

      , 'flagGetMessageSource' => false
      
      , 'verbose' => true // when set to TRUE class methods are sending actual conversation data to standard output
      , 'debug' => false // when set to TRUE mail is actually sent to 'rcpt_to_debug' address + verbose

);


/**
 * Object constructor. Receives configuration array as parameter and merges it with the default one.
 *
 * @param array $arrConfig {
 *      Mandatory. Configuration array.
 *      
 * }
 * @see eiseImap::$strDefaultConfig
 */
function __construct($arrConfig){
    
    $this->conf = array_merge(self::$arrDefaultConfig, $arrConfig);

    $this->coverPassword();
    
}

public function connect(){

    $this->v('Starting IMAP Session...');

    if(!$this->conf['host'] 
     || !$this->conf['login'] 
     || !$this->conf['password'] 
     ) 
        throw new eiseMailException('Host/credentials are not specified.');


    $this->conn_str = '{'
        .$this->conf['host']
        .($this->conf['port'] ? ':'.$this->conf['port'] : '')
        .($this->conf['flags'] 
            ? (strpos($this->conf['flags'], '/')===0 ? '' : '/').$this->conf['flags']
            : '')
        .'}'
        .($this->conf['mailbox_name'] ? $this->conf['mailbox_name'] : '');


    /** try to connect */
    $this->v("Trying to connect to server with the following params:\r\n".
        var_export(array(
                    $this->conn_str
                    , $this->conf['login']
                    , $this->conf['passCovered']
                    , $this->conf['imap_open_options']
                    , ($this->conf['imap_open_n_retries'] ? $this->conf['imap_open_n_retries'] :  self::$arrDefaultConfig['imap_open_n_retries'])
                    , (!empty($this->conf['imap_open_params']) ? $this->conf['imap_open_params'] :  self::$arrDefaultConfig['imap_open_params'])
                )
            , true)
        );

    if (version_compare(PHP_VERSION, '5.3.2', '<')){ // if PHP version is lower than 5.3.2, we omit parameter 6
        $this->mailbox = @imap_open($this->conn_str
            , $this->conf['login']
            , $this->conf['password']
            , $this->conf['imap_open_options']
            , ($this->conf['imap_open_n_retries'] ? $this->conf['imap_open_n_retries'] :  self::$arrDefaultConfig['imap_open_n_retries'])
            );
    } else {
        $this->mailbox = @imap_open($this->conn_str
            , $this->conf['login']
            , $this->conf['password']
            , $this->conf['imap_open_options']
            , ($this->conf['imap_open_n_retries'] ? $this->conf['imap_open_n_retries'] :  self::$arrDefaultConfig['imap_open_n_retries'])
            , (!empty($this->conf['imap_open_params']) ? $this->conf['imap_open_params'] :  self::$arrDefaultConfig['imap_open_params'])
            );
    }
    

    if(!$this->mailbox) {
        
        $errMsg = "Cannot connect to server {$this->conn_str}, IMAP says: ". imap_last_error();
        $e = imap_errors();
        $this->v('ERROR: '.$errMsg."\r\nAll IMAP errors:\r\n".var_export($e, true));
        
        throw new eiseMailException($errMsg);

    }

    return $this->mailbox;

}

/**
 * Retrieves messages from the remote server as an associative array.
 *
 * @return array $arrMessages {
 *        Message array in the same manner as source for eiseSMTP function.
 * string From - sender, encoded to UTF-8
 * string To - addressee, encoded to UTF-8
 * string CC - CC field, encoded to UTF-8
 * string Subject - Subject field, encoded to UTF-8
 * object overview - first object from return value of imap_fetch_overview() function
 * string source - message source
 * array Attachments {
 *   Array of attachments, in the same manner as for eiseSMTP function
 *        string filename - filename, encoded to UTF-8
 *        binary content - binary content of the attached file
 *        Content-Type - content MIME type   
 *    }
 * }
 */
public function receive(){

    $arrMessages = array();

    $this->connect();
        
    /** Fetch mails accoring to criteria */
    $searchCriteria = ($this->conf['search_criteria'] ? $this->conf['search_criteria'] : self::$arrDefaultConfig['search_criteria']);
    $this->v("Searching mailbox by criteria \"{$searchCriteria}\"...");

    $emails = imap_search($this->mailbox, ($this->conf['search_criteria'] ? $this->conf['search_criteria'] : self::$arrDefaultConfig['search_criteria']) );
    
    $this->v( "Messages found: ".($emails ? count($emails): 0) );

    /* if any emails found, iterate through each email */
    if($emails) {
        
        $count = 1;
        
        /* put the newest emails on top */
        rsort($emails);
     
        /* for every email... */
        foreach($emails as $email_number) {

            /** $this->msg array to be returned */
            $this->msg = array();
            
            /** if we'd like to set some flags, we do it */
            if($this->conf['set_flags_on_scan']){
                $this->v('Setting flags \''.$this->conf['set_flags_on_scan']."' to {$email_number}");
                $status = imap_setflag_full($this->mailbox, $email_number, $this->conf['set_flags_on_scan']);
            }

            /** Get information specific to this email */
            //$overview = imap_fetch_overview($this->mailbox,$email_number);
            $overview = imap_rfc822_parse_headers(imap_fetchheader($this->mailbox,$email_number));
            
            /** Convert headers to utf8 */
            $ovrv = $this->mailOverviewUTF8($overview);

            $ovrv->msgno = $email_number;

            
             /** Run check of mail overview, if it returns false, we go to next message */
            if (!$this->checkMailOverview($ovrv))
                continue;

            $this->msg['msgno'] = $ovrv->msgno;
            $this->msg['From'] = $ovrv->from_utf8;
            $this->msg['To'] = $ovrv->to_utf8;
            $this->msg['Date'] = $ovrv->date;
            $this->msg['Subject'] = $ovrv->subject_utf8;
            $this->msg['overview'] = $ovrv;

            /** If flagGetMessageSource is set, we obtain whole message from the server */
            if($this->conf['flagGetMessageSource']){
                $this->msg['source'] = imap_fetchheader($this->mailbox, $email_number) . imap_body($this->mailbox, $email_number, FT_PEEK);
                $this->v('Message source obtained - size: '.strlen($this->msg['source']).' bytes');
            }

            /** if flagDontFetchMessageStructure is set, we omit this part of message handling */
            if(!$this->conf['flagDontFetchMessageStructure']){
                
                /** Getting message structure */
                $structure = imap_fetchstructure($this->mailbox, $email_number);

                $this->v("Message {$email_number} type: {$structure->type}, subtype: {$structure->subtype} contains parts: ".count($structure->parts) );
                
                /** Collect all attachments recursively from message root */
                $this->msg['Attachments'] = array();

                $this->fetch_file($structure, 0);

                $this->v( "Message {$email_number} attachments picked: ".count($this->msg['Attachments']) );

            }

            

            /** Call message handler */
            if( $this->handleMessage() ){
                if($this->conf['set_flags_on_handle']){
                    $this->v('Setting flags \''.$this->conf['set_flags_on_handle']."' to {$email_number}");
                    $status = imap_setflag_full($this->mailbox, $email_number, $this->conf['set_flags_on_handle']);
                }
            }

            if(!$this->conf['flagDontFetchMessageStructure']){
                /** Unlink saved attachments */
                foreach($this->msg['Attachments'] as $att){
                    if($att['temp_filename'])
                        @unlink($att['temp_filename']);
                }
            }

            $arrMessages[] = $this->msg;

            if($count++ >= $this->conf['max_messages']) break;
            
        }
     
    } 
     
    /** close the connection */
    imap_close($this->mailbox);

    $this->v('IMAP Session complete');
    
    return ($arrMessages);

}

/** 
 * Function recursively walks through all $part object and picks up any attachment.
 * This object is returned by imap_fetchstructure() PHP IMAP function. 
 * There can be 3 cases:
 *     1) Message is the file: it has only one part that contains attached file
 *     2) Multipart message: when message contains few parts: some for texts, some for attachments
 *     3) Alternative messages: when message consists of few parts: each one can have few subparts with attachments/texts etc.
 *     This function just walks through all these parts recursively.
 * 
 * @param object $part - the object that represents message structure element
 * @param $partID - id for part to be analyzed for attachments. '0' represents root $part of message, '1', '2', etc - subparts of root part
 *     '2.1', '2.2', etc - subparts of sub-root part, and so on.
 * 
 * @return void
 */
private function fetch_file($part, $partID){

    $arrAtt = array();

    $is_attachment = false;
    $filename = $name = '';

    if($part->ifdparameters) {
        foreach($part->dparameters as $object) 
        {
            if(strtolower($object->attribute) == 'filename') 
            {
                $is_attachment = true;
                $filename = $object->value;
            }
        }
    }

    if($part->ifparameters) {
        foreach($part->parameters as $object) 
        {
            if(strtolower($object->attribute) == 'name') 
            {
                $is_attachment = true;
                $name = $object->value;
            }
        }
    }

    if($is_attachment) {
        
        $att = imap_fetchbody($this->mailbox, $this->msg['msgno'], ($partID ? $partID : 1), FT_PEEK);

        /* 3 = BASE64 encoding */
        if($part->encoding == 3) { 
            $att = base64_decode($att);
        }
        /* 4 = QUOTED-PRINTABLE encoding */
        elseif($part->encoding == 4) { 
            $att = quoted_printable_decode($att);
        }

        $arrAtt['filename'] = ($name!='' 
            ? $name 
            : ($filename!='' ? $filename : time().'.dat')
            );
        $arrAtt['filename'] = imap_utf8($arrAtt['filename']);
        $arrAtt['Content-Type'] = $part->subtype;
        if($this->conf['flagSaveAttachments']){
            $ext = pathinfo($arrAtt['filename'], PATHINFO_EXTENSION);
            $fileName = tempnam(sys_get_temp_dir(), 'eiseIMAP_').($ext!='' ? '.'.$ext : '');
            $arrAtt['content'] = null;
            file_put_contents($fileName, $att);
            $arrAtt['temp_filename'] = $fileName;
            $arrAtt['size'] = strlen($att);

        } else {
            $arrAtt['content'] = $att;
        }

        $this->msg['Attachments'][] = $arrAtt;

    }

    if(isset($part->parts) && count($part->parts)) {
        foreach($part->parts as $i=>$subpart) {

            $this->fetch_file( $subpart, ($partID ?  $partID.'.' : '').($i+1) );

        }
    }

}


/**
 * This function converts mail headers to unicode and updates $overview object that returned 
 * by imap_fetch_overview() PHP function and updates the object with corresponding fields with _utf8 suffix:
 *  $overview->to_uft8
 *  $overview->from_uft8
 *  $overview->subject_utf8
 *
 * @param object $overview - a single overview message object taken from the array returned by imap_fetch_overview() PHP function
 *
 * @return $overview object
 *
 * @link http://php.net/manual/en/function.imap-fetch-overview.php
 */
public function mailOverviewUTF8($overview){

    $overview->to_utf8 = iconv_mime_decode($overview->toaddress, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    $overview->from_utf8 = iconv_mime_decode($overview->fromaddress, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    $overview->cc_utf8 = iconv_mime_decode($overview->ccaddress, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    $overview->reply_to_utf8 = iconv_mime_decode($overview->reply_toaddress, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    $overview->sender_utf8 = iconv_mime_decode($overview->senderaddress, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    $overview->subject_utf8 = iconv_mime_decode($overview->subject, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

    return $overview;

}


/**
 * Function that allows to filter mail by its overview.
 * If function returns true, mail attachments will be dowloaded also.
 * This is interface function, to be overridden in extending class.
 * By default it returns true, i.e. all messages will be returned with attachments.
 *
 * @param object $overview - a single overview message object taken from the array returned by imap_fetch_overview() PHP function
 *
 * @return variant - When it returns false if message will be skipped. If any other value - message will be completely fetched from the server
 *
 * @link http://php.net/manual/en/function.imap-fetch-overview.php
 *
 */
public function checkMailOverview($overview){
    return true;
}

/**
 * Callback function to handle current messages stored in $this->msg object
 *
 * @return boolean - If it returns true, receive() method sets flags for it specified in $conf['set_flags_on_handle']
 *
 */
public function handleMessage(){
    return false;
}


public function save($strMessage){

    imap_append($this->mailbox, $this->conn_str, $strMessage);

}

}






/**
 * This extension of Exception class should allow us to pass back messages array in its actual state
 */
class eiseMailException extends Exception {

/**
 * Receives user message and $messages array
 *
 * @param $usrMsg {string} text user message
 * @param $arrMessages {array} array of messages that can be set as an attemp to save unsent / unhandled messages in case of exception.
 * 
 * @class eiseMailException
 */
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


/**
 * Backward-compatibility
 * 
 * @extends eiseSMTP
 */
class eiseMail extends eiseSMTP{}
?>