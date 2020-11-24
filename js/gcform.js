// This ensures that the is_recur check box is always checked if a GoCardless payment processor is selected.
//
// It does this by controling the is_recur checkbox input.
//
// - Contribution forms with just the GoCardless payment processor (PP) enabled
//   have a hidden input with the PP ID.
//
// - Contribution forms with a choice of PPs use radio buttons.
//
document.addEventListener('DOMContentLoaded', function () {
  // This next line gets swapped out by PHP
  var goCardlessProcessorIDs = [];

  var isRecurInput = document.getElementById('is_recur');
  if (!isRecurInput) {
    return;
  }
  var ppRadios = document.querySelectorAll('input[type="radio"][name="payment_processor_id"]');
  var goCardlessProcessorSelected;
  var selectedProcessorName;

  isRecurInput.addEventListener('change', function(e) {
    if (!isRecurInput.checked && goCardlessProcessorSelected) {
      e.preventDefault();
      e.stopPropagation();
      forceRecurring(true);
    }
  });

  function forceRecurring(withAlert) {
    isRecurInput.checked = true;

    if (withAlert) {
      if (selectedProcessorName) {
        alert("Contributions made with " + selectedProcessorName + " must be recurring.");
      }
      else {
        alert("Contributions must be recurring.");
      }
    }
    var fakeEvent = new Event('change');
    isRecurInput.dispatchEvent(fakeEvent);
  }

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

    if (goCardlessProcessorSelected) {
      forceRecurring();
    }
  }

  if (ppRadios.length > 1) {
    // We have radio inputs to select the processor.
    [].forEach.call(ppRadios, function(r) {
      r.addEventListener('click', gcFixRecurFromRadios);
    });

    gcFixRecurFromRadios();
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
    }
    // else: no idea, let's do nothing.
  }
});
