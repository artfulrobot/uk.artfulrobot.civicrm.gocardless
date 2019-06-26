<?php
/** @file This is a script provided for **developers** wanting to import
 * existing GoCardless subscriptions into CiviCRM, e.g. if you were using
 * GoCardless with a non-CiviCRM based system before.
 *
 * You should **not** simply run this! But it is provided as a basis for your
 * own import needs.
 *
 * You should definitely be doing this on a development copy of the site first!
 * And you should definitely be doing backups etc. But you know that, of course
 * you do.
 *
 * Nb. This will **not** work as a migration route from Veda's GoCardless
 * extension.
 *
 * Run this using:
 *
 *     cv scr import-from-gocardless.php
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
// It's set low for testing, but you should increase it to some number in
// excess of your donors when running for real.
const GC_SUBSCRIPTIONS_LIMIT = 10; // xxx

// What financial type do you want to use?
define('GC_IMPORT_FINANCIAL_TYPE',  'Donation (regular)');


// Import Code begins
// ==================

if (php_sapi_name() != 'cli') {
  exit;
}

define('GC_IMPORT_FINANCIAL_TYPE_ID',  CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'financial_type_id', GC_IMPORT_FINANCIAL_TYPE));
if (!GC_IMPORT_FINANCIAL_TYPE_ID) {
  echo "Failed to find financial type " . GC_IMPORT_FINANCIAL_TYPE . "\n";
  exit;
}
// Get GC api.
civicrm_initialize();
define('GC_IMPORT_PAYMENT_INSTRUMENT_ID', CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'payment_instrument_id', 'direct_debit_gc'));
if (!GC_IMPORT_PAYMENT_INSTRUMENT_ID) {
  echo "Failed to find direct_debit_gc payment instrument.";
  exit;
}
define('STATUS_PENDING',  CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Pending'));
define('STATUS_IN_PROGRESS',  CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'In Progress'));

// Get a GoCardless API for the live endpoint.
$processor_config = civicrm_api3(
    'PaymentProcessor',
    'getsingle',
    ['payment_processor_type_id' => 'GoCardless', 'is_active' => 1, 'is_test' => 0]);
$processor = Civi\Payment\System::singleton()->getByProcessor($processor_config);
$gc_api = $processor->getGoCardlessApi();

$subscriptions = $gc_api->subscriptions()->list(['params' => [
  'limit' => GC_SUBSCRIPTIONS_LIMIT,
  'status' => 'active',
]]);
print count($subscriptions->records) . " active subscriptions\n";

foreach ($subscriptions->records as $subscription) {

  // Try to find the ContributionRecur record. The GoCardless subscription ID
  // is stored as the recurring contrib's `trxn_id`.
  $recur = civicrm_api3('ContributionRecur', 'get', [
    'trxn_id' => $subscription->id,
  ]);

  if ($recur['count'] == 0) {
    // CiviCRM does not know this subscription.
    $mandate  = $gc_api->mandates()->get($subscription->links->mandate);
    $customer = $gc_api->customers()->get($mandate->links->customer);
    print "No recur record for subscription $subscription->id $customer->email $customer->given_name $customer->family_name.\n";

    // Find Contact in CiviCRM by the email. (See assumptions.)
    $contact_id = 0;
    $email = civicrm_api3('Email', 'get', ['email' =>$customer->email, 'sequential' => 1, 'return' => 'contact_id']);
    if ($email['count'] == 0) {
      print "Email not found in CiviCRM, creating contact now.\n";
      $contact = civicrm_api3('Contact', 'create', [
        'contact_type'           => 'Individual',
        'first_name'             => $customer->given_name,
        'last_name'              => $customer->family_name,
        'email'                  => $customer->email,
      ]);
      print "Created contact $customer->given_name $customer->family_name id: $contact[id]\n";
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
      $contact_id = (int) $contact['id'];
    }
    else {
      $contact_ids = array_unique(array_map(function ($_) { return (int) $_['contact_id'];}, $email['values']));
      if (count($contact_ids) > 1) {
        print "Email belongs to more than one contact! NOT importing.\n";
        continue;
      }
      $contact_id = $contact_ids[0];
      print "Found Contact for this $contact_id.\n";
    }

    $yn = '';
    if ($contact_id) {
      print "Create recurring contribution? (N)";
      $yn = strtoupper(trim(fgets(STDIN)));
    }
    if ($yn != 'Y') {
      continue;
    }

    // Create recurring contribution and related successful contributions.
    print "looking up payments\n";
    $payments = $gc_api->payments()->list(['params' => [
      'subscription' => $subscription->id,
    ]]);

    $payments_to_copy = [];
    foreach ($payments->records as $payment) {
      if ($payment->status == 'confirmed' || $payment->status == 'paid_out') {
        $payments_to_copy[] = [
          'trxn_id'      => $payment->id,
          'receive_date' => $payment->charge_date,
          'total_amount' => $payment->amount/100,
        ];
      }
      else print "Not importing $payment->status payment $payment->id\n";
    }
    print count($payments->records) . " payments, to copy " . count($payments_to_copy) . "\n";

    $params = [
      'contact_id'             => $contact_id,
      'amount'                 => $subscription->amount / 100,
      'currency'               => 'GBP',
      "frequency_unit"         => preg_replace('/ly$/', '', $subscription->interval_unit),
      "frequency_interval"     => $subscription->interval,
      "start_date"             => $subscription->start_date,
      "create_date"            => $subscription->start_date,
      "modified_date"          => $subscription->start_date,
      "end_date"               => $subscription->end_date,
      "trxn_id"                => $subscription->id,
      "contribution_status_id" => STATUS_IN_PROGRESS, // 1:Completed, 2: pending, 3: cancelled, 4: failed, 5: in progress ...)
      "is_test"                => 0,
      "cycle_day"              => 1,
      "payment_processor_id"   => $processor->id,
      "financial_type_id"      => GC_IMPORT_FINANCIAL_TYPE_ID,
      "payment_instrument_id"  => GC_IMPORT_PAYMENT_INSTRUMENT_ID,
    ];
    print "creating...with " . json_encode($params, JSON_PRETTY_PRINT);
    $result = civicrm_api3('ContributionRecur', 'create', $params);
    if ($result['id']) {
      $recur_id = $result['id'];
      print "✔ Created ContributionRecur $recur_id\n";

      if (empty($payments_to_copy)) {
        print "Creating initial pending contribution\n";
        $_ = [
          'receive_date'           => $subscription->start_date,
          'total_amount'           => $subscription->amount / 100,
          'contact_id'             => $contact_id,
          "payment_instrument_id"  => GC_IMPORT_PAYMENT_INSTRUMENT_ID,
          'currency'               => 'GBP',
          "financial_type_id"      => GC_IMPORT_FINANCIAL_TYPE_ID,
          'contribution_recur_id'  => $recur_id,
          'is_test'                => 0,
          'contribution_status_id' => STATUS_PENDING,
        ];
        print json_encode($_, JSON_PRETTY_PRINT);
        $result = civicrm_api3('Contribution', 'create', $_);
        if (!$result['is_error']) {
          print "✔ Created payment $result[id]\n";
        }
        else {
          print_r($result);
          exit;
        }
      }
      foreach ($payments_to_copy as $_) {
        $_ += [
          'contact_id'             => $contact_id,
          "payment_instrument_id"  => GC_IMPORT_PAYMENT_INSTRUMENT_ID,
          'currency'               => 'GBP',
          "financial_type_id"      => GC_IMPORT_FINANCIAL_TYPE_ID,
          'contribution_recur_id'  => $recur_id,
          'is_test'                => 0,
          'contribution_status_id' => 1, // 1: Completed.
        ];
        print json_encode($_, JSON_PRETTY_PRINT);
        $result = civicrm_api3('Contribution', 'create', $_);
        if (!$result['is_error']) {
          print "✔ Created payment $result[id]\n";
        }
        else {
          print_r($result);
          exit;
        }
      }


    }
    else {
      print_r($result);
      exit;
    }
  }
  else {
    print "Already found: $subscription->id\n";
    //print json_encode($recur['values'][$recur['id']], JSON_PRETTY_PRINT);
  }
}
