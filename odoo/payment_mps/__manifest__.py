{
    "name": "Payment Provider: MPS",
    "version": "20.0.0.1.0",
    "category": "Accounting/Payment Providers",
    "sequence": 350,
    "summary": "Take card and banking-app payments on the MPS-hosted checkout."
    " Sends the order amount, number and return addresses to MPS (Mobile Payment Solutions).",
    "description": " ",  # Non-empty string to avoid loading the README file.
    "author": "Mobile Payment Solutions",
    "website": "https://www.mps-emi.com",
    "depends": ["payment"],
    "data": [
        "views/payment_provider_views.xml",
        "data/payment_provider_data.xml",
        "data/payment_method_data.xml",
        "data/ir_cron_data.xml",
    ],
    "post_init_hook": "post_init_hook",
    "uninstall_hook": "uninstall_hook",
    "license": "LGPL-3",
}
