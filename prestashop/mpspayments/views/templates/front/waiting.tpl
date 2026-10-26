{**
 * Copyright since 2026 Mobile Payment Solutions
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    Mobile Payment Solutions
 * @copyright Since 2026 Mobile Payment Solutions
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *}
{extends file='page.tpl'}

{block name='head' append}
  {if $mps_refresh_url}
    <meta http-equiv="refresh" content="4;url={$mps_refresh_url|escape:'html':'UTF-8'}">
  {/if}
{/block}

{block name='page_content'}
  <h1>{l s='We are confirming your payment' d='Modules.Mpspayments.Shop'}</h1>
  {if $mps_refresh_url}
    <p>{l s='This takes a few seconds. This page refreshes by itself.' d='Modules.Mpspayments.Shop'}</p>
  {else}
    <p>{l s='MPS has not confirmed your payment yet. You will receive an e-mail as soon as your order is confirmed; there is no need to pay again.' d='Modules.Mpspayments.Shop'}</p>
  {/if}
{/block}
