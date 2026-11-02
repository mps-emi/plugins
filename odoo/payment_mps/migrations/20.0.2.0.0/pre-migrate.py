"""20.0.2.0.0 connects with the API key only. The client ID and secret of a provider set up before MPS
issued API keys, and the token they bought, go: Odoo leaves a removed field's data in place until it
drops the column, so they are erased here first, while the columns are still there.

A provider that had only those is taken off line (not live, not published): without an API key it can no
longer reach MPS, and the form says to paste one before it can be enabled again.

The MPS payment method is marked as refundable: its record is installed once (noupdate), and before
20.0.2.0.0 it said no refunds, so Odoo never showed the Refund button on an MPS payment."""

import logging

from odoo.tools import SQL
from odoo.tools.sql import column_exists

_logger = logging.getLogger(__name__)

REMOVED_COLUMNS = ("mps_client_id", "mps_client_secret", "mps_access_token", "mps_token_expiry")


def migrate(cr, version):
    present = [column for column in REMOVED_COLUMNS if column_exists(cr, "payment_provider", column)]
    if present:
        cr.execute(
            SQL(
                "UPDATE payment_provider SET %s",
                SQL(", ").join(SQL("%s = NULL", SQL.identifier(column)) for column in present),
            )
        )

    cr.execute(
        """
        UPDATE payment_provider
           SET is_live = FALSE, is_published = FALSE
         WHERE code = 'mps'
           AND (is_live OR is_published)
           AND COALESCE(mps_api_key, '') = ''
     RETURNING id
        """
    )
    for (provider_id,) in cr.fetchall():
        _logger.warning(
            "MPS provider %s had no API key: taken off line. Paste an API key to enable it again.",
            provider_id,
        )

    cr.execute(
        """
        UPDATE payment_method
           SET support_refund = 'partial'
         WHERE id IN (SELECT res_id FROM ir_model_data
                       WHERE module = 'payment_mps' AND name = 'payment_method_mps' AND model = 'payment.method')
        """
    )
