<?php
// This file declares an Angular module which can be autoloaded
// in CiviCRM. See also:
// \https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_angularModules/n
return [
  'js' => [
    'ang/gocardless.js',
    'ang/gocardless/*.js',
    'ang/gocardless/*/*.js',
  ],
  'css' => [
    'ang/gocardless.css',
  ],
  'partials' => [
    'ang/gocardless',
  ],
  'requires' => [
    'crmUi',
    'crmUtil',
    'ngRoute',
  ],
  'settings' => [],
];
