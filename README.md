# GoCardless Direct Debit integration for CiviCRM

**A CiviCRM extension to GoCardless integration to handle UK 
Direct Debits.**

A better readme is to come :-)

Meanwhile pre-launch, please see and use the issue queue, especially 
see [#1](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/1) which sets out the urgent project goals. I will be launching 
with basic functionality by mid November.


## How you can help

I'd welcome your input. Please note that this extension is **NOT 
production-ready yet**.

Let me know - see [#7](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/7)

## How to install

This extension requires the GoCardlessPro PHP library. I have not packaged this up in this repo (need to find out best way to do this in CiviCRM) so here's how to install from the \*nix command line.

    $ cd /path/to/your/extensions/dir
    $ git clone https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless.git
    $ cd uk.artfulrobot.civicrm.gocardless
    $ which composer >/dev/null && composer install || echo You need composer, pls see https://getcomposer.org/download/
    
And then install it through the CiviCRM Extensions screen as usual (you may need to click Refresh).


