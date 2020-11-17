# GoCardless Direct Debit integration for CiviCRM

**A CiviCRM extension to GoCardless integration to handle UK Direct Debits.**

Please see [documentation pages](https://docs.civicrm.org/gocardless)

This extension is working well for collecting regular weekly, monthly or yearly donations from UK supporters. Using it you can enable supporters to set up direct debits and every month when GoCardless takes payment this updates your CiviCRM contributions automatically. If someone cancels their Direct Debit this also updates your CiviCRM records. It also sends them a bunch of flowers thanking them for their support and asking them to reconsider their cancellation. Ok, it doesn't do that last bit.

[Artful Robot](https://artfulrobot.uk) stitches together open source websites and databases to help campaigns, charities, NGOs and other beautifully-minded people change the world. We specialise in CiviCRM and Drupal.

Other things to note

- Daily recurring is not supported by GoCardless, so you should not enable this option when configuring your forms. If you do users will get an error message: "Error Sorry, we are unable to set up your Direct Debit. Please call us."

- Taking one offs is [not supported/implemented yet](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/12).

- Membership organisations should be aware that there isn't currently a way to change subscriptions in bulk - this may cause an issue if you need to increase/decrease your membership fee at any point in future - [see issue #87](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/87).

- Generally worth scanning the titles of the [Issue Queue](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/)

- Developers can drive it from a non-CiviCRM form, e.g. if you have a highly custom donate form that does not use CiviCRM's payment pages.

- There are some phpunit tests. You only get these by cloning the repo, not by downloading a release .tgz or .zip. Do not run these on a live database!

- Pull Requests (PR) welcome. Please ensure all existing tests run OK before making a PR :-)

- You can pay me to fix/implement a feature you need [contact me](https://artfulrobot.uk/contact)

- If you use this, consider joining the friendly [chat channel](https://chat.civicrm.org/civicrm/channels/gocardless) for announcements and support.




