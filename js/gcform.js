// This ensures that the is_recur check box is always checked if a GoCardless payment processor is selected.
//
// It does this by controlling the is_recur and auto_renew checkbox inputs.
//
// - Contribution forms with just the GoCardless payment processor (PP) enabled
//   have a hidden input with the PP ID.
//
// - Contribution forms with a choice of PPs use radio buttons.
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
  // Note: the auto renew input might be a checkbox OR a hidden element.
  var autoRenewInput = document.getElementById('auto_renew');
  // If Civi offers a choice of payment processors by radio, they'll be found like this:
  var ppRadios = document.querySelectorAll('input[type="radio"][name="payment_processor_id"]');
  // Boolean: whether the currently selected payment processor is a GoCardless one.
  var goCardlessProcessorSelected = false;
  // The name of the selected GoCardless processor, or is empty.
  var selectedProcessorName = null;

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
        // They tried to un-check it, but GoCardless is selected.
        e.preventDefault();
        e.stopPropagation();
        forceRecurring(true);
      }
    });
    debug("Added event listener to isRecurInput", isRecurInput);
  }

  // Listen for when the user changes/tries to change the autoRenewInput checkbox
  if (autoRenewInput && autoRenewInput.getAttribute('type') === 'checkbox') {
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
    selectedProcessorName = null;
    [].forEach.call(ppRadios, function(r) {
      if (r.checked) {
        ppID = parseInt(r.value);
        var label = document.querySelector('label[for="' + r.id + '"]');
        selectedProcessorName = label ? label.textContent : 'Direct Debit';
      }
    });
    goCardlessProcessorSelected = (typeof ppID === 'number') && (goCardlessProcessorIDs.indexOf(ppID) > -1);

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
      // Look out for when it changes in a way we can't detect.
      window.setInterval(gcFixRecurFromRadios, 300);
    }
    else {
      // The processor type is fixed.
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

  if ('showHideAutoRenew' in window) {
    // This function is defined in templates/CRM/Contribute/Form/Contribution/MembershipBlock.tpl
    // and called by Civi in various places, including in onclick HTML attributes, defined in:
    // - CRM/Contribute/Form/ContributionBase.php
    // - CRM/Price/BAO/PriceField.php
    // Wrap this function to ensure we still forceRecurring if GC is selected.
    var origShowHideAutoRenew = window.showHideAutoRenew;
    window.showHideAutoRenew = function(memTypeId) {
      origShowHideAutoRenew(memTypeId);
      if (goCardlessProcessorSelected) {
        forceRecurring();
      }
    };
  }

});
