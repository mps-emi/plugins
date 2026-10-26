-- disable the MPS payment provider
UPDATE payment_provider
   SET mps_client_secret = NULL,
       mps_webhook_secret = NULL,
       mps_access_token = NULL,
       mps_token_expiry = NULL;
