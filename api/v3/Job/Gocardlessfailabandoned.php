<?php
use CRM_GoCardless_ExtensionUtil as E;

/**
 * Job.Gocardlessfailabandoned API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_job_Gocardlessfailabandoned_spec(&$spec) {
  $spec['timeout']['description'] = 'How long (hours) before we consider '
    . 'Pending ContributionRecur records as abandoned. Default: 24';
  $spec['timeout']['api.default'] = 24;
}

/**
 * Job.Gocardlessfailabandoned API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_job_Gocardlessfailabandoned($params) {

  $hours = (float) ($params['timeout'] ?? 0);
  if (!($hours >0)) {
    throw new API_Exception("Invalid timeout for Gocardlessfailabandoned. Should be an amount of hours > 0");
  }
  $too_old = time() - 60*60*$hours;

  // We need a list of GoCardless payment processors.
	$result = civicrm_api3('PaymentProcessor', 'get', [
      'return' => ["id"],
      'payment_processor_type_id' => "GoCardless",
    ]);

  $returnValues = ['contribution_recur_ids' => []];
  if ($result['count']) {
    $payment_processor_ids = array_keys($result['values']);

    $old_crs = civicrm_api3('ContributionRecur', 'get', [
      'payment_processor_id'   => ['IN' => $payment_processor_ids],
      'contribution_status_id' => "Pending",
      'modified_date'          => ['<' => date('Y-m-d H:i:s', $too_old)],
    ]);

    if ($old_crs['count'] > 0) {
      foreach (array_keys($old_crs['values']) as $contribution_recur_id) {
        civicrm_api3('ContributionRecur', 'create', [
          'contribution_status_id' => 'Failed',
          'id'                     => $contribution_recur_id,
        ]);
        $returnValues['contribution_recur_ids'][] = $contribution_recur_id;
      }
    }
  }
  return civicrm_api3_create_success($returnValues, $params, 'Job', 'GoCardlessFailAbandoned');
}
