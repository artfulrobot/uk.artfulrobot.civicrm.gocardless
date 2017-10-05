# GoCardless Direct Debit integration for CiviCRM

**A CiviCRM extension to GoCardless integration to handle UK 
Direct Debits.**

This extension is working well for collecting regular donations from UK supporters. Using it you can enable supporters to set up direct debits and every month when GoCardless takes payment this updates your CiviCRM contributions automatically. If someone cancels their Direct Debit this also updates your CiviCRM records. It also sends them a bunch of flowers thanking them for their support and asking them to reconsider their cancellation. Ok, it doesn't do that last bit.

[Artful Robot](https://artfulrobot.uk) stitches together open source websites and databases to help campaigns, charities, NGOs and other beautifully-minded people change the world. We specialise in CiviCRM and Drupal.

Other things to note

- Although "Beta", this has been in production use since November 2016. The usual disclaimers apply :-)

- Taking one offs is [not supported/implemented yet](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/12).

- Generally worth scanning the titles of the [Issue Queue](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/)

- Developers can drive it from a non-CiviCRM form, e.g. if you have a highly custom donate form that does not use CiviCRM's payment pages.

- There are some phpunit tests. You only get these by cloning the repo, not by downloading a release .tgz or .zip. Do not run these on a live database!

- Pull Requests (PR) welcome. Please ensure all existing tests run OK before making a PR :-)

- You can pay me to fix/implement a feature you need [contact me](https://artfulrobot.uk/contact)

- If you use this, it may help us all if you drop a comment on [Issue 20](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/20)


## How to install

Choose option 1a (everyone) or 1b (developers only), then proceed with step 2.

### 1a. Install it the Simple way

Visit the [Releases page](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/releases) and download the code from there. Unzip it in your extensions directory, then follow instructions for [step 2 below](#createpp).

### 1b. Install it the Difficult way (developers)

This extension requires the GoCardlessPro PHP library. Here's how to install from the \*nix command line. You need [composer](https://getcomposer.org/download/)

    $ cd /path/to/your/extensions/dir
    $ git clone https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless.git
    $ cd uk.artfulrobot.civicrm.gocardless
    $ which composer >/dev/null && composer install || echo You need composer, pls see https://getcomposer.org/download/ Then repeat this command. (i.e. composer install)

That should then bring in the GoCardlessPro dependency.

### <a name="createpp" id="createpp"></a> 2. Install the extension and create a payment processor

Install it through the CiviCRM Extensions screen as usual (you may need to click Refresh).

Go to Administer » CiviContribute » Payment Processors then click **Add New**

- Select **GoCardless** from the *Payment Processor Type*
- give it a name.
- Select **GoCardless Direct Debit** from the *Payment Method*
- Add your access tokens (you obvs need a GoCardless account to do this) and make up unique secure webhook secrets.
- click *Save*.

**Note: for testing purposes you may put your test/sandbox credentials in the Live fields, but you must use CiviCRM's 'test drive' mode for trying payments; live mode will NOT work with test credentials since they are authenticated against different GoCardless API end points.** So your live testing will need to be with real-world live data.

### 3. Install your webhook at GoCardless

GoCardless has full separation of its test (sandbox) and live account management pages, so **you'll do this twice**. Be sure to supply the webhook secret appropriate to the test/live environments - you **must** choose a different secret for live/test.

The webhook URL is at:

- Drupal: `/civicrm/gocardless/webhook` 
- Wordpress `/?page=CiviCRM&q=civicrm/gocardless/webhook`
- Joomla: `/index.php?option=com_civicrm&task=civicrm/gocardless/webhook`

Note: the webhook will check the key twice; once against the test and once against the live payment processors' webhook secrets. From that information it determines whether it's a test or not. That's one reason you need different secrets.

### 4. Use it and test it!

Create a contribution page and set up a regular donation using the "test-drive" page. Check things at CiviCRM's end and at GoCardless' end. Note that GoCardless keeps a log of whether webhooks were succesful and gives you the chance to resubmit them, too, if I remember correctly.

Note: if you're running a "test-drive" contribution page you can use GoCardless's test bank account: `20-00-00` `55779911`

Having set up a Direct Debit you should see that in the Contributions tab for your contact's record on CiviCRM, showing as a recurring payment, and also a pending contribution record. The date will be about a week in the future. Check your database several days after that date (GoCardless only knows something's been successful after the time for problems to be raised has expired, which is several working days) and the contribution should have been completed. Check your record next month and there should be another contribution automatically created.


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



