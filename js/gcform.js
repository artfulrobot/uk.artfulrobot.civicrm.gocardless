// This ensures that the is_recur check box is always checked if a GoCardless payment processor is selected.
document.addEventListener('DOMContentLoaded', function () {
  // This next line gets swapped out by PHP
  var goCardlessProcessorIDs = [];

  var isRecurInput = document.getElementById('is_recur');
  var ppRadios = document.querySelectorAll('input[name="payment_processor_id"]');
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
      alert("Contributions made with " + selectedProcessorName + " must be recurring.");
    }
    var fakeEvent = new Event('change');
    isRecurInput.dispatchEvent(fakeEvent);
  }

  function gcFixRecur() {
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

  [].forEach.call(ppRadios, function(r) {
    r.addEventListener('click', gcFixRecur);
  });

  gcFixRecur();

});
