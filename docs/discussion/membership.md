# Membership Dates

In a simple world, someone fills in a membership form, pays by credit card and
their membership is active immediately.

In the direct debit world, things happen on different dates:

1. `setup_date` - fill in a membership form, complete a direct debit mandate
   - Recurring Contribution created with status In Progress  
     `start_date = charge_date`
   - Contribution created with status Pending  
     `receive_date = charge_date`
   - Membership created with status Pending  
     `join_date`, `start_date`, `end_date` are blank

2. `charge_date` - first payment charged (4-5 working days later)

3. `webhook_date` - GoCardless fires webhook (one working day after the `charge_date`)

   - Contribution updated to status Completed

   - Membership updated to status New  
     `join_date = start_date = webhook_date`  
     `end_date = start_date + membership_length`

This appears to be identical date behaviour to creating a membership with a
pending cheque payment and then later recording the cheque as being received.
The membership will start when the payment is recorded and the contribution
status set to Completed. The end date is recalculated to be a year (or other
membership length) from the start date so that members get a full year of
benefit.

However... some might want the membership to start as soon as the mandate is
set up, before waiting for the first payment. The current date logic is handled
by core so this extension would need to override that in a couple of places to
implement a different scheme.  Since that is not specific to this payment
processor, it might be better to do this as an enhancement to core, or a
separate extension.

See also: [How to set up for membership](/howto/membership.md)

