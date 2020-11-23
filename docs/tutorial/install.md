# Install this extension

!!! warning
    Different versions of CiviCRM require different versions of this GoCardless
    extension. This assumes that your CiviCRM is up-to-date.


This extension can be installed from within CiviCRM itself (assuming your
implementation allows that, some do not for security reasons). 

!!! info Alternatives
    You can install manually or from the git repo (developers) but [see notes](../../howto/alternative-installs/)

In your CiviCRM site, go to **Administer » System Settings » Extensions**
then visit the **Add New** tab. You can see the CiviCRM user manual for details: [Installing
extensions](https://docs.civicrm.org/user/en/latest/introduction/extensions/#installing-extensions).

**GoCardless Direct Debit Payment Processor** should be listed there, click the
**Download** link and then confirm that you wish to install it.

## Set up a Payment Processor

Go to **Administer » CiviContribute » Payment Processors** then click **Add New**

- select **GoCardless** from the *Payment Processor Type*

- name: e.g. GoCardless Direct Debit

- title: e.g. Direct Debit (uses GoCardless)

- financial account: choose **GoCardless Account**

- Payment Method: select **GoCardless Direct Debit**

Next follows two "Processor Details" sections. For this you'll need *two
distinct, separate, secure new passwords* that you are not using anywhere else.
Definitely don't use your GoCardless password!

### Processor Details for Live Payments

For now, we don't have details for the Live Payments section, but we must
put something in.

!!! warning
    Do NOT put your test credentials in here. It won't do what you think it
    will. You have been warned.

- API Access Token: enter `not yet`. Once you have a live access token you can
  replace this with that.

- Webhook secret: enter a new, secure password.

- Site URL, API URL, Recurring Payments URL: these should all be left as
  their defaults are.

### Processor Details for Test Payments

- API Access Token: copy and paste the **access token** you got from your
  GoCardless sandbox account earlier. Your access token probably looks
  like `sandbox_xxxxxxxxxxxxxxxxxxxxxxxx`. Note that this is NOT the
  password for your GoCardless account.

- Webhook secret: enter a new, secure password *that is *NOT* the same as the
  one above*.

- Site URL, API URL, Recurring Payments URL: these should all be left as
  their defaults are.


### Save your new Payment Processor

Click *Save*. You should now see your payment processor listed.

## Next step

[Set up your webhooks](webhook.md)
