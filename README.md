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

Note: if you're running a "test-drive" contribution page you can use GoCardless's test bank account: 20-00-00 55779911


## How to install

### Install the code

This extension requires the GoCardlessPro PHP library. I have not packaged this up in this repo (need to find out best way to do this in CiviCRM) so here's how to install from the \*nix command line. You need [composer](https://getcomposer.org/download/)

    $ cd /path/to/your/extensions/dir
    $ git clone https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless.git
    $ cd uk.artfulrobot.civicrm.gocardless
    $ which composer >/dev/null && composer install || echo You need composer, pls see https://getcomposer.org/download/ Then repeat this command. (i.e. composer install)

### Install the extension and create a payment processor

Install it through the CiviCRM Extensions screen as usual (you may need to click Refresh).

Go to Administer » CiviContribute » Payment Processors then click **Add New**

- Select **GoCardless** from the *Payment Processor Type*
- give it a name.
- Select **GoCardless Direct Debit** from the *Payment Method*
- Add your access tokens (you obvs need a GoCardless account to do this) and make up a secure webhook secret.
- click *Save*.

### Install your webhook at GoCardless

GoCardless has full separation of its test (sandbox) and live account management pages, so you'll do this twice. Be sure to supply the webhook secret appropriate to the test/live environments :-)

The webhook URL is at `/civicrm/gocardless/webhook` for Wordpress this would be
`?page=CiviCRM&q=civicrm/gocardless/webhook`

Note: the webhook will check the key twice; once against the test and once against the live payment processors' webhook secrets. From that information it determines whether it's a test or not.

## Technical notes                                                                                                                                                                                                                             
                                                                                                                                                                                                                                               
GoCardless sends dozens of webhooks and this extension only reacts to the                                                                                                                                                                      
following:                                                                                                                                                                                                                                     
                                                                                                                                                                                                                                               
- payments: confirmed and failed.                                                                                                                                                                                                              
- subscriptions: cancelled and finished.                                                                                                                                                                                                       
                                                                                                                                                                                                                                               
The life-cycle would typically be:                                                                                                                                                                                                             
                                                                                                                                                                                                                                               
1. User interacts with CiviCRM forms to set up regular contribution. In CiviCRM                                                                                                                                                                
   this results in:                                                                                                                                                                                                                            
                                                                                                                                                                                                                                               
     - a **pending** contribution with the reecive date in the future.                                                                                                                                                                         
     - a **pending** recurring contribution with the start date in the future.                                                                                                                                                                 
                                                                                                                                                                                                                                               
                                                                                                                                                                                                                                               
   And at GoCardless this will have set up:
   
     - a **customer**
     - a **mandate**
     - a **subscription** - the ID of this begins with `SB` and is stored in the CiviCRM recurring contribution transaction ID.
     - a lot of scheduled **payments**

2. GoCardless submits the charge for a payment to the customer's bank and eventually (seems to be 3 working days after submission) this is confirmed. It sends a webhook for `resource_type` `payments`, action `confirmed`. At this point the extension will:

     - look up the payment witih GoCardless to obtain its subscription ID.
     - look up the CiviCRM recurring contribution record in CiviCRM from this subscription ID (which is stored in the transaction ID field)
     - find the pending CiviCRM contribution record that belongs to the recurring contribution and update it, setting status to **Completed**, setting the receive date to the **charge date** from GoCardless (n.b. this is earlier than the date this payment confirmed webhook fires) and setting the transaction id to the GoCardless payment ID. It also sets amount to the amount from GoCardless.
     - finally it changes the status on the CiviCRM recurring contribution record to 'In Progress'.
     
3. A month later GoCardless sends another confirmed payment. This time:

     - look up payment, get subscription ID. As before.
     - look up recurring contrib. record from subscription ID. As before.
     - there is no 'pending' contribution now, so a new Completed one is created, copying details from the recurring contribution record.
     
4. The Direct Debit ends with either cancelled or completed. Cancellations can be that the *subscription* is cancelled, or the *mandate*. The latter would affect all subscriptions. Probably other things, too, e.g. delete customer. GoCardless will cancel all pending payments and inform CiviCRM via webhook. GoCardless will then cancel the subscription and inform CiviCRM by webhook. Each of these updates the status of the contribution (payment) or recurring contribution (subscription) records.



