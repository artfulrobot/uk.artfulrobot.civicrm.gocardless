# Membership Dates

In a simple world, someone fills in a membership form, pays by credit card and their membership is active immediately.

In the DD world, things happen on different dates:
1. `setup_date` - fill in a membership form, complete a DD mandate
   - Recurring Contribution created with status Pending
   - Contribution created with status Pending
   - Membership created  with status Pending, `join_date = start_date = setup_date`
2. `charge_date` - first payment charged
3. `webhook_date` - GC fires webhook and notifies of `charge_date`
   - Recurring Contribution updated to In Progress
   - Contribution updated to status Completed, `receive_date` updated to `charge_date`
   - Membership updated to status New, `join_date` unchanged, `start_date` updated to `webhook_date`, `end_date` updated to `start_date` + membership_length

This appears to be identical date behaviour to creating a membership with a pending cheque payment and then later recording the cheque as being received.  The membership will start when the payment is recorded and the contribution status set to Completed.  The end date is recalculated to be a year (or other membership length) from the start date so that members get a full year of benefit.

However ... some might want the membership to start as soon as the mandate is setup, before waiting for the first payment.  The current date logic is handled by core so this extension would need to override that in a couple of places to implement a different scheme.  Since that is not specific to this payment processor, it might be better to do this as an enhancement to core, or a separate extension.
