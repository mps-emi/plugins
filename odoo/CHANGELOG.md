# Changelog

`build.sh` refuses to build a version without an entry here.

## 20.0.1.0.0

- First release on the Odoo Apps Store. The module is unchanged from 20.0.0.1.0; the store page now names
  the support address, ict@mps-emi.com.

## 20.0.0.1.0

- First release for Odoo 20: MPS as a payment provider on the MPS-hosted checkout, settled from the
  payment as MPS reports it — on return, on notification, and by scheduled reads for six hours.
- Notifications are ignored while no webhook signing secret is set on the provider.
- The provider form says what is sent to MPS, and that a test key accepts only the MPS test cards and
  charges nothing.
