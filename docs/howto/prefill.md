# Pre-filling fields on the GoCardless hosted page

GoCardless can pre-populate some of the fields (address, phone, email).
This is useful in the cases when you have asked the user for this
information as part of the form you set up in CiviCRM because it saves
them from having to enter it twice.

To use this feature just add the relevant fields to a CiviCRM Profile that you
are including in your contribution (etc) page.

Addresses, emails, phones all take a location type (Primary, Billing, Home,
Work...). If you have used more than one location type this plugin needs to
choose which to send to GoCardless. It picks fields using the following order of
preference:

1. Billing
2. Primary
3. anything
