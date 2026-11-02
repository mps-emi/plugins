-- disable the MPS payment provider
UPDATE payment_provider
   SET mps_api_key = NULL,
       mps_webhook_secret = NULL;
