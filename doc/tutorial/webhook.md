# Set up your webhook

## Figure out your webhook endpoint URL

A "webhook endpoint" is a URL that GoCardless's system will use to tell
your CRM about payments.

!!! note
    A webhook endpoint URL won't do anything if you visit it; it is
    not a web page.

In CiviCRM, visit **Administer » CiviContribute » GoCardless Webhooks**.
This page will show you the URLs that you need.

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

Then click **Create webhook endpoint**

## All set! Now let's test!

@todo link to contribution page


