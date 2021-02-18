// This ensures that the is_recur check box is always checked if a GoCardless payment processor is selected.
//
// It does this by controlling the is_recur and auto_renew checkbox inputs.
//
// - Contribution forms with just the GoCardless payment processor (PP) enabled
//   have a hidden input with the PP ID.
//
// - Contribution forms with a choice of PPs use radio buttons.
//
// Bug: When only Other amount is selected or before a radio amount is chosen, there's no PP stuff until an amount is entered.
//
document.addEventListener('DOMContentLoaded', function () {
  // var debug = console.log;
  var debug = function() {};
  debug("GoCardless loaded");
  // This next line gets swapped out by PHP
  var goCardlessProcessorIDs = [];

  // CiviCRM uses isRecur for non-membership payments, and autoRenew for memberships.
  // Nb. autoRenew may not be there e.g. if autoRenew is not allowed, or is not optional.
  var isRecurInput = document.getElementById('is_recur');
  var autoRenewInput = document.getElementById('auto_renew');
  var ppRadios = document.querySelectorAll('input[type="radio"][name="payment_processor_id"]');
  var goCardlessProcessorSelected;
  var selectedProcessorName;

  // Note: templates/CRM/common/paymentBlock.tpl includes JS which
  // un-checks the .checked property on all payment processor radios when that
  // block of form is hidden, and when it is shown, it sets the .checked
  // property where the checked attribute exists (i.e. the default).
  // There are no events to hook into here, so we need to resort to polling to
  // find out when it's changed.
  //var paymentOptionsGroup = document.querySelector('div.payment_options-group');
  //var paymentProcessorSection = document.querySelector('div.payment_processor-section');
  var gcWasPreviouslySelected = false;

  // Listen for when the user changes/tries to change the isRecurInput
  if (isRecurInput) {
    isRecurInput.addEventListener('change', function(e) {
      if (!isRecurInput.checked && goCardlessProcessorSelected) {
        e.preventDefault();
        e.stopPropagation();
        forceRecurring(true);
      }
    });
    debug("Added event listener to isRecurInput", isRecurInput);
  }

  // Listen for when the user changes/tries to change the autoRenewInput
  if (autoRenewInput) {
    autoRenewInput.addEventListener('change', function(e) {
      if (!autoRenewInput.checked && goCardlessProcessorSelected) {
        e.preventDefault();
        e.stopPropagation();
        forceRecurring(true);
      }
    });
    debug("Added event listener to autoRenew", autoRenewInput);
  }

  // This forces whichever of autoRenewInput and isRecurInput is on.
  function forceRecurring(withAlert) {

    if (withAlert) {
      if (selectedProcessorName) {
        alert("Contributions made with " + selectedProcessorName + " must be recurring/auto renewing.");
      }
      else {
        alert("Direct Debit contributions must be recurring/auto renewing.");
      }
    }

    // As we've changed this we need to trigger a 'change' event so that the other UI can respond.
    var fakeEvent = new Event('change');

    if (isRecurInput) {
      debug("Forcing isRecurInput");
      isRecurInput.checked = true;
      isRecurInput.dispatchEvent(fakeEvent);
    }

    if (autoRenewInput) {
      debug("Forcing autoRenew");
      autoRenewInput.checked = true;
      autoRenewInput.dispatchEvent(fakeEvent);
    }
  }

  // This function looks through payment processor selector radios,
  // set goCardlessProcessorSelected and if found, forceRecurring
  function gcFixRecurFromRadios() {
    var ppID;
    [].forEach.call(ppRadios, function(r) {
      if (r.checked) {
        ppID = parseInt(r.value);
        var label = document.querySelector('label[for="' + r.id + '"]');
        selectedProcessorName = label ? label.textContent : 'Direct Debit';
      }
    });
    goCardlessProcessorSelected = (goCardlessProcessorIDs.indexOf(ppID) > -1);

    if (goCardlessProcessorSelected && !gcWasPreviouslySelected) {
      forceRecurring();
    }
    gcWasPreviouslySelected = goCardlessProcessorSelected;
    debug("Determined whether GoCardless is the processor selected from radios:", goCardlessProcessorSelected);
  }

  // If the user has a choice about any type of auto renew...
  if (isRecurInput || autoRenewInput) {
    if (ppRadios.length > 1) {
      debug("Found a choice of payment processors as radios");
      // We have radio inputs to select the processor.
      [].forEach.call(ppRadios, function(r) {
        r.addEventListener('click', gcFixRecurFromRadios);
      });

      gcFixRecurFromRadios();
      // Look out for when it changes.
      window.setInterval(gcFixRecurFromRadios, 500);
    }
    else {
      var ppInput = document.querySelectorAll('input[type="hidden"][name="payment_processor_id"]');
      if (ppInput.length === 1) {
        // We have a single payment processor involved that won't be changing.
        var ppID = parseInt(ppInput[0].value);
        goCardlessProcessorSelected = (goCardlessProcessorIDs.indexOf(ppID) > -1);
        if (goCardlessProcessorSelected) {
          forceRecurring();
        }
        debug("Determined whether GoCardless is the processor:", goCardlessProcessorSelected);
      }
      // else: no idea, let's do nothing.
    }
  }

});
