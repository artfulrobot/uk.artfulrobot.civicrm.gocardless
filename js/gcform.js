// This ensures that the is_recur check box is always checked if a GoCardless payment processor is selected.
//
// It does this by controlling the is_recur and auto_renew checkbox inputs.
//
// - Contribution forms with just the GoCardless payment processor (PP) enabled
//   have a hidden input with the PP ID.
//
// - Contribution forms with a choice of PPs use radio buttons.
//

(function($, ts) {

  $(document).ajaxComplete(function(event, xhr, settings) {
    function isAJAXPaymentForm(url) {
      return (url.match("civicrm(\/|%2F)payment(\/|%2F)form") !== null) ||
        (url.match("civicrm(\/|\%2F)contact(\/|\%2F)view(\/|\%2F)participant") !== null) ||
        (url.match("civicrm(\/|\%2F)contact(\/|\%2F)view(\/|\%2F)membership") !== null) ||
        (url.match("civicrm(\/|\%2F)contact(\/|\%2F)view(\/|\%2F)contribution") !== null);
    }
    // /civicrm/payment/form? occurs when a payproc is selected on page
    // /civicrm/contact/view/participant occurs when payproc is first loaded on event credit card payment
    // On wordpress these are urlencoded
    if (isAJAXPaymentForm(settings.url)) {
      load();
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    load();
  });

  function load() {
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
      isRecurInput.addEventListener('change', function (e) {
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
      autoRenewInput.addEventListener('change', function (e) {
        if (!autoRenewInput.checked && goCardlessProcessorSelected) {
          e.preventDefault();
          e.stopPropagation();
          forceRecurring(true);
        }
      });
      debug("Added event listener to autoRenew", autoRenewInput);
    }
    else if (document.getElementById('force_renew')) {
      forceRecurring(false);
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

      setRecurStartDate();
    }

    function hideStartDate() {
      $('#gc-recurring-start-date').hide();
      $("#gc-recurring-start-date option:selected").prop("selected", false);
      $("#gc-recurring-start-date option:first").prop("selected", "selected");
      $('#recur-start-date-description').remove();
    }

    function setRecurStartDate() {
      var recurSection = '.is_recur-section';
      if (!$(recurSection).length) {
        recurSection = '#allow_auto_renew';
      }

      if ($('select#frequency_unit,input[name=is_recur_radio]').length > 0) {
        // core select element
        var selectedFrequencyUnit = $('select#frequency_unit').val();
        if (!selectedFrequencyUnit) {
          // recurringbuttons extension radio buttons
          selectedFrequencyUnit = $('input[name=is_recur_radio]:checked').val();
        }
        if ($.inArray(selectedFrequencyUnit, ['month', 'year']) < 0) {
          hideStartDate();
          return;
        }
      }

      var recurStartDateDiv = document.getElementById('gc-recurring-start-date');
      if (recurStartDateDiv) {
        recurStartDateDiv.remove();
      }

      var dayOfMonthSelect = document.createElement('select');
      dayOfMonthSelect.setAttribute('id', 'day_of_month');
      dayOfMonthSelect.setAttribute('name', 'day_of_month');
      dayOfMonthSelect.classList.add('crm-form-select');

      var dayOfMonthOptions = {};

      // Build the "day_of_month" select element and add to form
      var options = '';
      for (var key in dayOfMonthOptions) {
        if (dayOfMonthOptions.hasOwnProperty(key)) {
          options += '<option value="' + key + '">' + dayOfMonthOptions[key] + '</option>';
        }
      }
      dayOfMonthSelect.innerHTML = options;

      recurStartDateDiv = document.createElement('div');
      recurStartDateDiv.setAttribute('id', 'gc-recurring-start-date');
      var recurStartDateElement = document.createElement('div');
      recurStartDateElement.classList.add('crm-section', 'recurring-start-date');
      var recurStartDateLabel = document.createElement('div');
      recurStartDateLabel.classList.add('label');
      recurStartDateLabel.innerText = ts('Day of month');
      recurStartDateElement.appendChild(recurStartDateLabel);
      var recurStartDateContent = document.createElement('div');
      recurStartDateContent.classList.add('content');
      recurStartDateContent.appendChild(dayOfMonthSelect);
      recurStartDateElement.appendChild(recurStartDateLabel);
      recurStartDateElement.appendChild(recurStartDateContent);
      recurStartDateDiv.appendChild(recurStartDateElement);
      // Remove/insert the recur start date element just below the recur selections
      $(recurSection + ' #gc-recurring-start-date').remove();
      $(recurSection).after(recurStartDateDiv);

      if (Object.keys(dayOfMonthOptions).length === 1) {
        // We only have one option. No need to offer selection - just show the date
        $(dayOfMonthSelect).parent('div.content').prev('div.label').hide();
        $(dayOfMonthSelect).next('div.description').hide();
        $(dayOfMonthSelect).hide();
        $('#recur-start-date-description').remove();
        if ($(dayOfMonthSelect).val() !== '0') {
          var recurStartMessage = ts('Your direct debit will be collected on the %1 day of the month', {
            1: $(dayOfMonthSelect).text()
          });
          $(dayOfMonthSelect).after(
            '<div class="description" id="recur-start-date-description">' + recurStartMessage + '</div>'
          );
        }
      }
      $('#gc-recurring-start-date').show().val('');
    }

    // This function looks through payment processor selector radios,
    // set goCardlessProcessorSelected and if found, forceRecurring
    function gcFixRecurFromRadios() {
      var ppID;
      selectedProcessorName = null;
      [].forEach.call(ppRadios, function (r) {
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
        [].forEach.call(ppRadios, function (r) {
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
      window.showHideAutoRenew = function (memTypeId) {
        origShowHideAutoRenew(memTypeId);
        if (goCardlessProcessorSelected) {
          forceRecurring();
        }
      };
    }

    // Trigger when we change the frequency unit selector (eg. month, year) on recur
    CRM.$('select#frequency_unit').on('change', function() {
      setRecurStartDate();
    });

    CRM.$('input[name=is_recur_radio]').on('change', function() {
      setRecurStartDate();
    });

  }

})(CRM.$, CRM.ts('uk.artfulrobot.civicrm.gocardless'));
