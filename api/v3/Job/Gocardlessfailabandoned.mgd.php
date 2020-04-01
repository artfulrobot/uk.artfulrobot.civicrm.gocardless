<?php
// This file declares a managed database record of type "Job".
// The record will be automatically inserted, updated, or deleted from the
// database as appropriate. For more details, see "hook_civicrm_managed" at:
// http://wiki.civicrm.org/confluence/display/CRMDOC42/Hook+Reference
return array(
  0 =>
  array(
    'name' => 'Cron:Job.Gocardlessfailabandoned',
    'entity' => 'Job',
    'params' =>
    array(
      'version' => 3,
      'name' => 'GoCardless: mark abandoned Pending recurring contributions Failed',
      'description' => 'If a user starts to donate but abandons on the GoCardless page their ContributionRecur record gets stuck at Pending/Incomplete. This job marks those as Failed after 40 mins (GoCardless only allows 30 mins to complete a redirect flow) so you can see abandoned recurring contributions. You may choose to lengthen the interval to 1.5 hours if you want to allow for someone setting up a mandate at the cusp of a daylight saving hour change.',
      'run_frequency' => 'Always',
      'api_entity' => 'Job',
      'api_action' => 'Gocardlessfailabandoned',
      'parameters' => '',
    ),
  ),
);
