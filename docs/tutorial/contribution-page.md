# Create a Contribution Page using GoCardless

Setting up a contribution page is a big topic because there are so many
options, so this tutorial is going to cover the minimal setup required to
start taking regular monthly donations. See the User Guide for more details - [Creating Contribution Pages](https://docs.civicrm.org/user/en/latest/contributions/online-contributions/).

Go to **Contributions » New Contribution Page**.

- Titles: as you like.

- Financial Type ID: Donation.

- Confirmation page: Let’s choose NO because the donor has to complete
  a form on GoCardless's site anyway, so one more step doesn't seem to add
  much use.

- Is Online Contribution Page Active? Yes.

Click **Continue**. We then get the Amounts tab:

- Execute real-time monetary transactions: Yes.

- Currency: GBP

- Payment Processor: ✔ GoCardless Direct Debit

- Pay later: no

- Contribution Amounts section enabled: ✔ 

- Price set: no

- Recurring Contributions: ✔ Yes.

   - Supported Recurring Units: month only
   - Support recurring intervals: no
   - Offer installments: no (the subscription goes on until they cancel.)

- Pledges: no

- Contribution amounts: up to you.

Then click **Save and Done**

!!! note
    GoCardless is your friend if you want to take recurring contributions.
    If you want to take one-offs, it's not your friend; it won't work and
    it will cause a lot of confusion/frustration.

## Make a contribution

You should now be on the list of Contribution Pages. (If not, find it
under **Contributions » Manage Contribution Pages**)

Click the **Links** link next to your new contribution page and click
**Test-drive**.

- Select/enter the amount you want to give.

- check the box that says **I want to contribute this every month**

- enter your email address, click submit.

!!! warning

    Remember earlier we said GoCardless doesn't do one-offs? Well this is
    important here because if a user does not check the "I want to
    contribute every month" option then it will simply crash(!). You can 
    force this option on the [GoCardless Settings](../reference/settings.md#force-recurring) page.

You should now be on GoCardless' website, seeing one of their forms.

- Country: UK

- Name: yours, or a test name

- Sort code and Account number: these [must be special test
  ones](https://developer.gocardless.com/getting-started/developer-tools/test-bank-details/).
  We'll use sort code:`200000` and Account number: `55779911`

- Address: yours will do.

- Email: should be pre-filled for you.

- Click **Set up direct debit**.

On the next page you’re asked to confirm. Then finally you should get
a rather underwhelming summary page from CiviCRM saying:

> Your contribution has been submitted to GoCardless Direct Debit for processing.

## See the contribution in CiviCRM

Visit the record of the contact you signed up. Don't panic that the
Contributions tab doesn't have a number bubble by it; test contributions
aren't counted. Click that tab and you should now see your new
contribution, in **Pending (Incomplete Transaction)** status. Its date
will be in the future by about a week, because that's how long it takes to
set a mandate up and how long we're required to give notice.

Click the **Recurring Contributions** tab and you should see your
subscription listed with the status **In Progress**. (If it's not saying
that status, something is wrong.)

The Contribution record will stay pending until such time that the money
has been taken from the account holder successfully. (It's a little more
complex than that, but for now, that will do.)

## See the contribution in GoCardless

Log in to your sandbox account at GoCardless. Click **Customers** and you
should see your test customer listed. Click that record and you should see
their "Subscription" and below that their "Payments" list. These details
should mirror what you saw in CiviCRM. The Payment shows with an amber dot
because it's not successful yet.

## Check the webhooks (if you're interested/want to be sure)

Now click **Developer** on the left side menu. At the bottom you should
see a *Webhooks* heading with an item showing with a green dot and `204 No
Content` and the webhook URL you supplied earlier.

!!! note
    If you see a green dot with a `200` code, something *might* be wrong.

If so, you're all set, well done!

## Like technical details and want to carry on?

At this point, GoCardless has not sent CiviCRM anything we care about.
It's sent us notifications that a mandate was created; that a subscription
was created; that a payment was created and that a payment was created as
part of a subscription. If you have access to CiviCRM's ConfigAndLog
directory, there will be a log file in there with GoCardless in its name,
which will include an "Ignored unimplemented webhook" line for each of
those events. This is all good. We don't need to know about those things
because we already know.

If you're geeky you might like to [continue the
tutorial](extra-marks.md) which will simulate the payment being
made and updating CiviCRM; and cancelling the subscription.
