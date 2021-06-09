# How (not) to use Scheduled Reminders with GoCardless contributions.

Trying to create a Scheduled Reminder that fires on the date of
a completed contribution will fail.

Read a longer
[discussion](https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/115)
of the problem if you like but it's because the Contribution's
`receive_date` is always going to be a day behind when the contribution is
*confirmed* by GoCardless, and this means the Scheduled Reminder system
will not see it; not trigger.

The time between the *charge* (`receive_date`) and the *confirmed payment*
is normally a day. (I'm not sure if it's affected by weekends/holidays
also.)

So to use a Scheduled Reminder on a completed contribution you would need
to add a gap of at least 1 day after the Receive Date to cover this gap.

Some suggest that [CiviRules](https://civicrm.org/extensions/civirules)
may help provide a useful alternative to Scheduled Reminders.
