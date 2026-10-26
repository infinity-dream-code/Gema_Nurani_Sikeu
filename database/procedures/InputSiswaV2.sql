USE bekasi_sdit_gema_nurani;

DROP PROCEDURE IF EXISTS InputSiswaV2;

DELIMITER $$

CREATE PROCEDURE InputSiswaV2(
    IN p_NIMRAW    VARCHAR(32),
    IN p_NMCUSTRAW VARCHAR(70),
    IN p_DESC02    VARCHAR(50),
    IN p_CODE02    VARCHAR(50),
    IN p_CODE01    VARCHAR(10),
    IN p_DESC03    VARCHAR(50),
    IN p_DESC04    VARCHAR(50),
    IN p_DESC05    VARCHAR(250),
    IN p_CODE04    VARCHAR(5),
    IN p_GENUS     VARCHAR(50),
    IN p_CODE05    VARCHAR(5),
    IN p_NOVA      VARCHAR(32),
    IN p_EMAIL     VARCHAR(100),
    IN p_NUM2ND    VARCHAR(20)
)
main: BEGIN
    DECLARE v_NIM VARCHAR(32);
    DECLARE v_NMCUST VARCHAR(70);
    DECLARE v_DESC02 VARCHAR(50);
    DECLARE v_CODE02 VARCHAR(50);
    DECLARE v_CODE01 VARCHAR(10);
    DECLARE v_DESC03 VARCHAR(50);
    DECLARE v_DESC04 VARCHAR(50);
    DECLARE v_DESC05 VARCHAR(250);
    DECLARE v_CODE04 VARCHAR(5);
    DECLARE v_GENUS VARCHAR(50);
    DECLARE v_CODE05 VARCHAR(5);
    DECLARE v_NOVA VARCHAR(32);
    DECLARE v_EMAIL VARCHAR(100);
    DECLARE v_NUM2ND VARCHAR(20);
    DECLARE v_DESC01 VARCHAR(50);

    DECLARE v_isExistCust INT DEFAULT 0;
    DECLARE v_isExistAcaYear INT DEFAULT 0;
    DECLARE v_IDClass INT DEFAULT NULL;
    DECLARE v_userExist INT DEFAULT 0;
    DECLARE v_foundSchool INT DEFAULT 0;
    DECLARE v_nextSchool INT DEFAULT 1;
    DECLARE v_nextCustId BIGINT DEFAULT 1;
    DECLARE v_kelasNama VARCHAR(50);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    /* ========= NORMALIZE (jangan hapus leading zero: VA 13 digit) ========= */
    SET v_NIM = UPPER(TRIM(REPLACE(REPLACE(REPLACE(IFNULL(p_NIMRAW, ''), '''', ''), '`', ''), ' ', '')));
    SET v_NMCUST = UPPER(TRIM(REPLACE(REPLACE(IFNULL(p_NMCUSTRAW, ''), '''', ''), '`', '')));
    WHILE INSTR(v_NMCUST, '  ') > 0 DO
        SET v_NMCUST = REPLACE(v_NMCUST, '  ', ' ');
    END WHILE;

    SET v_DESC02 = UPPER(TRIM(IFNULL(p_DESC02, '')));
    WHILE INSTR(v_DESC02, '  ') > 0 DO SET v_DESC02 = REPLACE(v_DESC02, '  ', ' '); END WHILE;

    SET v_CODE02 = UPPER(TRIM(IFNULL(p_CODE02, '')));
    WHILE INSTR(v_CODE02, '  ') > 0 DO SET v_CODE02 = REPLACE(v_CODE02, '  ', ' '); END WHILE;

    SET v_DESC03 = UPPER(TRIM(IFNULL(p_DESC03, '')));
    WHILE INSTR(v_DESC03, '  ') > 0 DO SET v_DESC03 = REPLACE(v_DESC03, '  ', ' '); END WHILE;
    IF v_DESC03 = '' THEN SET v_DESC03 = '-'; END IF;

    SET v_DESC04 = UPPER(TRIM(IFNULL(p_DESC04, '')));
    WHILE INSTR(v_DESC04, '  ') > 0 DO SET v_DESC04 = REPLACE(v_DESC04, '  ', ' '); END WHILE;

    SET v_DESC05 = UPPER(TRIM(IFNULL(p_DESC05, '')));
    WHILE INSTR(v_DESC05, '  ') > 0 DO SET v_DESC05 = REPLACE(v_DESC05, '  ', ' '); END WHILE;

    SET v_CODE04 = UPPER(TRIM(IFNULL(p_CODE04, '')));
    SET v_GENUS = UPPER(TRIM(IFNULL(p_GENUS, '')));
    WHILE INSTR(v_GENUS, '  ') > 0 DO SET v_GENUS = REPLACE(v_GENUS, '  ', ' '); END WHILE;
    SET v_CODE05 = UPPER(TRIM(IFNULL(p_CODE05, '')));

    SET v_NOVA = TRIM(REPLACE(IFNULL(p_NOVA, ''), ' ', ''));
    IF v_NOVA = '' THEN SET v_NOVA = v_NIM; END IF;

    SET v_EMAIL = TRIM(IFNULL(p_EMAIL, ''));
    SET v_NUM2ND = TRIM(IFNULL(p_NUM2ND, ''));
    IF v_NUM2ND = '' THEN SET v_NUM2ND = '-'; END IF;

    SET v_CODE01 = UPPER(TRIM(IFNULL(p_CODE01, '')));
    IF v_CODE01 = '' AND v_CODE02 LIKE '%SDIT%' THEN
        SET v_CODE01 = 'SDIT';
    END IF;

    IF v_NIM = '' THEN
        ROLLBACK;
        LEAVE main;
    END IF;

    /* ========= SMART REF: mst_sekolah =========
       CODE01 dipakai scope admin (cyber_key.fid)
       DESC01 = nama unit
    */
    SET v_DESC01 = v_CODE02;
    SET v_foundSchool = 0;

    IF v_CODE01 <> '' THEN
        SELECT COUNT(*) INTO v_foundSchool
        FROM mst_sekolah
        WHERE UPPER(TRIM(CODE01)) = v_CODE01;

        IF v_foundSchool > 0 THEN
            SELECT CODE01, IFNULL(NULLIF(TRIM(DESC01), ''), v_CODE02)
            INTO v_CODE01, v_DESC01
            FROM mst_sekolah
            WHERE UPPER(TRIM(CODE01)) = v_CODE01
            LIMIT 1;
        END IF;
    END IF;

    IF v_foundSchool = 0 AND v_CODE02 <> '' THEN
        SELECT COUNT(*) INTO v_foundSchool
        FROM mst_sekolah
        WHERE UPPER(TRIM(DESC01)) = v_CODE02;

        IF v_foundSchool > 0 THEN
            SELECT CODE01, DESC01
            INTO v_CODE01, v_DESC01
            FROM mst_sekolah
            WHERE UPPER(TRIM(DESC01)) = v_CODE02
            LIMIT 1;
        END IF;
    END IF;

    IF v_foundSchool = 0 THEN
        IF v_CODE01 = '' OR v_CODE01 IS NULL THEN
            IF v_CODE02 LIKE '%SDIT%' THEN
                SET v_CODE01 = 'SDIT';
            ELSE
                SELECT IFNULL(MAX(CAST(CODE01 AS UNSIGNED)), 0) + 1
                INTO v_nextSchool
                FROM mst_sekolah
                WHERE CODE01 REGEXP '^[0-9]+$';

                SET v_CODE01 = LPAD(v_nextSchool, 3, '0');
            END IF;
        END IF;

        SET v_DESC01 = IF(v_CODE02 = '', v_CODE01, v_CODE02);

        INSERT INTO mst_sekolah (CODE01, DESC01)
        SELECT v_CODE01, v_DESC01
        FROM DUAL
        WHERE NOT EXISTS (
            SELECT 1 FROM mst_sekolah WHERE UPPER(TRIM(CODE01)) = v_CODE01
        );
    ELSEIF v_CODE02 <> '' THEN
        SET v_DESC01 = v_CODE02;
    END IF;

    /* ========= SMART REF: mst_thn_aka ========= */
    SELECT COUNT(*) INTO v_isExistAcaYear
    FROM mst_thn_aka
    WHERE UPPER(TRIM(thn_aka)) = v_DESC04;

    IF v_isExistAcaYear = 0 AND v_DESC04 <> '' THEN
        INSERT INTO mst_thn_aka (thn_aka)
        VALUES (v_DESC04);
    END IF;

    /* ========= SMART REF: mst_kelas =========
       Excel UNIT     = mst_kelas.unit
       Excel KELAS    = mst_kelas.jenjang
       Excel KELOMPOK = mst_kelas.kelas
       mst_kelas.kelompok = mst_sekolah.CODE01
    */
    SET v_kelasNama = IF(v_DESC03 IN ('', '-'), v_DESC02, v_DESC03);

    SELECT id INTO v_IDClass
    FROM mst_kelas
    WHERE UPPER(TRIM(unit)) = v_CODE02
      AND (
          UPPER(TRIM(kelompok)) = v_CODE01
          OR IFNULL(TRIM(kelompok), '') = ''
      )
      AND (
          UPPER(TRIM(jenjang)) = v_DESC02
          OR UPPER(TRIM(kelas)) = v_DESC02
          OR UPPER(TRIM(kelas)) = v_kelasNama
      )
    LIMIT 1;

    IF v_IDClass IS NULL THEN
        INSERT INTO mst_kelas (kelas, jenjang, unit, kelompok)
        VALUES (v_kelasNama, v_DESC02, v_CODE02, v_CODE01);

        SET v_IDClass = LAST_INSERT_ID();
        IF v_IDClass IS NULL OR v_IDClass = 0 THEN
            SELECT MAX(id) INTO v_IDClass FROM mst_kelas;
        END IF;
    ELSE
        UPDATE mst_kelas
        SET kelompok = v_CODE01
        WHERE id = v_IDClass
          AND (kelompok IS NULL OR TRIM(kelompok) = '');
    END IF;

    /* ========= UPSERT SCCTCUST ========= */
    SELECT COUNT(*) INTO v_isExistCust
    FROM SCCTCUST
    WHERE NOCUST = v_NIM
       OR (v_NOVA <> '' AND NOVA = v_NOVA);

    IF v_isExistCust = 0 THEN
        SELECT IFNULL(MAX(CUSTID), 0) + 1 INTO v_nextCustId FROM SCCTCUST;

        INSERT INTO SCCTCUST (
            CUSTID, NOCUST, NOVA, NUM2ND, NMCUST, EMAIL, STCUST,
            DESC01, DESC02, CODE02, CODE01, DESC03, DESC04, DESC05,
            CODE04, CODE03, GENUS, CODE05, LastUpdate
        ) VALUES (
            v_nextCustId, v_NIM, v_NOVA, v_NUM2ND, v_NMCUST, NULLIF(v_EMAIL, ''), 1,
            v_DESC01, v_DESC02, v_CODE02, v_CODE01, v_DESC03, v_DESC04, v_DESC05,
            v_CODE04, v_IDClass, v_GENUS, v_CODE05, NOW()
        );

        SELECT COUNT(*) INTO v_userExist FROM sm_user WHERE userlogin = v_NIM;
        IF v_userExist = 0 THEN
            INSERT INTO sm_user (userlogin, kunci, ket)
            VALUES (v_NIM, '40bd001563085fc35165329ea1ff5c5ecbdbbeef', 'Input');
        END IF;
    ELSE
        UPDATE SCCTCUST
        SET
            NMCUST = IF(v_NMCUST = '', NMCUST, v_NMCUST),
            NOVA = IF(v_NOVA = '', NOVA, v_NOVA),
            EMAIL = IF(v_EMAIL = '', EMAIL, v_EMAIL),
            NUM2ND = IF(v_NUM2ND IN ('', '-'), NUM2ND, v_NUM2ND),
            DESC01 = v_DESC01,
            DESC02 = v_DESC02,
            CODE02 = v_CODE02,
            CODE01 = v_CODE01,
            DESC03 = v_DESC03,
            DESC04 = v_DESC04,
            DESC05 = IF(v_DESC05 = '', DESC05, v_DESC05),
            CODE04 = IF(v_CODE04 = '', CODE04, v_CODE04),
            CODE03 = v_IDClass,
            GENUS  = IF(v_GENUS = '', GENUS, v_GENUS),
            CODE05 = IF(v_CODE05 = '', CODE05, v_CODE05),
            STCUST = 1,
            LastUpdate = NOW()
        WHERE NOCUST = v_NIM
           OR (v_NOVA <> '' AND NOVA = v_NOVA);
    END IF;

    COMMIT;
END$$

DELIMITER ;
