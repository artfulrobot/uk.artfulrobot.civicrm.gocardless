<?php
return [
  'gocardless' => [
    'name'        => 'gocardless',
    'title'       => ts('GoCardless settings'),
    'description' => ts('JSON encoded settings.'),
    'group_name'  => 'domain',
    'type'        => 'String',
    'default'     => FALSE,
    'add'         => '5.30',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
];
