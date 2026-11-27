-- =====================================================================
--  FINANCIERA - Contabilidad: datos de ejemplo (base de prueba)
--
--  SOLO PARA LA BASE DE PRUEBA, como financiera_datos_ficticios.sql. Vacía
--  la contabilidad (asientos, períodos, ejercicios y plan de cuentas) y
--  carga:
--    - un plan de cuentas de ejemplo para una SRL que da préstamos
--      (152 cuentas, máscara 9.9.99.999): caja y bancos, créditos por
--      préstamos, aportes de inversores, IVA, comisiones, intereses y
--      punitorios ganados, gastos de otorgamiento, cuota social, gastos.
--      Es un borrador para probar la parametrización y los asientos; el
--      definitivo lo da el estudio contable (migracion\plan_cuentas_
--      financiera_srl.csv, con la columna «uso» de cada cuenta).
--    - el Ejercicio 2026 (1/1 al 31/12), de trabajo, con sus 12 meses
--      abiertos, a nombre de la empresa (tabla empresa).
--
--    - la cuenta contable de las cuentas de fondos y de los conceptos de
--      gasto, y las imputaciones generales de cada clase de operación.
--
--  Correr DESPUÉS de financiera_contabilidad.sql y
--  financiera_contabilidad_imputaciones.sql. Se puede correr de nuevo.
--  No tiene DELIMITER: va por DBeaver (Alt+X) o por CMD.
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Limpieza ------------------------------------------------------------
UPDATE cuentas_fondos SET id_cuenta_contable = NULL;
UPDATE gastos_conceptos SET id_cuenta_contable = NULL;
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE c_imputaciones_config;
TRUNCATE TABLE c_asientos_origen;
TRUNCATE TABLE c_asiento_detalle;
TRUNCATE TABLE c_asientos;
TRUNCATE TABLE c_periodos;
TRUNCATE TABLE c_ejercicios;
TRUNCATE TABLE c_plan_de_cuentas;
SET FOREIGN_KEY_CHECKS = 1;

DELETE FROM c_clasificacion_plan_cuentas WHERE codigo > 5;
INSERT INTO c_clasificacion_plan_cuentas (codigo, clasificacion, naturaleza, tipo) VALUES
 (1, 'ACTIVO', 'deudora', 'activo'), (2, 'PASIVO', 'acreedora', 'pasivo'),
 (3, 'PATRIMONIO NETO', 'acreedora', 'patrimonio'), (4, 'INGRESOS', 'acreedora', 'ingreso'),
 (5, 'EGRESOS', 'deudora', 'egreso')
ON DUPLICATE KEY UPDATE clasificacion = VALUES(clasificacion), naturaleza = VALUES(naturaleza), tipo = VALUES(tipo);

INSERT INTO parametros (clave, valor, descripcion) VALUES
 ('CONTABLE_MASCARA', '9.9.99.999', 'Máscara del código de cuenta contable: 9 = dígito, lo demás separador (ej. 9.9.99.999)')
ON DUPLICATE KEY UPDATE valor = VALUES(valor);

-- 2. Plan de cuentas -----------------------------------------------------
--    Con el padre por código; nivel, imputable (último tramo distinto de
--    cero) y corriente calculados con las reglas de inc\contable.php.
DROP TEMPORARY TABLE IF EXISTS tmp_plan;
CREATE TEMPORARY TABLE tmp_plan (
    codigo VARCHAR(20) NOT NULL PRIMARY KEY, nombre VARCHAR(150) NOT NULL, padre VARCHAR(20) NULL,
    nivel TINYINT UNSIGNED NOT NULL, imputable TINYINT(1) NOT NULL, corriente TINYINT(1) NOT NULL,
    clasif SMALLINT UNSIGNED NOT NULL
) DEFAULT CHARSET=utf8mb4;

INSERT INTO tmp_plan (codigo, nombre, padre, nivel, imputable, corriente, clasif) VALUES
 ('1.0.00.000','ACTIVO',NULL,1,0,0,1),
 ('1.1.00.000','ACTIVO CORRIENTE','1.0.00.000',2,0,1,1),
 ('1.1.01.000','CAJA Y BANCOS','1.1.00.000',3,0,1,1),
 ('1.1.01.001','CAJA EN PESOS','1.1.01.000',4,1,1,1),
 ('1.1.01.002','CAJA EN DOLARES','1.1.01.000',4,1,1,1),
 ('1.1.01.003','BANCO CUENTA CORRIENTE EN PESOS','1.1.01.000',4,1,1,1),
 ('1.1.01.004','BANCO CAJA DE AHORRO EN PESOS','1.1.01.000',4,1,1,1),
 ('1.1.01.005','BILLETERAS VIRTUALES','1.1.01.000',4,1,1,1),
 ('1.1.01.006','VALORES A DEPOSITAR','1.1.01.000',4,1,1,1),
 ('1.1.01.007','FONDOS EN TRANSITO','1.1.01.000',4,1,1,1),
 ('1.1.02.000','INVERSIONES TEMPORARIAS','1.1.00.000',3,0,1,1),
 ('1.1.02.001','PLAZOS FIJOS','1.1.02.000',4,1,1,1),
 ('1.1.02.002','FONDOS COMUNES DE INVERSION','1.1.02.000',4,1,1,1),
 ('1.1.03.000','CREDITOS POR PRESTAMOS','1.1.00.000',3,0,1,1),
 ('1.1.03.001','DEUDORES POR PRESTAMOS','1.1.03.000',4,1,1,1),
 ('1.1.03.002','INTERESES A COBRAR','1.1.03.000',4,1,1,1),
 ('1.1.03.003','PUNITORIOS A COBRAR','1.1.03.000',4,1,1,1),
 ('1.1.03.004','DEUDORES EN GESTION JUDICIAL','1.1.03.000',4,1,1,1),
 ('1.1.03.005','PREVISION PARA INCOBRABLES','1.1.03.000',4,1,1,1),
 ('1.1.04.000','OTROS CREDITOS','1.1.00.000',3,0,1,1),
 ('1.1.04.001','DEUDORES VARIOS','1.1.04.000',4,1,1,1),
 ('1.1.04.002','ANTICIPOS A COMERCIALIZADORES','1.1.04.000',4,1,1,1),
 ('1.1.04.003','CHEQUES RECHAZADOS','1.1.04.000',4,1,1,1),
 ('1.1.04.004','GASTOS PAGADOS POR ADELANTADO','1.1.04.000',4,1,1,1),
 ('1.1.05.000','CREDITOS FISCALES','1.1.00.000',3,0,1,1),
 ('1.1.05.001','IVA CREDITO FISCAL','1.1.05.000',4,1,1,1),
 ('1.1.05.002','IVA SALDO A FAVOR','1.1.05.000',4,1,1,1),
 ('1.1.05.003','RETENCIONES Y PERCEPCIONES DE IVA SUFRIDAS','1.1.05.000',4,1,1,1),
 ('1.1.05.004','RETENCIONES Y PERCEPCIONES DE IIBB SUFRIDAS','1.1.05.000',4,1,1,1),
 ('1.1.05.005','ANTICIPOS DE IMPUESTO A LAS GANANCIAS','1.1.05.000',4,1,1,1),
 ('1.1.05.006','IMPUESTO A LOS DEBITOS Y CREDITOS A CUENTA','1.1.05.000',4,1,1,1),
 ('1.2.00.000','ACTIVO NO CORRIENTE','1.0.00.000',2,0,0,1),
 ('1.2.01.000','CREDITOS POR PRESTAMOS NO CORRIENTES','1.2.00.000',3,0,0,1),
 ('1.2.01.001','DEUDORES POR PRESTAMOS NO CORRIENTES','1.2.01.000',4,1,0,1),
 ('1.2.02.000','BIENES DE USO','1.2.00.000',3,0,0,1),
 ('1.2.02.001','INSTALACIONES','1.2.02.000',4,1,0,1),
 ('1.2.02.002','AMORTIZACION ACUMULADA INSTALACIONES','1.2.02.000',4,1,0,1),
 ('1.2.02.003','MUEBLES Y UTILES','1.2.02.000',4,1,0,1),
 ('1.2.02.004','AMORTIZACION ACUMULADA MUEBLES Y UTILES','1.2.02.000',4,1,0,1),
 ('1.2.02.005','EQUIPOS DE COMPUTACION','1.2.02.000',4,1,0,1),
 ('1.2.02.006','AMORTIZACION ACUMULADA EQUIPOS DE COMPUTACION','1.2.02.000',4,1,0,1),
 ('1.2.02.007','RODADOS','1.2.02.000',4,1,0,1),
 ('1.2.02.008','AMORTIZACION ACUMULADA RODADOS','1.2.02.000',4,1,0,1),
 ('1.2.03.000','ACTIVOS INTANGIBLES','1.2.00.000',3,0,0,1),
 ('1.2.03.001','SOFTWARE','1.2.03.000',4,1,0,1),
 ('1.2.03.002','AMORTIZACION ACUMULADA SOFTWARE','1.2.03.000',4,1,0,1),
 ('2.0.00.000','PASIVO',NULL,1,0,0,2),
 ('2.1.00.000','PASIVO CORRIENTE','2.0.00.000',2,0,1,2),
 ('2.1.01.000','DEUDAS COMERCIALES','2.1.00.000',3,0,1,2),
 ('2.1.01.001','PROVEEDORES','2.1.01.000',4,1,1,2),
 ('2.1.01.002','COMISIONES A PAGAR A COMERCIALIZADORES','2.1.01.000',4,1,1,2),
 ('2.1.02.000','DEUDAS FINANCIERAS','2.1.00.000',3,0,1,2),
 ('2.1.02.001','APORTES DE INVERSORES EN PESOS','2.1.02.000',4,1,1,2),
 ('2.1.02.002','APORTES DE INVERSORES EN DOLARES','2.1.02.000',4,1,1,2),
 ('2.1.02.003','INTERESES A PAGAR A INVERSORES','2.1.02.000',4,1,1,2),
 ('2.1.02.004','PRESTAMOS BANCARIOS','2.1.02.000',4,1,1,2),
 ('2.1.03.000','REMUNERACIONES Y CARGAS SOCIALES','2.1.00.000',3,0,1,2),
 ('2.1.03.001','SUELDOS A PAGAR','2.1.03.000',4,1,1,2),
 ('2.1.03.002','CARGAS SOCIALES A PAGAR','2.1.03.000',4,1,1,2),
 ('2.1.03.003','PROVISION SAC Y VACACIONES','2.1.03.000',4,1,1,2),
 ('2.1.04.000','DEUDAS FISCALES','2.1.00.000',3,0,1,2),
 ('2.1.04.001','IVA DEBITO FISCAL','2.1.04.000',4,1,1,2),
 ('2.1.04.002','IVA A PAGAR','2.1.04.000',4,1,1,2),
 ('2.1.04.003','IMPUESTO A LAS GANANCIAS A PAGAR','2.1.04.000',4,1,1,2),
 ('2.1.04.004','INGRESOS BRUTOS A PAGAR','2.1.04.000',4,1,1,2),
 ('2.1.04.005','RETENCIONES Y PERCEPCIONES A DEPOSITAR','2.1.04.000',4,1,1,2),
 ('2.1.04.006','TASAS MUNICIPALES A PAGAR','2.1.04.000',4,1,1,2),
 ('2.1.05.000','OTRAS DEUDAS','2.1.00.000',3,0,1,2),
 ('2.1.05.001','PRESTAMOS A DESEMBOLSAR','2.1.05.000',4,1,1,2),
 ('2.1.05.002','COBRANZAS A IMPUTAR','2.1.05.000',4,1,1,2),
 ('2.1.05.003','SALDOS A DEVOLVER A CLIENTES','2.1.05.000',4,1,1,2),
 ('2.1.05.004','ACREEDORES VARIOS','2.1.05.000',4,1,1,2),
 ('2.2.00.000','PASIVO NO CORRIENTE','2.0.00.000',2,0,0,2),
 ('2.2.01.000','DEUDAS FINANCIERAS NO CORRIENTES','2.2.00.000',3,0,0,2),
 ('2.2.01.001','APORTES DE INVERSORES NO CORRIENTES','2.2.01.000',4,1,0,2),
 ('2.2.02.000','PREVISIONES','2.2.00.000',3,0,0,2),
 ('2.2.02.001','PREVISION PARA JUICIOS','2.2.02.000',4,1,0,2),
 ('3.0.00.000','PATRIMONIO NETO',NULL,1,0,0,3),
 ('3.1.00.000','APORTES DE LOS SOCIOS','3.0.00.000',2,0,0,3),
 ('3.1.01.000','CAPITAL','3.1.00.000',3,0,0,3),
 ('3.1.01.001','CAPITAL SOCIAL','3.1.01.000',4,1,0,3),
 ('3.1.01.002','AJUSTE DE CAPITAL','3.1.01.000',4,1,0,3),
 ('3.1.01.003','APORTES IRREVOCABLES','3.1.01.000',4,1,0,3),
 ('3.2.00.000','RESULTADOS ACUMULADOS','3.0.00.000',2,0,0,3),
 ('3.2.01.000','RESERVAS','3.2.00.000',3,0,0,3),
 ('3.2.01.001','RESERVA LEGAL','3.2.01.000',4,1,0,3),
 ('3.2.01.002','RESERVA FACULTATIVA','3.2.01.000',4,1,0,3),
 ('3.2.02.000','RESULTADOS NO ASIGNADOS','3.2.00.000',3,0,0,3),
 ('3.2.02.001','RESULTADOS NO ASIGNADOS','3.2.02.000',4,1,0,3),
 ('3.2.02.002','RESULTADO DEL EJERCICIO','3.2.02.000',4,1,0,3),
 ('3.2.02.003','AJUSTE DE RESULTADOS DE EJERCICIOS ANTERIORES','3.2.02.000',4,1,0,3),
 ('4.0.00.000','INGRESOS',NULL,1,0,0,4),
 ('4.1.00.000','INGRESOS FINANCIEROS','4.0.00.000',2,0,0,4),
 ('4.1.01.000','INTERESES POR PRESTAMOS','4.1.00.000',3,0,0,4),
 ('4.1.01.001','INTERESES GANADOS POR PRESTAMOS','4.1.01.000',4,1,0,4),
 ('4.1.01.002','PUNITORIOS GANADOS','4.1.01.000',4,1,0,4),
 ('4.1.01.003','QUITAS Y BONIFICACIONES DE INTERESES','4.1.01.000',4,1,0,4),
 ('4.1.02.000','OTROS INGRESOS FINANCIEROS','4.1.00.000',3,0,0,4),
 ('4.1.02.001','RENTAS DE INVERSIONES TEMPORARIAS','4.1.02.000',4,1,0,4),
 ('4.1.02.002','DIFERENCIA DE CAMBIO POSITIVA','4.1.02.000',4,1,0,4),
 ('4.2.00.000','INGRESOS POR SERVICIOS','4.0.00.000',2,0,0,4),
 ('4.2.01.000','CARGOS AL CLIENTE','4.2.00.000',3,0,0,4),
 ('4.2.01.001','GASTOS DE OTORGAMIENTO','4.2.01.000',4,1,0,4),
 ('4.2.01.002','CUOTA SOCIAL Y CARGOS ADMINISTRATIVOS','4.2.01.000',4,1,0,4),
 ('4.2.01.003','RECUPERO DE GASTOS DE COBRANZA','4.2.01.000',4,1,0,4),
 ('4.3.00.000','OTROS INGRESOS','4.0.00.000',2,0,0,4),
 ('4.3.01.000','INGRESOS VARIOS','4.3.00.000',3,0,0,4),
 ('4.3.01.001','RECUPERO DE CREDITOS INCOBRABLES','4.3.01.000',4,1,0,4),
 ('4.3.01.002','INGRESOS VARIOS','4.3.01.000',4,1,0,4),
 ('5.0.00.000','EGRESOS',NULL,1,0,0,5),
 ('5.1.00.000','EGRESOS FINANCIEROS','5.0.00.000',2,0,0,5),
 ('5.1.01.000','INTERESES PAGADOS','5.1.00.000',3,0,0,5),
 ('5.1.01.001','INTERESES A INVERSORES','5.1.01.000',4,1,0,5),
 ('5.1.01.002','INTERESES BANCARIOS','5.1.01.000',4,1,0,5),
 ('5.1.01.003','INTERESES FISCALES','5.1.01.000',4,1,0,5),
 ('5.1.02.000','OTROS EGRESOS FINANCIEROS','5.1.00.000',3,0,0,5),
 ('5.1.02.001','GASTOS Y COMISIONES BANCARIAS','5.1.02.000',4,1,0,5),
 ('5.1.02.002','DIFERENCIA DE CAMBIO NEGATIVA','5.1.02.000',4,1,0,5),
 ('5.2.00.000','GASTOS DE COMERCIALIZACION','5.0.00.000',2,0,0,5),
 ('5.2.01.000','GASTOS DE COMERCIALIZACION','5.2.00.000',3,0,0,5),
 ('5.2.01.001','COMISIONES A COMERCIALIZADORES','5.2.01.000',4,1,0,5),
 ('5.2.01.002','PUBLICIDAD','5.2.01.000',4,1,0,5),
 ('5.2.01.003','INFORMES COMERCIALES','5.2.01.000',4,1,0,5),
 ('5.2.01.004','GASTOS DE COBRANZA','5.2.01.000',4,1,0,5),
 ('5.2.01.005','FIRMA DIGITAL','5.2.01.000',4,1,0,5),
 ('5.3.00.000','GASTOS DE ADMINISTRACION','5.0.00.000',2,0,0,5),
 ('5.3.01.000','GASTOS DE PERSONAL','5.3.00.000',3,0,0,5),
 ('5.3.01.001','SUELDOS Y JORNALES','5.3.01.000',4,1,0,5),
 ('5.3.01.002','CARGAS SOCIALES','5.3.01.000',4,1,0,5),
 ('5.3.01.003','SUELDO ANUAL COMPLEMENTARIO','5.3.01.000',4,1,0,5),
 ('5.3.02.000','SERVICIOS Y GASTOS GENERALES','5.3.00.000',3,0,0,5),
 ('5.3.02.001','HONORARIOS PROFESIONALES','5.3.02.000',4,1,0,5),
 ('5.3.02.002','ALQUILERES','5.3.02.000',4,1,0,5),
 ('5.3.02.003','SERVICIOS PUBLICOS','5.3.02.000',4,1,0,5),
 ('5.3.02.004','TELEFONIA E INTERNET','5.3.02.000',4,1,0,5),
 ('5.3.02.005','SISTEMAS Y SOFTWARE','5.3.02.000',4,1,0,5),
 ('5.3.02.006','PAPELERIA Y UTILES','5.3.02.000',4,1,0,5),
 ('5.3.02.007','SEGUROS','5.3.02.000',4,1,0,5),
 ('5.3.02.008','GASTOS VARIOS','5.3.02.000',4,1,0,5),
 ('5.3.03.000','AMORTIZACIONES','5.3.00.000',3,0,0,5),
 ('5.3.03.001','AMORTIZACION DE BIENES DE USO','5.3.03.000',4,1,0,5),
 ('5.3.03.002','AMORTIZACION DE ACTIVOS INTANGIBLES','5.3.03.000',4,1,0,5),
 ('5.4.00.000','IMPUESTOS Y TASAS','5.0.00.000',2,0,0,5),
 ('5.4.01.000','IMPUESTOS Y TASAS','5.4.00.000',3,0,0,5),
 ('5.4.01.001','INGRESOS BRUTOS','5.4.01.000',4,1,0,5),
 ('5.4.01.002','IMPUESTO A LOS DEBITOS Y CREDITOS','5.4.01.000',4,1,0,5),
 ('5.4.01.003','TASAS MUNICIPALES','5.4.01.000',4,1,0,5),
 ('5.4.01.004','IMPUESTO A LAS GANANCIAS','5.4.01.000',4,1,0,5),
 ('5.5.00.000','CARGOS POR INCOBRABILIDAD','5.0.00.000',2,0,0,5),
 ('5.5.01.000','CARGOS POR INCOBRABILIDAD','5.5.00.000',3,0,0,5),
 ('5.5.01.001','CARGO POR PREVISION PARA INCOBRABLES','5.5.01.000',4,1,0,5),
 ('5.5.01.002','DEUDORES INCOBRABLES','5.5.01.000',4,1,0,5);

INSERT INTO c_plan_de_cuentas (id_clasificacion, codigo, nombre, nivel, permite_movimiento, activa, es_monetario, corriente)
SELECT clasif, codigo, nombre, nivel, imputable, 1,
       -- monetarias: disponibilidades, créditos y deudas en pesos
       IF(imputable = 1 AND (codigo LIKE '1.1.%' OR codigo LIKE '1.2.01.%' OR codigo LIKE '2.%'), 1, 0),
       corriente
  FROM tmp_plan ORDER BY codigo;

UPDATE c_plan_de_cuentas h
  JOIN tmp_plan t ON t.codigo = h.codigo
  JOIN c_plan_de_cuentas p ON p.codigo = t.padre
   SET h.id_cuenta_padre = p.id_cuenta;

DROP TEMPORARY TABLE tmp_plan;

-- 3. Ejercicio 2026 con sus meses ----------------------------------------
INSERT INTO c_ejercicios (nombre, cuit, razon_social, fecha_inicio, fecha_fin, estado, activo, observaciones)
SELECT 'Ejercicio 2026', (SELECT cuit FROM empresa LIMIT 1), (SELECT razon_social FROM empresa LIMIT 1),
       '2026-01-01', '2026-12-31', 'abierto', 1, 'Datos de ejemplo';

INSERT INTO c_periodos (id_ejercicio, nombre, fecha_inicio, fecha_fin, estado)
WITH RECURSIVE meses (m) AS (SELECT 1 UNION ALL SELECT m + 1 FROM meses WHERE m < 12)
SELECT e.id_ejercicio,
       CONCAT(ELT(m, 'Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'), ' 2026'),
       MAKEDATE(2026, 1) + INTERVAL (m - 1) MONTH,
       LAST_DAY(MAKEDATE(2026, 1) + INTERVAL (m - 1) MONTH),
       'abierto'
  FROM meses JOIN c_ejercicios e ON e.nombre = 'Ejercicio 2026';


-- 4. Cuentas de los maestros e imputaciones --------------------------------
--    En el plan de ejemplo hay una sola cuenta por clase de fondos: en el
--    real, cada cuenta bancaria lleva su propia subcuenta.
UPDATE cuentas_fondos f
  JOIN c_plan_de_cuentas c ON c.codigo = CASE
         WHEN f.tipo = 'C' AND f.moneda = 'USD' THEN '1.1.01.002'
         WHEN f.tipo = 'C' THEN '1.1.01.001'
         WHEN f.tipo = 'B' THEN '1.1.01.003'
         WHEN f.tipo = 'V' THEN '1.1.01.005'
         WHEN f.tipo = 'R' THEN '1.1.01.006'
         WHEN f.tipo = 'I' THEN '1.1.02.001'
         ELSE '1.1.01.001' END
   SET f.id_cuenta_contable = c.id_cuenta;

UPDATE gastos_conceptos g JOIN c_plan_de_cuentas c ON c.codigo = '4.2.01.001'
   SET g.id_cuenta_contable = c.id_cuenta;

INSERT INTO c_imputaciones_config (clase, subtipo, nombre, id_cuenta_debe, id_cuenta_haber)
SELECT x.clase, x.subtipo, x.nombre, d.id_cuenta, hb.id_cuenta
  FROM (SELECT 'LIQUIDACION' AS clase, '' AS subtipo, 'Liquidación de préstamos' AS nombre, '1.1.03.001' AS debe, '2.1.05.001' AS haber
        UNION ALL SELECT 'INTERES', '', 'Intereses de préstamos', NULL, '4.1.01.001'
        UNION ALL SELECT 'PUNITORIO', '', 'Punitorios', NULL, '4.1.01.002'
        UNION ALL SELECT 'IVA_DEBITO', '', 'IVA débito fiscal', NULL, '2.1.04.001'
        UNION ALL SELECT 'QUITA', '', 'Quitas por cancelación anticipada', '4.1.01.003', NULL
        UNION ALL SELECT 'CUOTA_SOCIAL', '', 'Cuota social', NULL, '4.2.01.002'
        UNION ALL SELECT 'COMISION', '', 'Comisiones de comercializadores', '5.2.01.001', '2.1.01.002'
        UNION ALL SELECT 'APORTE', '', 'Aportes de inversores en pesos', NULL, '2.1.02.001'
        UNION ALL SELECT 'APORTE', 'USD', 'Aportes de inversores en dólares', NULL, '2.1.02.002'
        UNION ALL SELECT 'INTERES_INVERSOR', '', 'Intereses a inversores', '5.1.01.001', '2.1.02.003'
        UNION ALL SELECT 'COBRANZA_A_IMPUTAR', '', 'Cobranzas a imputar', NULL, '2.1.05.002') x
  LEFT JOIN c_plan_de_cuentas d  ON d.codigo  = x.debe
  LEFT JOIN c_plan_de_cuentas hb ON hb.codigo = x.haber;

-- 5. Control ---------------------------------------------------------------
SELECT cl.clasificacion AS rubro, COUNT(*) AS cuentas, SUM(pc.permite_movimiento) AS imputables,
       SUM(pc.id_cuenta_padre IS NULL AND pc.nivel > 1) AS sin_padre
  FROM c_plan_de_cuentas pc JOIN c_clasificacion_plan_cuentas cl ON cl.codigo = pc.id_clasificacion
 GROUP BY cl.codigo, cl.clasificacion ORDER BY cl.codigo;
SELECT e.nombre, e.fecha_inicio, e.fecha_fin, COUNT(p.id_periodo) AS periodos
  FROM c_ejercicios e LEFT JOIN c_periodos p ON p.id_ejercicio = e.id_ejercicio GROUP BY e.id_ejercicio;
SELECT 'sin cuenta contable' AS control, nombre FROM cuentas_fondos WHERE id_cuenta_contable IS NULL
UNION ALL SELECT 'sin cuenta contable', nombre FROM gastos_conceptos WHERE id_cuenta_contable IS NULL;
