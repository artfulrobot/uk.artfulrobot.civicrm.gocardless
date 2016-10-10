<?php
/**
 * @file
 * Describes the GoCardless payment processor.
 */
return [
  [
    'entity' => 'PaymentProcessorType',
    'cleanup' => 'unused',
    'params' => [
      'version' => 3,
      'name' => 'GoCardless',
      'title' => 'GoCardless',
      'is_active' => 1,
      'is_default' => 0,
      'user_name_label' => 'API Access Token',
      'class_name' => 'CRM_Core_Payment_GoCardless',
      'url_api_default' => 'https://api.gocardless.com/',
      'url_api_test_default' => 'https://api-sandbox.gocardless.com/',
      'billing_mode' => 4,
      'is_recur' => 1,
      'payment_type' => 2, // Mandatory but only a choice between Credit/Debit cards. Went with Debit!
      // 'domain_id' => 1, This is not documented at https://wiki.civicrm.org/confluence/display/CRMDOC/Create+a+Payment-Processor+Extension
    ],
  ]
];
