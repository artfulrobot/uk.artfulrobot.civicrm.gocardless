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
      'description' => 'If a user starts to donate but abandons on the GoCardless page their ContributionRecur record gets stuck at Pending/Incomplete. This job marks those as Failed after 24 hours so you can see abandoned payments.',
      'run_frequency' => 'Always',
      'api_entity' => 'Job',
      'api_action' => 'Gocardlessfailabandoned',
      'parameters' => '',
    ),
  ),
);
