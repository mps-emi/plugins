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
{if $mps_paid}
<p>{l s='Your payment was received.' d='Modules.Mpspayments.Shop'}</p>
{/if}
<p>{l s='Order reference:' d='Modules.Mpspayments.Shop'} <strong>{$mps_order_reference|escape:'html':'UTF-8'}</strong></p>
