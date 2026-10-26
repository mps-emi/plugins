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
<p class="help-block">{l s='Call this address every 5 minutes (a crontab line, or the Cron tasks manager module). It confirms orders whose shopper closed the tab before returning, on a shop MPS cannot notify.' d='Modules.Mpspayments.Admin'}</p>
<code>{$mps_cron_url|escape:'html':'UTF-8'}</code>
