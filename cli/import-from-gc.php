<?php
/**
 * @file
 * This is a script provided for **developers** wanting to import existing
 * GoCardless subscriptions into CiviCRM, e.g. if you were using GoCardless
 * with a non-CiviCRM based system before.
 *
 * It is **not** ready to run, but is provided as a basis for your own import needs.
 *
 * You should definitely be doing this on a development copy of the site first!
 * And you should definitely be doing backups etc. But you know that, of course you do.
 *
 * Nb. This will **not** work as a migration route from Veda's GoCardless extension.
 *
 * Run this using:
 *
 *     cv scr import-from-gocardless.php
 *
 * It loops all (100) subscriptions at GC and looks them up in CiviCRM. It will
 * report details for any not found in CiviCRM.
 *
 * Assumptions and notes (**important**)
 *
 * - It assumes that all the customers in GoCardless can be found in CiviCRM by
 *   looking up their email. If this is not the case either change this script or
 *   do a separate import first.
 *
 * - It does not import failed payments. (You could easily change it to do this.)
 *
 */

// You may want to set a suitable limit here, especially while testing.


if (php_sapi_name() != 'cli') {
  exit;
}

const GC_SUBSCRIPTIONS_LIMIT = 1000; // xxx
const GC_IMPORT_FINANCIAL_TYPE_ID=20;     // xxx set this to whatever you need.
// Get GC api.
civicrm_initialize();
define('GC_IMPORT_PAYMENT_INSTRUMENT_ID', civicrm_api3('OptionValue', 'getvalue', [
  'return' => "value",
  'option_group_id' => "payment_instrument",
  'name' => "direct_debit_gc",
]));

if (!GC_IMPORT_PAYMENT_INSTRUMENT_ID) {
  echo "Failed to find direct_debit_gc payment instrument.";
  exit;
}
$gc_api = CRM_GoCardlessUtils::getApi(FALSE);
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

    // Find Contact in CiviCRM by the email. (See assumptions.)
    $contact = civicrm_api3('Contact', 'get', ['email' => $customer->email, 'sequential' => 1]);
    print "No recur record for subscription $subscription->id $customer->email $customer->given_name $customer->family_name. Found $contact[count] in CiviCRM.\n";
    $yn = '';
    if ($contact['count'] == 1) {
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

    $contact = $contact['values'][0];
    $params = [
      'contact_id'             => $contact['id'],
      'amount'                 => $subscription->amount / 100,
      'currency'               => 'GBP',
      "frequency_unit"         => preg_replace('/ly$/', '', $subscription->interval_unit),
      "frequency_interval"     => $subscription->interval,
      "start_date"             => $subscription->start_date,
      "create_date"            => $subscription->start_date,
      "modified_date"          => $subscription->start_date,
      "end_date"               => $subscription->end_date,
      "trxn_id"                => $subscription->id,
      "contribution_status_id" => 1, // 1:Completed, 2: pending, 3: cancelled, 4: failed, 5: in progress ...)
      "is_test"                => 0,
      "cycle_day"              => 1,
      "payment_processor_id"   => 3, // GoCardless, Live
      "financial_type_id"      => 20, // Donation (regular)
      "payment_instrument_id"  => 23, // named direct_debit_gc
    ];
    print "creating...with " . json_encode($params, JSON_PRETTY_PRINT);
    $result = civicrm_api3('ContributionRecur', 'create', $params);
    if ($result['id']) {
      $recur_id = $result['id'];
      print "✔ Created ContributionRecur $recur_id\n";
      foreach ($payments_to_copy as $_) {
        $_ += [
          'contact_id'             => $contact['id'],
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
