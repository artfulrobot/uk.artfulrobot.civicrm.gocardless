# Set up your webhook

## Figure out your webhook endpoint URL

A "webhook endpoint" is a URL that GoCardless's system will use to tell
your CRM about payments.

!!! note
    A webhook endpoint URL won't do anything if you visit it; it is
    not a web page.

In CiviCRM, visit **Administer » CiviContribute » GoCardless Settings**.
This page will show you the URLs that you need.

## Fix Contribution Pages problem with non-recurring

While we're on this settings page...

This GoCardless extension cannot handle one-offs; only recurring
contributions are allowed. CiviContribute's pages offer the user
a checkbox asking if they want to make it regular. If they don't check
that, and they're using a GoCardless payment processor, it will crash.

We can fix this by forcing contributions to use recurring payments if
a GoCardless processor is selected, which is what that Force Recurring
checkbox is about. **Check the force recurring checkbox and then click
Save**.

## Set up the webhooks at GoCardless

Login to your GoCardless sandbox account again (it's at
[manage-sandbox.gocardless.com](https://manage-sandbox.gocardless.com/))
- doing this in a separate tab might be helpful.

Click **Developers** from the panel on the left, then click the **Create**
button and choose **Webhook endpoint**.

- Name: whatever you like. "CiviCRM Test" would be reasonable.

- URL: copy and paste the **Test** webhook URL from the page you opened
  above.

- Secret: enter the *webhook secret* that you entered for the **test**
  payment processor.

- Webhook client certificate: leave that un-checked.

Then click the final **Create webhook endpoint** button.

You should now see your webhook listed with a green dot and "enabled" in the
Status column.

!!! note
    You can't setup a webhook to a private URL. e.g. if you have
    a development/staging server that's not publicly available, this is not
    going to work. GoCardless needs to be able to reach the webhook URL.


## All set! Now let's test it...

Next step: [Set up a Contribution Page](/tutorial/contribution-page.md)
