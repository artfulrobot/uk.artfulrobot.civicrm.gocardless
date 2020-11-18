# Technical notes

GoCardless sends dozens of webhooks and this extension only reacts to the
following:

- payments: confirmed and failed.
- subscriptions: cancelled and finished.

The life-cycle would typically be:

![Lifecycle image](/lifecycle.svg)

It may be more helpful to view the image full size:
[lifecycle.svg](/lifecycle.svg) (nb. it is created from lifecycle.dot
using graphviz)

1. User interacts with a CiviCRM Contribution form to set up regular
   contribution. On submitting the form the user is redirected to the GoCardless
   website after the following records are set up in CiviCRM:

     - a **pending** contribution
     - a **pending** recurring contribution

   Those records have receive and start date set (by core CiviCRM) to the date
   and time the form was submitted (as you might expect). However, once the user
   completes the page(s) on the GoCardless website they are redirected back to
   your website which completes the set up. On completion, the receive date of
   the contribution and the start date of the recurring contribution will have
   been set to a date **in the future**. This is the date provided by GoCardless
   itself and corresponds to the earliest date they can make a charge.

   Also, the recurring contribution record is now set to *In Progress*.

   The completion process sets up the following at GoCardless:

      - a **customer**
      - a **mandate**
      - a **subscription** - the ID of this begins with `SB` and is stored in the
        CiviCRM recurring contribution `trxn_id` and `processor_id` fields
      - a lot of scheduled **payments**


2. GoCardless submits the charge for a payment to the customer's bank and
   eventually (4-5 working days after creation) this is confirmed.
   It sends a webhook for `resource_type` `payments`, action `confirmed`. At
   this point the extension will:

     - look up the payment with GoCardless to obtain its subscription ID.
     - look up the CiviCRM recurring contribution record in CiviCRM from this
       subscription ID (which is stored in the transaction ID field)
     - find the pending CiviCRM contribution record that belongs to the
       recurring contribution and update it, setting status to **Completed**,
       setting the receive date to the **charge date** from GoCardless (n.b.
       this is earlier than the date this payment confirmed webhook fires) and
       setting the transaction ID to the GoCardless payment ID. It also sets
       amount to the amount from GoCardless.
     - check that the status on the CiviCRM recurring contribution
       record is 'In Progress'. (It should be, but the check is there because we
       previously did things differently.)

Note: the following working day the GoCardless payment status is changed from `confirmed` to
`paid_out`. Normally the confirmed webhook will have processed the payment
before this happens, but the extension will allow processing of payment
confirmed webhooks if the payment's status is `paid_out` too. This can be
helpful if there has been a problem with the webhook and you need to replay
some.

3. A week/month/year later GoCardless sends another confirmed payment. This time:

     - look up payment, get subscription ID. As before.
     - look up recurring contribution record from subscription ID. As before.
     - there is no 'pending' contribution now, so a new Completed one is
       created, copying details from the recurring contribution record.

4. Any failed payments work like confirmed ones but of course add or update
   Contributions with status `Failed` instead of `Completed`.

5. The Direct Debit ends with either cancelled or completed. Cancellations can
   be that the *subscription* is cancelled, or the *mandate* is cancelled. The latter would
   affect all subscriptions. Probably other things too, e.g. delete customer.
   GoCardless will cancel all pending payments and inform CiviCRM via webhook.
   GoCardless will then cancel the subscription and inform CiviCRM by webhook.
   Each of these updates the status of the contribution (payment) or recurring
   contribution (subscription) records.




