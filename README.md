#eiseMail

Email send/receive PHP library

Need batch mail sending? Tired of PHP mail() function? Want to save sent mails to "Sent items"? Want to create mail robot? eiseMail is aimed to help with this! Read mail messages and respond from your PHP app!

This library consists of two major classes:
1. [eiseSMTP](https://russysdev.github.io/eiseMail/#eisesmtp) - the class that sends mail using sockets and supports TLS and other required security features. Developed especially to make batch send much easier.
2. [eiseIMAP](https://russysdev.github.io/eiseMail/#eiseimap) - the class that reads mail with IMAP. It's not just a wrapper to native PHP IMAP, it is aimed to obtain message attachments in simpliest possible way.

Both classes are based on [eiseMail_base](https://russysdev.github.io/eiseMail/#eisemail_base) class that contains some handful utilities.

In case of exception, class methods are throwing [eiseMailException](https://russysdev.github.io/eiseMail/#eisemailexception) object with mail message queue in its actual state so you can trace what messages were sent and what were not.  

__PHP version__ required: >5.1

__Documentation__: https://russysdev.github.io/eiseMail/
__Examples__: see eiseMail_demo.php

__License__: GNU Public License <http://opensource.org/licenses/gpl-license.php>  
__Uses__: : OpenSSL, IMAP  
__Version__: : 1.0  
__Author__: : Ilya Eliseev <ie@e-ise.com>, contributors: Dmitry Zakharov, Igor Zhuravlev