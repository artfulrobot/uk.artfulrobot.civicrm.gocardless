<?php
use CRM_GoCardless_ExtensionUtil as E;

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
 * Various intercepts.
 *
 */
function gocardless_civicrm_buildForm($formName, &$form) {

  if ($formName === 'CRM_Contribute_Form_Contribution_ThankYou') {
    if (!empty($_GET['redirect_flow_id'])) {
      // Looks like a GoCardless thank you page. Complete redirect flow.
      CRM_GoCardlessUtils::handleContributeFormThanks();
    }
  }
  elseif ($formName === 'CRM_Contribute_Form_Contribution_Main') {
    CRM_GoCardlessUtils::handleContributeFormHacks();
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
 */
function gocardless_civicrm_navigationMenu(&$menu) {
  _gocardless_civix_insert_navigation_menu($menu, 'Administer/CiviContribute', array(
    'label' => E::ts('GoCardless Settings', array('domain' => 'uk.artfulrobot.civicrm.gocardless')),
    'name' => 'gocardless_webhook_helper',
    'url' => 'civicrm/a#/gocardless',
    'permission' => 'administer payment processors',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _gocardless_civix_navigationMenu($menu);
}
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
      $errors['test_signature'] = E::ts('Webhook secrets MUST be unique between test and live.');
    }
  }
}
/**
 * Implementation of hook_civicrm_check
 *
 * Add a check to the status page/System.check that the payment instrument has a financial account.
 * See https://github.com/artfulrobot/uk.artfulrobot.civicrm.gocardless/issues/51
 */
function gocardless_civicrm_check(&$messages) {
  $result = civicrm_api3('OptionValue', 'getsingle', [
      'option_group_id' => "payment_instrument",
      'name' => "direct_debit_gc",
  ]);
  $financial_account_id = CRM_Contribute_PseudoConstant::getRelationalFinancialAccount($result['id'], NULL, 'civicrm_option_value');

  if (empty($financial_account_id)) {
    $messages[] = new CRM_Utils_Check_Message(
      'gocardless_missing_financial_account',
      E::ts('Please visit Administer » CiviContribute » Payment Methods and edit '
          . 'the entry called GoCardless Direct Debit. Select a suitable Financial Account '
          . 'and press Save. Without this you may see errors like "No Payments found for '
          . 'this contribution record".'),
      E::ts('Missing Financial Account for GoCardless'),
      \Psr\Log\LogLevel::WARNING,
      'fa-flag'
    );
  }
}
