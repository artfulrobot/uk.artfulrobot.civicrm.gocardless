--
-- Copy the trxn_id to processor_id for GoCardless recurring payments.
-- Do not clobber any existing data (there should not be any)
--
UPDATE civicrm_contribution_recur cr
 INNER JOIN civicrm_payment_processor pp ON cr.payment_processor_id = pp.id
 INNER JOIN civicrm_payment_processor_type pt ON pp.payment_processor_type_id = pt.id

   SET cr.processor_id = cr.trxn_id

 WHERE pt.name = 'GoCardless'
   AND (cr.processor_id IS NULL OR cr.processor_id = '')
   AND (cr.trxn_id IS NOT NULL AND cr.trxn_id != '');
