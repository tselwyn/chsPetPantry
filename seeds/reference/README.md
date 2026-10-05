# Reference seed data

Put reference data here that the client has reviewed and that production needs before the first distribution day. Examples:

- sites, service-area ZIP codes;
- species, breeds, size bands with their pictures;
- the first allotment rule version;
- the product catalogue and barcodes;
- policy texts in English and Spanish;
- clinics and their species rules;
- intake questions.

`php bin/seed.php` loads every `*.sql` file here, in name order. Each file must be safe to run more than once: use `INSERT … ON DUPLICATE KEY UPDATE col = col`, and never `VALUES()` in the update clause (see plan §8).

Synthetic data for development goes in `seeds/dev/` instead. It is loaded only with `--dev`, and never in staging or prod.
