# Setting up for membership

The "Auto-renew" option is required for the GoCardless payment processor to
handle memberships.

If you use Price Sets and you have the "Auto renew option, not required"
selected then the user will not be shown the tick-box allowing them to select
Auto Renew, and this will break things. So better to use the straight forward
auto renew option rather than give an option that will break things.

Technical people might like to know that without this, CiviCRM creates a single
contribution and a membership record, but no `contribution_recur` record. This
causes a crash completing the redirect flow because it can't figure out the
interval (i.e. 1/year or such). It is possible to look that up from the
membership ID however that leads to the situation described above, and it's then
not clear what happens when the next payment comes in as it will not match up
with a `contribution_recur` record.

