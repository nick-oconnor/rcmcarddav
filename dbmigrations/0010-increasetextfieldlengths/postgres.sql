ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN password TYPE TEXT;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN url TYPE TEXT;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN sync_token TYPE TEXT;
ALTER TABLE TABLE_PREFIXcarddav_addressbooks ALTER COLUMN sync_token SET DEFAULT '';

ALTER TABLE TABLE_PREFIXcarddav_contacts ALTER COLUMN email TYPE TEXT;
ALTER TABLE TABLE_PREFIXcarddav_contacts ALTER COLUMN uri TYPE TEXT;

ALTER TABLE TABLE_PREFIXcarddav_groups ALTER COLUMN uri TYPE TEXT;
