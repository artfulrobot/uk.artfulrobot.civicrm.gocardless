# Get a GoCardless 'sandbox' account

A sandbox account works just like a live account, except no actual money
goes anywhere. It's very useful for testing.

[Register a sandbox account at GoCardless](https://manage-sandbox.gocardless.com/signup)

## Get an access token

From within GoCardless's dashboard you'll need to **Create an access
token**.

Once you're logged in at GoCardless to your sanbox account, go to
**Developers** (menu on left) » **Create** (button top right) » **Access
Token**.

You are presented with a choice between Read or Read/Write. Choose **Read/Write**.

Name it whatever you like, e.g. CiviCRM.

Once you've created an access token a pop-up box will display the token.
**You can never get to this again!** So make sure you copy it and store it
safely somewhere for later use in your CiviCRM payment processor
configuration.

You'll need to come back to the GoCardless control panel later on to set up your webhook.

## Next step

[Install this extension](install.md)
