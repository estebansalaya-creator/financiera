-- =====================================================================
--  FINANCIERA - Conciliación bancaria de EJEMPLO (solo en una base de
--  prueba). Correr después de financiera_conciliacion.sql y de
--  financiera_contabilidad_demo.sql. Se puede correr más de una vez. No
--  tiene DELIMITER.
--
--  1. Vacía lo que haya de conciliaciones: reglas, conciliaciones, los
--     comprobantes bancarios y las transferencias que emitió la
--     conciliación (se BORRAN: es una base de prueba).
--  2. Asigna la cuenta contable de los conceptos de tesorería (plan de
--     ejemplo de financiera_contabilidad_demo.sql).
--  3. Arma un extracto de la cuenta corriente del Nación de junio a
--     octubre de 2026 con lo que el libro ya tiene (menos lo de los
--     últimos días de octubre, que queda «en tránsito»), más lo que el
--     banco cobra por su cuenta cada mes: mantenimiento, IVA, comisiones
--     de transferencias, el impuesto a los débitos y créditos y la
--     percepción de Ingresos Brutos. En octubre, una transferencia que
--     nadie identifica. Con saldo en cada renglón, como el ICBC.
--
--  Las reglas de clasificación quedan vacías a propósito: se cargan en
--  Tesorería › Clasificación de conceptos.
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Limpieza ----------------------------------------------------------
DELETE FROM conciliacion_libro;
DELETE FROM conciliacion_items;
DELETE FROM conciliacion_grupos;
UPDATE conciliaciones SET id_conc_anterior = NULL;
DELETE FROM conciliaciones;
DELETE FROM conc_reglas;
UPDATE extractos_bancarios SET conciliado = 0, id_conciliacion = NULL;

DELETE d FROM debitos_creditos_bancarios d JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
 WHERE m.id_comp_banco IS NOT NULL
    OR m.id_mov_cuentas IN (SELECT id_mov_cuentas FROM (SELECT id_mov_cuentas FROM movimientos_entre_cuentas WHERE comentario LIKE 'Conciliación %') x);
DELETE FROM movimientos_fondos
 WHERE id_comp_banco IS NOT NULL
    OR id_mov_cuentas IN (SELECT id_mov_cuentas FROM (SELECT id_mov_cuentas FROM movimientos_entre_cuentas WHERE comentario LIKE 'Conciliación %') x);
DELETE FROM movimientos_entre_cuentas WHERE comentario LIKE 'Conciliación %';
DELETE FROM comprobantes_bancarios;
DELETE FROM numeradores WHERE clave LIKE 'CB-%';

DELETE e FROM extractos_bancarios e JOIN extractos_importaciones i ON i.id_importacion = e.id_importacion
 WHERE i.archivo = 'demo_conciliacion_nacion.csv';
DELETE FROM extractos_importaciones WHERE archivo = 'demo_conciliacion_nacion.csv';

-- 2. Cuentas contables de los conceptos ---------------------------------
UPDATE conceptos_tesoreria t
  JOIN c_plan_de_cuentas c ON c.codigo = CASE t.codigo
        WHEN 'COMISION'   THEN '5.1.02.001'
        WHEN 'IVA'        THEN '1.1.05.001'
        WHEN 'IDC'        THEN '5.4.01.002'
        WHEN 'PERC_IVA'   THEN '1.1.05.003'
        WHEN 'SIRCREB'    THEN '1.1.05.004'
        WHEN 'INT_BANCO'  THEN '5.1.01.002'
        WHEN 'INT_GANADO' THEN '4.1.02.001'
        WHEN 'REINTEGRO'  THEN '4.3.01.002' END
   SET t.id_cuenta_contable = c.id_cuenta;

-- 3. El extracto --------------------------------------------------------
SET @cta = (SELECT id_cuenta_fondos FROM cuentas_fondos WHERE tipo = 'B' AND moneda = 'ARS'
             ORDER BY nombre LIKE 'Naci%' DESC, id_cuenta_fondos LIMIT 1);
SET @desde = '2026-06-01';
SET @hasta = '2026-10-31';
SET @transito = '2026-10-29';

INSERT INTO extractos_importaciones (id_cuenta_fondos, archivo, formato, resultado, motivo, fecha_desde, fecha_hasta)
VALUES (@cta, 'demo_conciliacion_nacion.csv', 'BNA_EMPRESAS', 'OK', 'Extracto de ejemplo para probar la conciliación', @desde, @hasta);
SET @imp = LAST_INSERT_ID();

-- Lo que el libro ya tiene, como lo cuenta el banco
INSERT INTO extractos_bancarios (id_cuenta_fondos, id_importacion, fecha, referencia, debito, credito, concepto, origen)
SELECT @cta, @imp, m.fecha,
       COALESCE(NULLIF(d.nro_referencia, ''), LPAD(d.id_deb_cred * 7919 MOD 100000000, 8, '0')),
       NULLIF(d.importe_debito, 0), NULLIF(d.importe_credito, 0),
       CASE
         WHEN m.tipo_origen = 'MOVIMIENTO' AND d.tipo_instrumento = 'DEPOSITO' THEN 'DEPOSITO EN EFECTIVO'
         WHEN m.tipo_origen = 'MOVIMIENTO' AND d.importe_credito > 0 THEN 'TRANSFERENCIA ENTRE CUENTAS PROPIAS'
         WHEN m.tipo_origen = 'MOVIMIENTO' THEN 'TRANSFERENCIA ENTRE CUENTAS PROPIAS DEBITO'
         WHEN m.tipo_origen IN ('PAGO_OP', 'DESEMBOLSO') THEN 'TRANSFERENCIA INTERBANKING'
         WHEN m.tipo_origen = 'PAGO_INVERSOR' THEN 'TRANSFERENCIA A TERCEROS'
         WHEN m.tipo_origen IN ('RECIBO', 'COBRANZA') AND d.tipo_instrumento = 'DEPOSITO' THEN 'DEPOSITO EN EFECTIVO'
         WHEN m.tipo_origen IN ('RECIBO', 'COBRANZA') THEN 'CREDITO COBRANZA EXTRABANCARIA'
         ELSE 'TRANSFERENCIA RECIBIDA'
       END,
       'BNA'
  FROM debitos_creditos_bancarios d
  JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
 WHERE m.id_cuenta_fondos = @cta AND m.anulado_el IS NULL AND d.estado <> 'ANULADO'
   AND m.fecha BETWEEN @desde AND @hasta AND m.fecha < @transito
 ORDER BY m.fecha, d.id_deb_cred;

-- Lo que el banco cobra por su cuenta, el último día de cada mes
INSERT INTO extractos_bancarios (id_cuenta_fondos, id_importacion, fecha, referencia, debito, credito, concepto, origen)
SELECT @cta, @imp, x.fecha, x.ref, x.debito, NULL, x.concepto, 'BNA'
  FROM (
    SELECT LAST_DAY(fecha) AS fecha, CONCAT('MC', DATE_FORMAT(MAX(fecha), '%y%m')) AS ref, 18500.00 AS debito,
           'COMISION MANTENIMIENTO CUENTA' AS concepto, 1 AS o
      FROM extractos_bancarios WHERE id_importacion = @imp GROUP BY LAST_DAY(fecha)
    UNION ALL
    SELECT LAST_DAY(fecha), CONCAT('IV', DATE_FORMAT(MAX(fecha), '%y%m')), ROUND((18500 + COUNT(*) * 950) * 0.21, 2),
           'IVA TASA GENERAL', 2
      FROM extractos_bancarios WHERE id_importacion = @imp AND debito IS NOT NULL GROUP BY LAST_DAY(fecha)
    UNION ALL
    SELECT LAST_DAY(fecha), CONCAT('CT', DATE_FORMAT(MAX(fecha), '%y%m')), COUNT(*) * 950.00,
           'COMISION TRANSFERENCIAS', 3
      FROM extractos_bancarios WHERE id_importacion = @imp AND debito IS NOT NULL GROUP BY LAST_DAY(fecha)
    UNION ALL
    SELECT LAST_DAY(fecha), CONCAT('ID', DATE_FORMAT(MAX(fecha), '%y%m')), ROUND(SUM(debito) * 0.006, 2),
           'IMPUESTO LEY 25413 S/DEBITOS', 4
      FROM extractos_bancarios WHERE id_importacion = @imp AND debito IS NOT NULL GROUP BY LAST_DAY(fecha)
    UNION ALL
    SELECT LAST_DAY(fecha), CONCAT('IC', DATE_FORMAT(MAX(fecha), '%y%m')), ROUND(SUM(credito) * 0.006, 2),
           'IMPUESTO LEY 25413 S/CREDITOS', 5
      FROM extractos_bancarios WHERE id_importacion = @imp AND credito IS NOT NULL GROUP BY LAST_DAY(fecha)
    UNION ALL
    SELECT LAST_DAY(fecha), CONCAT('SC', DATE_FORMAT(MAX(fecha), '%y%m')), ROUND(SUM(credito) * 0.002, 2),
           'PERCEPCION IIBB SIRCREB', 6
      FROM extractos_bancarios WHERE id_importacion = @imp AND credito IS NOT NULL GROUP BY LAST_DAY(fecha)
  ) x
 ORDER BY x.fecha, x.o;

-- Una transferencia que nadie identifica (para probar las excepciones)
INSERT INTO extractos_bancarios (id_cuenta_fondos, id_importacion, fecha, referencia, debito, credito, concepto, origen)
VALUES (@cta, @imp, '2026-10-22', '77140022', NULL, 50000.00, 'TRANSFERENCIA RECIBIDA — CUIT 30716549871', 'BNA');

-- El saldo de cada renglón: lo que había antes del extracto más lo movido
SET @base = (SELECT COALESCE(SUM(m.importe_debe - m.importe_haber), 0) FROM movimientos_fondos m
              WHERE m.id_cuenta_fondos = @cta AND m.anulado_el IS NULL AND m.fecha < @desde);
UPDATE extractos_bancarios e
  JOIN (SELECT id_extracto,
               SUM(COALESCE(credito, 0) - COALESCE(debito, 0)) OVER (ORDER BY fecha, id_extracto) AS acum
          FROM extractos_bancarios WHERE id_importacion = @imp) s ON s.id_extracto = e.id_extracto
   SET e.saldo = ROUND(@base + s.acum, 2);

UPDATE extractos_importaciones i
   SET renglones      = (SELECT COUNT(*) FROM extractos_bancarios WHERE id_importacion = @imp),
       insertados     = (SELECT COUNT(*) FROM extractos_bancarios WHERE id_importacion = @imp),
       total_debitos  = (SELECT COALESCE(SUM(debito), 0) FROM extractos_bancarios WHERE id_importacion = @imp),
       total_creditos = (SELECT COALESCE(SUM(credito), 0) FROM extractos_bancarios WHERE id_importacion = @imp)
 WHERE i.id_importacion = @imp;
