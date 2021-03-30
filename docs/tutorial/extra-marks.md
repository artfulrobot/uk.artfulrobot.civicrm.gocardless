# Tutorial extra

OK, good to have you with us!

GoCardless has a "scenario simulator" on test accounts which is useful for
testing your set-up without having to actually wait a week for a payment
to be made!

We're going to go on to simulate the payment being successful. Then we'll
cancel the mandate.


## Simulate the payment having been made

Log in to GoCardless on your sandbox account. Find your Customer, and
click into their Payment. The URL and somewhere at the top of the page
shows you a payment ID, e.g. `PM001122334455`. Their IDs are very useful
and all begin with letters to let you know their type, e.g. `PM...` is
a payment, `SB...` is a subscription.

**Copy/make a note of the payment ID.**

Go back to the *Customer* page and click on the item that says about Bank
Account. On that page you'll see a *Mandates* section. Click on the
mandate and **copy/note the mandate ID**. Mandate IDs start `MD...`

Then go to **Developers » Simulate a Scenario**.

1. First choose Activate Mandate, and copy in your `MD...` mandate ID and
   click **Simulate scenario**. It should give some sort of confirmation.

2. Now choose **Payment Paid Out**. Enter the payment ID (`PM...`) you
   copied, and again click **Simulate Scenario**.

Now what it *says* is that this will "immediately" simulate these things
having happened. But it's not really immediate; you may have to wait 5-10
mins before anything happens.

## Take a look at your contact in CiviCRM.

You should now see that the Contribution is **Completed**, and Viewing
that contribution should show you its `PM...` ID under **Transaction ID**.

## Now let's cancel it.

You can cancel it from GoCardless's site, external to CiviCRM: this should
set the Recurring Contribution to Cancelled (and if you cancel before the
initial payment is completed, then that would change from Pending
→ Cancelled, too.)

Or you can cancel from within CiviCRM. Find the Recurring Contribution and
click Cancel.

- Give a reason if you like.

- Choose **Yes** to "Send cancellation request to GoCardless" - that's
  important because if you don't then money will still be taken but
  won't/might not be registered! (The *No* option is only there for weird
  admin troubleshooting.)

You can see from the GoCardless website that this has cancelled the
*subscription* belonging to the customer. (It has not cancelled the
customer, nor has it actually cancelled the mandate.)
