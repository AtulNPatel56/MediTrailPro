USE meditrail_pro;

ALTER TABLE medications
    ADD COLUMN supply_count INT UNSIGNED NULL AFTER status;
