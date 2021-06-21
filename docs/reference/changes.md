# Change log

## 1.10.2

- Mostly a clean-up of code around handling completed payments; strip out work
  that is now done by core CiviCRM, and for the bits we still need to override
  (e.g. setting Contribution `receive_date` to the charge date of the completed
  payment) we do this with SQL not the Contribution.create as this sometimes
  seems to do too much resulting in errors like `DB Error: already exists` when
  Civi (5.31 at least) for some reason tries to add a duplicate row in the
  activity-contact table.

- Added [`hook_civicrm_alterPaymentProcessorParams`](https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_alterPaymentProcessorParams/)
  support to alter the params send to GoCardless for creating subscription.

- Introduces a hook to alter or override the
  `completeRedirectFlowWithGoCardless`. See `CRM_GoCardless_Hook`

- Implement `doPayment` instead of the old, deprecated `doTransferPayment`. See
  https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/114

- Fix trouble with receipt policy (duplicate receipts or none!) - see
  https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/107

- Fix 'source' not populating in subsequent contributions. - see
  https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/118

## 1.10.1

- Improvement to the force recurring/renew option (see note in 1.10.0 and
  [Settings](settings.md)). In 1.10.0 the auto-renew box was getting un-checked
  by CiviCRM core's code when you changed membership type. This release fixes
  that case.

## 1.10.0

- New settings form. **Administer » CiviContribute » GoCardless Settings**

- Option to send receipts (previously it just didn’t) (fix [#61](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/61))

- Option to prevent users from forgetting to tick the Recurring and Auto-renew boxes. (fix [#72](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/72))

- Stores CiviCRM contact, contribution and contribution recur IDs on subscriptions at GoCardless (fix [#79](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/79))

- Various fixes related to webhook URLs.

- (Developers: new import script konp.php may be useful to study and adapt for special cases including importing membership)

## 1.9.3

- Reduce timeout for changing "Pending" recurring contributions to "Failed" from 24 hours to 0.66 hours. See [issue #76](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/76) You can still override this as a parameter, should you wish.

- developers: fixed problem getting and setting the processor ID in import script. Thanks @jmdh for this. Also, there's been a massive refactor of the import script.

- Use `supplemental_address_1` and 2 when prefilling GC address fields. Thanks @TomCranshaw

- Implement new doCancelRecurring and support payment propertyBag, needed in recent core versions. Thanks @mattwire

- Exclude guzzle dependency of the GoCardless library: CiviCRM core already provides guzzle 6, so this extension bringing it in as well is not needed or helpful.

- New docs!

- Move to standard webhook URLs (old one still supported for now) and new helper page (Administer » CiviContribute » GoCardless Webhooks) to spell out the correct URLs to use.

## 1.9.2

- Move to `Payment.create` API instead of older (and deprecated) `Contribution.completetransaction` API.
   - This is from PR #70 (Thanks @mattwire) which fixes [issue #63](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/63) on some sites where the first contribution never gets completed.
   - Also this method is now used for repeat payments.

- Fix some issues with the system checks (PR #69 thanks @mattwire)

- Treat HTTP headers sent by GoCardless webhooks as case-insensitive, as now required by GoCardless (they changed the way they sent HTTP/1.1 headers).

- Fix missing/invalid configuration for payment instrument and payment method.

## 1.9 For CiviCRM 5.19+

- **Do not install v 1.9 from civicrm.org/extensions** - it's missing the important libraries! Use 1.9.1

- Supports changing the amount and cancelling a subscription via CiviCRM main user interface ([issue #6](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/6)). It does not support letting supporters themselves change these things.

- One-way-upgrade: we now store the GoCardless subscription ID in *both* `trxn_id` and `processor_id` in the `civicrm_contribution_recur` table. This is because some parts of CiviCRM's UI require us to reference the record via `processor_id` which was unused up to this point. An upgrade task should populate existing data.

- Some membership dates logic was failing in the tests under CiviCRM 5.19. This version passes its tests again.

- Fix issue when setting up a weekly membership ([issue #59](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/59) - thanks to MJW Consulting for reporting and suggesting fix)

- Improvements to code standards; better support for translation possibilities; move tests to phpunit6.

## 1.8 Big changes

- **Now with pictures** showing the lifecycle of Contribution and
  ContributionRecur records.  
  ![Lifecycle diagrams](/lifecycle.svg)

- **Major change, possibly breaking**: multiple GoCardless payment processors
  now allowed. Previous versions had assumed a single GoCardless payment
  processor, and that's fine for most organisations. However, some organisations
  have cause to use multiple GoCardless accounts with one CiviCRM instance.

  **This change should hopefully be invisible to you and existing sites should
  continue to work as before**, with the **possible exception** of anyone who
  has done a custom (non-CiviCRM Contribution Page) donation form and used
  the GoCardless classes directly. If you have done this then you need to
  adjust your code, removing calls to:

  1. `CRM_GoCardlessUtils::getApi`
  2. `CRM_GoCardlessUtils::getRedirectFlow`
  3. `CRM_GoCardlessUtils::getPaymentProcessor`

  In cases (1), (2), you should now be looking to find an appropriate
  `CRM_Core_Payment_GoCardless` payment processor object (e.g. use
  ```php
  // This assumes you only have one active GoCardless processor.
  $processor_config = civicrm_api3(
      'PaymentProcessor',
      'getsingle',
      ['payment_processor_type_id' => 'GoCardless',
       'is_active' => 1, 'is_test' => 0]);
  $processor = Civi\Payment\System::singleton()->getByProcessor($processor_config);
  $redirect_flow = $processor->getRedirectFlow(...);
  $gc_api = $processor->getGoCardlessApi();
  ```

  ) and call its methods
  which have the same names. In case (3) please just use CiviCRM's native
  methods for finding a payment processor.

  Currently these methods are left in but will trigger `E_USER_DEPRECATED`
  errors to help you find use.


- **Now handles "Late Failures"**

  With BACS (and SEPA, although that's not yet supported here) payments can
  apparently be "Confirmed" one day, then next day they can still fail. This
  is just to keep you on your toes.

  It's called [late failure](https://support.gocardless.com/hc/en-gb/articles/360001467265-Payment-failures).

  Until v1.8 we didn't know about late failures which would result in
  'Completed' contributions being recorded which had actually failed the next
  day.

  This new version of the extension handles late failures by changing the
  Contribution status to Refunded. Note that CiviCRM will not let us change a
  Completed Contribution to Failed, which is why it's processed as a refund.

- **Scheduled job for abandoned/failed mandate setups**

  When a user clicks submit and is redirected to the offsite GoCardless page to
  enter their bank details, a recurring contribution and a contribution record
  are created as Pending on their Contact record.

  If the user gives up at this point then those records would stay in "Pending",
  which means you can't then easily differentiate between those abandoned ones
  and the ones that should complete soon.

  v1.8 includes a scheduled job which will look for any Pending recurring
  contributions older than 24 hours and will mark them as Failed. The Pending
  Contribution record is marked as Cancelled.

  So you can now find abandoned set up attempts by searching for Failed
  recurring payments.

## 1.7

- Fixed issue in certain use cases that resulted in the First Name field not
 being pre-populated ([issue #45](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/45)). Also further thanks to Aidan for knotty
 discussions on membership.

- Fixed issue that caused *other* payment processors' configuration forms to
 not save. ([issue #49](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/49))

##  1.6 "stable"!

- Membership now supported thanks to work by William Mortada @wmortada and
 Aidan Saunders @aydun, and this extension is in production use by quite a few
 organisations so calling this release stable.

- GoCardless forms are now pre-filled with address, email, phone numbers if
 you have collected those details before passing on to GoCardless. Thanks to
 [Vitiligo Society](https://vitiligosociety.org.uk/) for funding this work.

- Updated GoCardlessPro library to 1.7.0 just to keep up-to-date.

### 1.5beta

Should now respect a limited number of installments. Previous
versions will set up an open-ended subscription. You may not have wanted that
;-) Also updated GoCardlessPro library from 1.2.0 to 1.6.0
[GoCardlessPro changelog](https://github.com/gocardless/gocardless-pro-php/releases)
should not have broken anything.
