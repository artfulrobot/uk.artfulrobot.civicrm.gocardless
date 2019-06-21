<?php
return [
  [
    'entity' => 'OptionValue',
    'name'   => 'Abandoned_contribution_status',
    'params' => [
      'version'         => 3,
      'option_group_id' => 'contribution_status',
      'name'            => 'Abandoned',
      'label'           => 'Abandoned',
      'description'     => 'Use for ContributionRecur records only: indicates that a user began setting up a recurring contribution, but then abandoned the process. i.e. we will never expect this to yield contributions.',
      'is_active'       => 1,
      'is_reserved'     => 1,
    ]
  ]
];
