<?php
/** @file
 *
 *
 *
 * Run this using:
 *
 *     cv scr cli/konp.php
 *
 * It loops active subscriptions at GC and looks them up in CiviCRM.
 *
 * - It finds a person by email.
 *   - It will skip a record if the email is found more than once.
 *   - It will create a new contact if the email is not found.
 * - It will look up the subscription.
 *   - If not found, it will ask if you want to create it.
 *     (this will also import all completed contributions)
 *   - If found, it does not import anything.
 *
 * - It does not import failed payments. (You could easily change it to do
 * this.)
 *
 */

// Config
// ======

// You may want to set a suitable limit here, especially while testing.
// It's set low for testing, but you should increase it or set to NULL (no limit).
const GC_SUBSCRIPTIONS_LIMIT = 10;
//const GC_SUBSCRIPTIONS_LIMIT = NULL;

// You can set a date here, or use NULL
// define('GC_IMPORT_SINCE',  '2019-05-01T00:00:00Z');
define('GC_IMPORT_SINCE',  NULL);

// Where do you want summary output file saved?
define('GC_PRIVATE_OUTPUT_DIR',  '/tmp/');
// Do you want the process to stop and ask whether to create a subscription that can't be found?
define('GC_CONFIRM_BEFORE_CREATING_RECUR',  TRUE);

// Import Code begins
// ==================

if (php_sapi_name() != 'cli') {
  exit;
}

class GCImport
{
  public $descriptionMap = [
    '£2 monthly donation'                      => ['membershipType' => '', 'financialType' => 'Donation (regular)'],
    'Group Affiliation (full)'                 => ['membershipType' => 'Group Affiliation', 'financialType' => 'Group Affiliation dues'],
    'Supporting affiliate'                     => ['membershipType' => 'Supporting Affiliate', 'financialType' => 'Supporting Affiliate dues'],
    'Individual Membership Unwaged'            => ['membershipType' => 'Individual Member (Unwaged)', 'financialType' => 'Member dues'],
    'Individual Membership Waged/Good pension' => ['membershipType' => 'Individual Member (Waged/Good pension)', 'financialType' => 'Member dues'],
    'Regular £10 p/m donation to KONP'         => ['membershipType' => '', 'financialType' => 'Donation (regular)'],
    'Regular £2 p/m donation to KONP'          => ['membershipType' => '', 'financialType' => 'Donation (regular)'],
    'Regular £20 p/m donation to KONP'         => ['membershipType' => '', 'financialType' => 'Donation (regular)'],
    'Regular £25 p/m donation to KONP'         => ['membershipType' => '', 'financialType' => 'Donation (regular)'],
    'Regular £5 p/m donation to KONP'          => ['membershipType' => '', 'financialType' => 'Donation (regular)'],
  ];
  /** @var null|string date. */
  public $importSince;

  /**
   * @var bool
   */
  public $confirmCreateRecur = TRUE;
  /** @var int. */
  public $paymentInstrumentID;

  /** $var int */
  public $contribStatusPending;

  /** $var int */
  public $contribStatusCompleted;

  /** $var int */
  public $contribStatusFailed;

  /** $var int */
  public $contribRecurStatusPending;

  /** $var int */
  public $contribRecurStatusInProgress;

  /** $var int */
  public $contribRecurStatusCompleted;
  /** $var int */
  public $contribRecurStatusCancelled;

  /** $var int */
  public $contribRecurStatusFailed;

  /** @var */
  public $gcAPI;

  /** @var CRM_Core_Payment_GoCardless */
  public $processor;

  public $log = [
    'subscriptions' => [],
    'contactsEncountered' => [],
    'contactsWithAdded' => [],
    'subscriptionsFound' => 0,
    'subscriptionsAdded' => 0,
    'subscriptionsSkipped' => 0,
    'subscriptionsStatusUpdated' => 0,

    'paymentsFound' => 0,
    'paymentsAdded' => 0,
    'paymentsAddedAmount' => 0,

    'contactsAdded' => [],
  ];
  public $logFile = NULL;
  public $lastSave = NULL;
  /**
   * @param null|String $importSince (date)
   */
  public function __construct($importSince = NULL, $confirmCreateRecur=TRUE, $logDir=NULL) {
    civicrm_initialize();

    if ($logDir !== NULL) {
      if (!is_dir($logDir)) {
        throw new InvalidArgumentException("Invalid logdir $logDir");
      }
      $this->logFile = rtrim($logDir, '/') . '/gc-import-log-' . date('Y-m-d:H:i:s') . ".json";
      if (file_exists($this->logFile)) {
        throw new InvalidArgumentException("log file $this->logFile exists.");
      }
      if (!file_put_contents("testing", $this->logFile)) {
        throw new InvalidArgumentException("failed to write to log file $this->logFile");
      }
    }

    $this->confirmCreateRecur = (bool) $confirmCreateRecur;

    if ($importSince) {
      $_ = strtotime($importSince);
      if ($_ === FALSE) {
        throw new InvalidArgumentException("Invalid date '$importSince'");
      }
      // The \Z assumes UTC. Hmmm.
      $this->importSince = date('Y-m-d\TH:i:s\Z');
    }
    $this->paymentInstrumentID = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'direct_debit_gc');
    if (!$this->paymentInstrumentID) {
      throw new \InvalidArgumentException("Failed to find direct_debit_gc payment instrument.");
    }

    $this->contribStatusPending = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending');
    $this->contribStatusCompleted = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    $this->contribStatusFailed = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
    $this->contribRecurStatusPending = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Pending');
    $this->contribRecurStatusInProgress = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'In Progress');
    $this->contribRecurStatusCompleted = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Completed');
    $this->contribRecurStatusFailed = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Failed');
    $this->contribRecurStatusCancelled = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_ContributionRecur', 'contribution_status_id', 'Cancelled');

    // Get a GoCardless API for the live endpoint.
    $processorConfig = civicrm_api3(
      'PaymentProcessor',
      'getsingle',
      ['payment_processor_type_id' => 'GoCardless', 'is_active' => 1, 'is_test' => 0]);
    $this->processor = Civi\Payment\System::singleton()->getByProcessor($processorConfig);

    $this->gcAPI = $this->processor->getGoCardlessApi();
  }

  /**
   */
  public function run($limit=NULL) {

    // Write the first log after 5s by pretending we started 55s ago.
    $this->lastSave = time() - 55;

    // Load all subscriptions (possibly using created_at filter).
    // This gives us an iterator which handles paging transparently.
    // Note: you can limit by customer like this:
    // $params = ['customer' => 'CU000AWWATZGTB'];
    $params = [];
    if ($this->importSince) {
      $params['created_at[gte]'] = $this->importSince;
    }
    $subscriptions = $this->gcAPI->subscriptions()->all(['params' => $params]);

    $count = 0;
    foreach ($subscriptions as $subscription) {
      if ($limit && $count >= $limit) {
        echo "Stopping as limited to $limit\n";
        return;
      }
      $count++;

      print "Subscription: $subscription->id\n";
      $this->log['subscriptions'][$subscription->id] = ['status' => $subscription->status];
      try {
        $payments = $this->getPaymentsToImport($subscription);
        $this->importSubscription($subscription, $payments);
      }
      catch (SkipSubscriptionImportException $e) {
        $this->log['subscriptions'][$subscription->id]['skipped'] = TRUE;
        $this->log['subscriptionsSkipped']++;
        echo "Warning: Skipping subscription $subscription->id: " . $e->getMessage() . "\n";
      }

      if ((time() - $this->lastSave) >= 60) {
        $this->saveAffectedSummary();
        $this->lastSave = time();
      }
    }
    echo "Completed $count subscriptions.\n";
    $this->saveAffectedSummary();
  }

  /**
   *
   * @param GoCardlessPro\Services\SubscriptionsService $subscription
   * @param Array $payments
   */
  public function importSubscription($subscription, $payments) {
    // Try to find the ContributionRecur record. The GoCardless subscription ID
    // is stored as the recurring contrib's `trxn_id`.
    $recurDAO = new CRM_Contribute_BAO_ContributionRecur();
    $recurDAO->processor_id = $subscription->id;

    if (!($recurDAO->find(1))) {
      // CiviCRM does not know this subscription.
      $contactID = $this->getContact($subscription);
      $this->log['contactsEncountered'][$contactID] = TRUE;
      $this->log['subscriptions'][$subscription->id]['contactID'] = $contactID;
      $this->log['subscriptions'][$subscription->id]['wasFound'] = FALSE;

      if ($this->confirmCreateRecur) {
        print "...Create recurring contribution? (N)";
        $yn = strtoupper(trim(fgets(STDIN)));
      }
      else {
        // no need to confirm, get on with it.
        $yn = 'Y';
      }
      if ($yn != 'Y') {
        $this->log['subscriptions'][$subscription->id]['skipped'] = TRUE;
        $this->log['subscriptionsSkipped']++;
        throw new SkipSubscriptionImportException("...Nothing done, skipping subscription.");
      }

      $_ = $this->createContribRecur($subscription, $contactID);
      $contribRecurID = $_['contribution_recur']['id'];
      $financialTypeID = $_['contribution_recur']['financial_type_id'];
      $membership = $_['membership'];

      $this->log['subscriptions'][$subscription->id]['recurID'] = $contribRecurID;
      $this->log['contactsWithAdded'][$contactID] = TRUE;

      if (empty($payments)) {
        $this->createInitialPendingContrib($subscription, $contactID, $contribRecurID, $financialTypeID, $membership);
        return;
      }
    }
    else {
      $contactID = $recurDAO->contact_id;
      $contactID = (int) $recurDAO->contact_id;
      $this->log['contactsEncountered'][$contactID] = TRUE;
      $this->log['subscriptionsFound']++;
      $this->log['subscriptions'][$subscription->id]['contactID'] = $contactID;
      $contribRecurID = (int) $recurDAO->id;
      $this->log['subscriptions'][$subscription->id]['wasFound'] = TRUE;
      print "...Found subscription $subscription->id on recur $contribRecurID belonging to contact $contactID\n";
      $this->log['subscriptions'][$subscription->id]['recurID'] = $contribRecurID;

      $expectedStatus = $this->getMapSubscriptionStatusToContribRecurStatus($subscription);
      if ($expectedStatus != $recurDAO->contribution_status_id) {
        print "...! Status was {$recurDAO->contribution_status_id} expected $expectedStatus. Updated.\n";
        civicrm_api3('ContributionRecur', 'create', [
          'id' => $contribRecurID,
          'contribution_status_id' => $expectedStatus
        ]);
        $this->log['subscriptionsStatusUpdated']++;
        $this->log['subscriptions'][$subscription->id]['statusChange'] =
          ['old' => $recurDAO->contribution_status_id, 'new' => $expectedStatus];
      }

    }

    $this->importPayments($subscription, $payments, $contribRecurID, $contactID, $financialTypeID, $membership);

  }
  /**
   * @return array
   */
  public function getPaymentsToImport($subscription) {
    // Create recurring contribution and related successful contributions.
    print "...looking up payments on subscription $subscription->id\n";
    $payments = $this->gcAPI->payments()->all(['params' => [
      'subscription' => $subscription->id,
    ]]);

    $payments_to_copy = [];
    foreach ($payments as $payment) {
      if ($payment->status == 'confirmed' || $payment->status == 'paid_out') {
        $payments_to_copy[] = [
          'trxn_id'      => $payment->id,
          'receive_date' => $payment->charge_date,
          'total_amount' => $payment->amount/100,
          'line_items' => [
            [
              'line_item' => [[
                'financial_type_id' => $this->financialTypeID, // xxx
                'line_total' => $payment->amount / 100,
                'unit_price' => $payment->amount / 100,
                "price_field_id" => 1,
                'qty' => 1,
              ]]
            ]
          ],
        ];
      }
      else {
        print "...skipping $payment->status payment $payment->id\n";
      }
    }
    print "..." . count($payments_to_copy) . " payments to import.\n";

    return $payments_to_copy;
  }
  /**
   * Find a contact with the customer's email.
   *
   * @throws SkipSubscriptionImportException
   * @return int Contact ID
   */
  public function getContact($subscription) {
    $mandate  = $this->gcAPI->mandates()->get($subscription->links->mandate);
    $customer = $this->gcAPI->customers()->get($mandate->links->customer);
    print "No recur record for subscription $subscription->id $customer->email $customer->given_name $customer->family_name (subscription created $subscription->created_at, started $subscription->start_date).\n";

    // Find Contact in CiviCRM by the email. (See assumptions.)
    $contactID = 0;
    $email = civicrm_api3('Email', 'get', ['email' => $customer->email, 'sequential' => 1, 'return' => 'contact_id']);
    if ($email['count'] == 0) {
      print "Email not found in CiviCRM, creating contact now.\n";
      $contact = civicrm_api3('Contact', 'create', [
        'contact_type'           => 'Individual',
        'first_name'             => $customer->given_name,
        'last_name'              => $customer->family_name,
        'email'                  => $customer->email,
      ]);
      print "Created contact $customer->given_name $customer->family_name id: $contact[id]\n";
      $this->log['contactsAdded'][$contact['id']] = TRUE;
      $this->log['subscriptions'][$subscription->id]['newContactID'] = $contact['id'];
      // Create address.
      $address = civicrm_api3('Address', 'create', [
          'contact_id'             => $contact['id'],
          'location_type_id'       => 'Main',
          'street_address'         => $customer->address_line1,
          'supplemental_address_1' => $customer->address_line2,
          'city'                   => $customer->city,
          'postal_code'            => $customer->postal_code,
          'country_id'             => $customer->country_code,
      ]);
      $contactID = (int) $contact['id'];
    }
    else {
      // Multiple emails found.
      $contactIDs = array_unique(array_map(function ($_) { return (int) $_['contact_id'];}, $email['values']));
      if (count($contactIDs) > 1) {
        throw new SkipSubscriptionImportException("Email $customer->email belongs to more than one contact! NOT importing");
      }
      $contactID = $contactIDs[0];
      print "...Found Contact for this $contactID.\n";
    }

    return $contactID;
  }
  /**
   *
   *
   * @param \GoCardlessPro\Services\SubscriptionsService $subscription
   * @return int
   */
  public function getMapSubscriptionStatusToContribRecurStatus($subscription) {
    switch ($subscription->status) {

    case 'pending_customer_approval':
      // the subscription is waiting for customer approval before becoming active
      // I *think* this is right...
      return $this->contribRecurStatusInProgress;

    case 'customer_approval_denied':
      // the customer did not approve the subscription
      return $this->contribRecurStatusFailed;

    case 'active':
      // the subscription is currently active and will continue to create payments
      return $this->contribRecurStatusInProgress;

    case 'finished':
      // all of the payments scheduled for creation under this subscription have been created
      return $this->contribRecurStatusCompleted;

    case 'cancelled':
      // the subscription has been cancelled and will no longer create payments
      return $this->contribRecurStatusCancelled;

    case 'paused':
      // the subscription has been paused and will not create payments
      // I *think* this makes sense...
      return $this->contribRecurStatusInProgress;

    }
    throw new \Exception("Unknown subscription status: '$subscription->status'");
  }
  /**
   * @return Array with:
   * - String membershipType
   * - String financialType
   * - Array contribution_recur
   * - Array|null membership
   */
  public function createContribRecur($subscription, $contactID) {

    // Figure out what the finaicialTypeID should be
    // We also need to link in/create the membership here.
    $structures = $this->descriptionMap[$subscription->description] ?? NULL;
    if (!$structures) {
      throw new SkipSubscriptionImportException("No description map for " . json_encode($subscription->description));
    }

    $params = [
      'contact_id'             => $contactID,
      'amount'                 => $subscription->amount / 100,
      'currency'               => 'GBP',
      "frequency_unit"         => preg_replace('/ly$/', '', $subscription->interval_unit),
      "frequency_interval"     => $subscription->interval,
      "start_date"             => $subscription->start_date,
      "create_date"            => $subscription->start_date,
      "modified_date"          => $subscription->start_date,
      "end_date"               => $subscription->end_date,
      "processor_id"           => $subscription->id,
      "trxn_id"                => $subscription->id,
      "contribution_status_id" => $this->contribRecurStatusInProgress,
      "is_test"                => 0,
      "cycle_day"              => 1,
      "payment_processor_id"   => $this->processor->getID(),
      "financial_type_id"      => $structures['financialType'],
      "payment_instrument_id"  => $this->paymentInstrumentID,
      'source'                 => 'Imported from GoCardless ' . date('Y-m-d H:i:s'),
    ];

    $recur = Civicrm_api3('ContributionRecur', 'create', $params);
    $recurID = $recur['id'];

    // Create a membership.
    if ($structures['membershipType']) {
      $membership = civicrm_api3('Membership', 'create', [
        'membership_type_id'    => $structures['membershipType'],
        'contact_id'            => $contactID,
        'join_date'             => $subscription->created_at, //xxx
        'start_date'            => $subscription->created_at,
        'contribution_recur_id' => $recurID,
      ]);
    }

    print "✔ Created ContributionRecur $recurID for subscription $subscription->id $structures[membershipType]\n";

    return $structures + ['contribution_recur' => $recur, 'membership' => $membership];
  }
  /**
   * @param GoCardlessPro\Services\SubscriptionsService $subscription
   * @param int $contactID
   * @param int $contribRecurID
   *
   */
  public function createInitialPendingContrib($subscription, $contactID, $contribRecurID, $financialTypeID, $membership) {

    print "Creating initial pending contribution\n";
    $_ = [
      'receive_date'           => $subscription->start_date,
      'total_amount'           => $subscription->amount / 100,
      'contact_id'             => $contactID,
      "payment_instrument_id"  => $this->paymentInstrumentID,
      'currency'               => 'GBP',
      "financial_type_id"      => $financialTypeID,
      'contribution_recur_id'  => $contribRecurID,
      'is_test'                => 0,
      'contribution_status_id' => $this->contribStatusPending,
      'is_email_receipt'       => 0,
      'line_items' => [
        [
          'line_item' => [[
            'line_total' => $subscription->amount / 100,
            'unit_price' => $subscription->amount / 100,
            "price_field_id" => 1,
            'financial_type_id' => $financialTypeID,
            'qty' => 1,
          ]]
        ]
      ],
    ];
    //print json_encode($_, JSON_PRETTY_PRINT) . "\n";
    $contribution = civicrm_api3('Order', 'create', $_);
    if (!$contribution['is_error']) {
      print "✔ Created initial pending payment $contribution[id]\n";
    }
    else {
      throw new \RuntimeException(json_encode($contribution, JSON_PRETTY_PRINT));
    }

    if ($membership) {
      // Need to link this payment to the membership, I think.
      civicrm_api3('MembershipPayment', 'create', [
        'membership_id'   => $membership['id'],
        'contribution_id' => $contribution['id'],
      ]);
    }
  }
  /**
   * @param GoCardlessPro\Services\SubscriptionsService $subscription
   * @param Array $payments
   * @param int $contribRecurID
   * @param int $financialTypeID
   * @param Array $membership
   */
  public function importPayments($subscription, $payments, $contribRecurID, $contactID, $financialTypeID, $membership) {

    $trxn_ids = [];
    foreach ($payments as $payment) {
      if (!preg_match('/^[A-Z0-9]+$/', $payment['trxn_id'])) {
        throw new Exception("Invalid trxn_id: $payment[trxn_id]");
      }
      $trxn_ids[] = '"' . $payment['trxn_id'] . '"';
      $this->log['subscriptions'][$subscription->id]['payments'][$payment['trxn_id']] = [];
    }

    if ($trxn_ids) {
      $trxn_ids = implode(",", $trxn_ids);
      $trxn_ids = CRM_Core_DAO::executeQuery("SELECT trxn_id FROM civicrm_contribution WHERE contribution_recur_id = $contribRecurID AND trxn_id IN ($trxn_ids)")
        ->fetchMap('trxn_id', 'trxn_id');
    }

    $skips = 0;
    foreach ($payments as $payment) {
      $log = & $this->log['subscriptions'][$subscription->id]['payments'][$payment['trxn_id']];

      if (isset($trxn_ids[$payment['trxn_id']])) {
        $log['wasFound'] = TRUE;
        $this->log['paymentsFound']++;
        $skips++;
        continue;
      }

      $log['wasFound'] = FALSE;
      $payment += [
        'contact_id'             => $contactID,
        'contribution_recur_id'  => $contribRecurID,
        "payment_instrument_id"  => $this->paymentInstrumentID,
        'currency'               => 'GBP',
        "financial_type_id"      => $financialTypeID,
        'contribution_status_id' => $this->contribStatusPending,
        'is_test'                => 0,
        'is_email_receipt'       => 0,
        'source'                 => 'GC import ' . date('Y-m-d H:i:s'),
      ];
      // print json_encode($payment, JSON_PRETTY_PRINT) . "\n";
      $orderCreateResult = civicrm_api3('Order', 'create', $payment);
      if (!$orderCreateResult['is_error']) {
        print "...+ Created Order for payment $payment[trxn_id], contribution ID: $orderCreateResult[id] on $payment[receive_date]\n";
        $log['newContribID'] = $orderCreateResult['id'];
        $log['amount'] = $payment['total_amount'];
        $log['date'] = $payment['receive_date'];
        $this->log['paymentsAdded']++;
        $this->log['paymentsAddedAmount'] += $payment['total_amount'];
        if ($membership) {
          // Link to membership
          civicrm_api3('MembershipPayment', 'create', [
            'membership_id'   => $membership['id'],
            'contribution_id' => $orderCreateResult['id'],
          ]);
        }
      }
      else {
        throw new RuntimeException("Error creating order: " . json_encode($orderCreateResult, JSON_PRETTY_PRINT));
      }

      // Now complete the payment.
      $paymentCreateParams = [
        'contribution_id'                   => $orderCreateResult['id'],
        'total_amount'                      => $payment['total_amount'],
        'trxn_date'                         => $payment['receive_date'],
        'trxn_id'                           => $payment['trxn_id'],
        'is_send_contribution_notification' => 0,
      ];
      $paymentCreateResult = civicrm_api3('Payment', 'create', $paymentCreateParams);
      if (!$paymentCreateResult['is_error']) {
        // correct the contribution date.
        civicrm_api3('Contribution', 'create', [
          'id' => $orderCreateResult['id'],
          'receive_date' => $payment['receive_date'],
        ]);

        print "...+ Created Payment on Order for payment $payment[trxn_id], contribution ID: $orderCreateResult[id]\n";
      }
      else {
        throw new RuntimeException("Error calling payment.create with " . json_encode($paymentCreateResult, JSON_PRETTY_PRINT));
      }
    }
    if ($skips>0) {
      print "...Skipped $skips payments already found\n";
    }
  }
  /**
   */
  public function saveAffectedSummary() {

    // Do some summary counts.
    $log = ['logCreated' => date('Y-m-d H:i:s'), 'stats' => []] + $this->log;
    foreach(['contactsEncountered', 'contactsWithAdded', 'contactsAdded'] as $_) {
      $log['stats'][$_] = count($log[$_]);
      $log[$_] = array_keys($log[$_]);
    }

    foreach(['subscriptionsFound', 'subscriptionsAdded', 'subscriptionsSkipped',
      'subscriptionsStatusUpdated', 'paymentsFound', 'paymentsAdded', 'paymentsAddedAmount',
    ] as $_) {
      $log['stats'][$_] = $log[$_];
      unset($log[$_]);
    }
    $log['stats']['subscriptionsFoundPercent'] = number_format($log['stats']['subscriptionsFound'] * 100 / ($log['stats']['subscriptionsFound'] + $log['stats']['subscriptionsAdded'] + $log['stats']['subscriptionsSkipped']), 1) . '%';
    $log['stats']['paymentsFoundPercent'] = number_format($log['stats']['paymentsFound'] * 100 / ($log['stats']['paymentsFound'] + $log['stats']['paymentsAdded']), 1) . '%';

    print json_encode($log['stats'], JSON_PRETTY_PRINT) . "\n";
    if ($this->logFile) {
      file_put_contents($this->logFile, json_encode($log));
      print "Log saved to $this->logFile\n";
    }
  }
}


try {
  $importer = new GCImport(
    GC_IMPORT_SINCE,
    GC_CONFIRM_BEFORE_CREATING_RECUR,
    GC_PRIVATE_OUTPUT_DIR
  );
  $importer->run(GC_SUBSCRIPTIONS_LIMIT);
}
catch (\Exception $e) {
  print "Error: " . $e->getMessage() . "\n\n" . $e->getTraceAsString();
}

class SkipSubscriptionImportException extends Exception {}
