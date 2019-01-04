<?php

require_once 'gocardless.civix.php';

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function gocardless_civicrm_config(&$config) {
  _gocardless_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @param array $files
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function gocardless_civicrm_xmlMenu(&$files) {
  _gocardless_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * We set up the payment processor type and payment instrument types here.
 * (I tried to do this with `hook_civicrm_managed()` but failed because I need to relate the entities).
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function gocardless_civicrm_install() {
  _gocardless_civix_civicrm_install();

  /**
   * Helper function for creating data structures.
   *
   * @param string $entity - name of the API entity.
   * @param Array $params_min parameters to use for search.
   * @param Array $params_extra these plus $params_min are used if a create call
   *              is needed.
   */
  $get_or_create = function ($entity, $params_min, $params_extra) {
    $params_min += ['sequential' => 1];
    $result = civicrm_api3($entity, 'get', $params_min);
    if (!$result['count']) {
      // Couldn't find it, create it now.
      $result = civicrm_api3($entity, 'create', $params_extra + $params_min);
    }
    return $result['values'][0];
  };

  // We need a payment instrument known as direct_debit_gc.
  $payment_instrument = $get_or_create('OptionValue',
    [ 'option_group_id' => "payment_instrument", 'name' => "direct_debit_gc", ],
    [ 'label' => ts("GoCardless Direct Debit"), ]);
  $payment_instrument_id = $payment_instrument['value'];

  $get_or_create('PaymentProcessorType',
    [
      'name' => 'GoCardless',
      'title' => 'GoCardless',
      'class_name' => 'Payment_GoCardless',
      'billing_mode' => 4,
      'is_recur' => 1,
    ],
    [
      'is_active' => 1,
      'is_default' => 0,
      'user_name_label' => 'API Access Token',
      'signature_label' => 'Webhook Secret',
      'url_api_default' => 'https://api.gocardless.com/',
      'url_api_test_default' => 'https://api-sandbox.gocardless.com/',
      'billing_mode' => 4,
      'is_recur' => 1,
      'payment_type' => $payment_instrument_id,
    ]);
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function gocardless_civicrm_uninstall() {
  _gocardless_civix_civicrm_uninstall();
  // @todo remove direct_debit_gc payment instrument and GoCardless PaymentProcessorType if not in use.
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function gocardless_civicrm_enable() {
  _gocardless_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function gocardless_civicrm_disable() {
  _gocardless_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @param $op string, the type of operation being performed; 'check' or 'enqueue'
 * @param $queue CRM_Queue_Queue, (for 'enqueue') the modifiable list of pending up upgrade tasks
 *
 * @return mixed
 *   Based on op. for 'check', returns array(boolean) (TRUE if upgrades are pending)
 *                for 'enqueue', returns void
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function gocardless_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _gocardless_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function gocardless_civicrm_managed(&$entities) {
  _gocardless_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * @param array $caseTypes
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function gocardless_civicrm_caseTypes(&$caseTypes) {
  _gocardless_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function gocardless_civicrm_angularModules(&$angularModules) {
_gocardless_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function gocardless_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _gocardless_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Complete a GoCardless redirect flow before we present the thank you page.
 *
 * - call GC API to complete the mandate.
 * - find details of the contribution: how much, how often, day of month, 'name'
 * - set up a GC Subscription.
 * - set trxn_id to the subscription ID in the contribution table.
 * - if recurring: set trxn_id, "In Progress", start date in contribution_recur table.
 * - if membership: set membership end date to start date + interval.
 *
 */
function gocardless_civicrm_buildForm( $formName, &$form ) {
  if ($formName != 'CRM_Contribute_Form_Contribution_ThankYou' || empty($_GET['redirect_flow_id'])) {
    // This form build has nothing to do with us.
    return;
  }

  // We have a redirect_flow_id.
  $redirect_flow_id = $_GET['redirect_flow_id'];
  $sesh = CRM_Core_Session::singleton();
  $sesh_store = $sesh->get('redirect_flows', 'GoCardless');
  if (empty($sesh_store[$redirect_flow_id])) {
    // When would this happen?
    // - Back button.
    // - Hacks?
    // - Something else that lost the session.
    //
    // Anyway, in all cases let's assume that we are unable to proceed.
    CRM_Core_Error::fatal('Sorry there was an error setting up your Direct Debit. This could be caused by your browser not allowing cookies.');
    return;
  }

  // Validate the session_token.
  if (empty($_GET['qfKey']) || empty($sesh_store[$redirect_flow_id]['session_token'])
    || $_GET['qfKey'] != $sesh_store[$redirect_flow_id]['session_token']) {

    // @todo throw something that generates a server error 500 page.
    CRM_Core_Error::fatal('Sorry, the session tokens did not match. This should not happen.');
    return;
  }

  // Complete the redirect flow with GC.
  try {
    $params = [ 'redirect_flow_id' => $redirect_flow_id ] + $sesh_store[$redirect_flow_id];
    $result = CRM_GoCardlessUtils::completeRedirectFlowCiviCore($params);
  }
  catch (Exception $e) {
    CRM_Core_Error::fatal('Sorry there was an error setting up your Direct Debit. Please contact us so we can look into what went wrong.');
  }
}

/**
 * Functions below this ship commented out. Uncomment as required.
 *

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function gocardless_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function gocardless_civicrm_navigationMenu(&$menu) {
  _gocardless_civix_insert_navigation_menu($menu, NULL, array(
    'label' => ts('The Page', array('domain' => 'uk.artfulrobot.civicrm.gocardless')),
    'name' => 'the_page',
    'url' => 'civicrm/the-page',
    'permission' => 'access CiviReport,access CiviContribute',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _gocardless_civix_navigationMenu($menu);
} // */
/**
 * Implements hook_civicrm_validateForm().
 *
 * @param string $formName
 * @param array $fields
 * @param array $files
 * @param CRM_Core_Form $form
 * @param array $errors
 */
function gocardless_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  if ($formName === 'CRM_Admin_Form_PaymentProcessor') {
    if (empty($fields['payment_processor_type_id'])) {
      // huh?
      return;
    }
    $payment_processor_name = civicrm_api3('PaymentProcessorType', 'getvalue', ['return' => 'name', 'id' => $fields['payment_processor_type_id']]);
    if ('GoCardless' !== $payment_processor_name) {
      // Not a GoCardless payment processor form.
      return;
    }
    // Now we know it's a GoCardless payment processor form.
    if ($fields['signature'] === $fields['test_signature']) {
      $errors['test_signature'] = ts('Webhook secrets MUST be unique between test and live.');
    }
  }
}
