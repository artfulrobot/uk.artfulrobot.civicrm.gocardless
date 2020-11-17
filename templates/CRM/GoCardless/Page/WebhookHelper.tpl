{if !$processors}
<p>{ts}No GoCardless processors are configured. Please visit:{/ts} 
<a href="{crmURL p='civicrm/admin/paymentProcessor' q="reset=1" }">{ts}Administer » CiviContribute » Payment Processors{/ts}</a></p>
{/if}
{foreach from=$processors key="id" item="pp"}
<article>
  <h2>{if $pp.is_active == 1}{ts}Active{/ts}{else}{ts}Inactive{/ts}{/if}: 
      <strong>{if $pp.is_test == 1}{ts}Test{/ts}{else}{ts}Live{/ts}{/if}</strong>
      "{$pp.name}"</h2>
  <p><em>{$pp.title}</em></p>
  <p>Webhook endpoint is:</p>
  <input type="text" value="{$pp.webhookUrl}" />
</article>
{/foreach}
