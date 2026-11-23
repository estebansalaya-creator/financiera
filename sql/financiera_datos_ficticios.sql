-- =====================================================================
--  FINANCIERA - Datos ficticios (base de prueba)
--
--  SOLO PARA LA BASE DE PRUEBA. Vacía TODOS los datos (clientes, préstamos,
--  cobranzas, cuota social, facturación, tesorería, inversiones, mensajes,
--  consultas al BCRA y a ARCA, log de errores) y reemplaza la configuración
--  que vino de Reggio por una inventada: empresa, tipos de asociado, tasas,
--  grilla, reparticiones, organismos, sucursales, zonas, vendedores, reglas
--  de comisión, valores de cuota social, gastos por tipo y los nombres de
--  telemarketing. Ningún nombre, CUIT ni CBU es de una persona real: los
--  CUIT y CBU verifican, pero son inventados.
--
--  Quedan como estaban: usuarios y sus permisos, menú, roles, parámetros,
--  bancos, cuentas de fondos, chequeras y talonarios (vuelven a empezar),
--  cotizaciones, catálogos de ARCA, tipos de documentación y de contacto,
--  motivos de seguimiento y los tickets de ARCA.
--
--  Lo que se carga, todo con los procedures del sistema:
--    30 clientes (22 personas y 8 empresas) en 5 tipos de asociado
--    20 préstamos de 3 a 60 cuotas, liquidados entre junio y noviembre de
--       2026: al día, en mora, con punitorios, un pago parcial, uno
--       cancelado por anticipado, tres con la grilla (Comercio) y dos con
--       la orden de pago sin pagar
--    los desembolsos, los cobros (caja, banco con su extracto, lotes de
--       Rapipago, un recibo anulado), los depósitos y pases de cada mes
--    4 inversores y 6 aportes
--    6 solicitudes sin liquidar: pendientes, observada, aprobada y rechazada
--
--  La cuota social y la facturación electrónica se generan después desde sus
--  pantallas. Los archivos ya subidos (documentación, adjuntos de mensajes)
--  quedan en disco: se pueden borrar a mano.
--
--  En DBeaver: Ejecutar script (Alt+X). Se puede correr de nuevo.
-- =====================================================================

DELIMITER $$

-- La limpieza y los datos de partida
DROP PROCEDURE IF EXISTS tmp_ej_carga $$
CREATE PROCEDURE tmp_ej_carga()
BEGIN
    -- 1. Limpieza: todos los datos --------------------------------------------
    SET FOREIGN_KEY_CHECKS = 0;
    TRUNCATE TABLE chat_mensajes;
    TRUNCATE TABLE chat_participantes;
    TRUNCATE TABLE chat_conversaciones;
    TRUNCATE TABLE consultas_bcra;
    TRUNCATE TABLE arca_constancia_actividades;
    TRUNCATE TABLE arca_constancia_impuestos;
    TRUNCATE TABLE arca_constancia;
    TRUNCATE TABLE arca_wsfe_log;
    TRUNCATE TABLE log_errores;
    TRUNCATE TABLE comprobantes_venta_items;
    TRUNCATE TABLE comprobantes_venta;
    TRUNCATE TABLE cuotas_sociales_pagos;
    TRUNCATE TABLE cuotas_sociales;
    TRUNCATE TABLE cuota_social_asociados;
    TRUNCATE TABLE cuota_social_valores;
    TRUNCATE TABLE clientes_observaciones;
    TRUNCATE TABLE contactos;
    TRUNCATE TABLE comisiones_importadas;
    TRUNCATE TABLE prestamos_gastos;
    TRUNCATE TABLE pagos_imputacion;
    TRUNCATE TABLE pagos;
    TRUNCATE TABLE recibos;
    TRUNCATE TABLE lotes_cobranza;
    TRUNCATE TABLE movimientos;
    TRUNCATE TABLE cuotas;
    TRUNCATE TABLE comisiones_liquidacion_detalle;
    TRUNCATE TABLE comisiones_liquidacion;
    TRUNCATE TABLE comisiones_devengadas;
    TRUNCATE TABLE inversiones_liquidaciones_detalle;
    TRUNCATE TABLE inversiones_liquidaciones;
    TRUNCATE TABLE inversiones_prestamos;
    TRUNCATE TABLE inversiones;
    TRUNCATE TABLE inversores;
    TRUNCATE TABLE transferencias_lotes_det;
    TRUNCATE TABLE transferencias_lotes;
    TRUNCATE TABLE pagos_emitidos;
    TRUNCATE TABLE ordenes_pago;
    TRUNCATE TABLE debitos_creditos_bancarios;
    TRUNCATE TABLE movimientos_fondos;
    TRUNCATE TABLE movimientos_entre_cuentas;
    TRUNCATE TABLE extractos_cliente;
    TRUNCATE TABLE cobros_cuil_reglas;
    TRUNCATE TABLE extractos_bancarios;
    TRUNCATE TABLE extractos_importaciones;
    TRUNCATE TABLE solicitudes_historial;
    TRUNCATE TABLE solicitudes;
    TRUNCATE TABLE prestamos;
    TRUNCATE TABLE clientes_documentos;
    TRUNCATE TABLE domicilios;
    TRUNCATE TABLE telefonos;
    TRUNCATE TABLE clientes_personas;
    TRUNCATE TABLE clientes_empresas;
    TRUNCATE TABLE clientes;
    TRUNCATE TABLE numeradores;
    -- La configuración que vino de Reggio
    TRUNCATE TABLE comision_reglas;
    TRUNCATE TABLE gastos_conceptos_tipos;
    TRUNCATE TABLE tasas_vigencia;
    TRUNCATE TABLE grillas_celdas;
    TRUNCATE TABLE grillas;
    TRUNCATE TABLE tipos_asociado;
    TRUNCATE TABLE vendedores;
    TRUNCATE TABLE zonas;
    TRUNCATE TABLE reparticiones;
    TRUNCATE TABLE organismos;
    TRUNCATE TABLE sucursales;
    SET FOREIGN_KEY_CHECKS = 1;
    UPDATE talonarios SET ultimo_emitido = numero_desde - 1;
    UPDATE chequeras SET proximo_numero = numero_desde;

    -- 2. Configuración inventada ---------------------------------------------
    DELETE FROM empresa;
    INSERT INTO empresa (id_empresa, razon_social, nombre_fantasia, cuit, condicion_iva, ingresos_brutos, inicio_actividades, domicilio, localidad, provincia, codigo_postal, telefono, email, sitio_web, actualizado_por)
    VALUES (1, 'CREDITOS DEL EJEMPLO S.A.', 'Financiera Ejemplo', '30719991048', 'RI', '901-123456-7', '2015-03-02', 'Av. de los Ejemplos 1234, piso 3', 'Ciudad Autónoma de Buenos Aires', 'Ciudad Autónoma de Buenos Aires', '1001', '011 5555-0100', 'info@financiera-ejemplo.com.ar', 'www.financiera-ejemplo.com.ar', 1);
    INSERT INTO organismos (id_organismo, nombre, activo) VALUES (1, 'Consejo de Enseñanza de Montealto', 1), (2, 'Caja de Retiros de Montealto', 1), (3, 'Guardia Comunal de Montealto', 1), (4, 'Red Comercial Alba', 1);
    INSERT INTO reparticiones (id_reparticion, nombre, id_organismo, activo, fecha_cobro) VALUES (1, 'MONTEALTO ENSEÑANZA - DISTRITO CUMBRE', 1, 1, NULL), (2, 'MONTEALTO ENSEÑANZA - DISTRITO LLANO', 1, 1, NULL), (3, 'CAJA DE RETIROS MONTEALTO - ORDINARIOS', 2, 1, NULL), (4, 'CAJA DE RETIROS MONTEALTO - PENSIONES', 2, 1, NULL), (5, 'GUARDIA COMUNAL MONTEALTO - ACTIVOS', 3, 1, NULL), (6, 'GUARDIA COMUNAL MONTEALTO - RETIRADOS', 3, 1, NULL), (7, 'RED ALBA - COMERCIOS', 4, 1, NULL), (8, 'RED ALBA - SERVICIOS', 4, 1, NULL);
    INSERT INTO sucursales (id_sucursal, nombre, activo) VALUES (1, 'Casa Central', 1), (2, 'Sucursal Norte', 1), (3, 'Sucursal Costa', 1), (4, 'Sucursal Oeste', 1);
    INSERT INTO zonas (id_zona, nombre, activo) VALUES (1, 'Zona Norte', 1), (2, 'Zona Sur', 1), (3, 'Zona Oeste', 1);
    INSERT INTO vendedores (id_vendedor, codigo, nombre, cuit, cbu, telefono, id_zona, id_supervisor, tipo_vinculo, fecha_alta, activo, dni, email, provincia, condicion_iva) VALUES
        (1, 'V01', 'MORETTI AYALA DANIELA', '27294458714', '0070101900000009000070', '549115556001', 1, NULL, 'EMPLEADO', '2025-02-01', 1, '29445871', 'moretti@financiera-ejemplo.com.ar', 'Buenos Aires', NULL),
        (2, 'V02', 'PALAZZINI RUBEN OMAR', '20271139080', '0140102400000009000148', '549115556002', 2, 1, 'COMISIONISTA', '2025-02-01', 1, '27113908', 'palazzini@financiera-ejemplo.com.ar', 'Buenos Aires', 'Monotributo'),
        (3, 'V03', 'BERTOLAMI GASTON EMILIO', '20335802145', '0170103200000009000216', '549115556003', 3, 1, 'COMISIONISTA', '2025-02-01', 1, '33580214', 'bertolami@financiera-ejemplo.com.ar', 'Buenos Aires', 'Monotributo'),
        (4, 'V04', 'OLIVARES TRINCA MARISA', '27302177665', '0720104800000009000285', '549115556004', 1, 1, 'COMISIONISTA', '2025-02-01', 1, '30217766', 'olivares@financiera-ejemplo.com.ar', 'Buenos Aires', 'Monotributo'),
        (5, 'V05', 'ZUBIRIA ROLDAN LUCAS', '20359024488', '2850105100000009000353', '549115556005', 2, 1, 'COMISIONISTA', '2025-02-01', 1, '35902447', 'zubiria@financiera-ejemplo.com.ar', 'Buenos Aires', 'Monotributo'),
        (6, 'C01', 'COMERCIALIZADORA VIENTO ALTO S.R.L.', '30710884524', '1910106600000009000421', '549115556006', 3, NULL, 'COMISIONISTA', '2025-02-01', 1, NULL, 'ventas@vientoalto-ejemplo.com.ar', 'Buenos Aires', 'Responsable inscripto'),
        (7, 'C02', 'RED DE VENTAS LUNARIA S.A.', '30709412368', '1500107000000009000490', '549115556007', 1, NULL, 'COMISIONISTA', '2025-02-01', 1, NULL, 'ventas@lunaria-ejemplo.com.ar', 'Buenos Aires', 'Responsable inscripto'),
        (8, 'TM', 'TELEMARKETING INTERNO', NULL, NULL, '541155556000', NULL, NULL, 'EMPLEADO', '2025-02-01', 1, NULL, 'telemarketing@financiera-ejemplo.com.ar', 'Buenos Aires', NULL);
    INSERT INTO grillas (id_grilla, nombre, descripcion, origen, activo) VALUES (1, 'Grilla Red Alba', 'Cuota fija por monto y plazo, IVA incluido (datos de ejemplo)', 'MANUAL', 1);
    INSERT INTO grillas_celdas (id_grilla, monto, plazo, valor_cuota) VALUES
        (1, 50000, 3, 20490.00), (1, 50000, 6, 11850.00), (1, 50000, 9, 9060.00), (1, 50000, 12, 7740.00), (1, 50000, 18, 6530.00), (1, 50000, 24, 6030.00),
        (1, 100000, 3, 40990.00), (1, 100000, 6, 23700.00), (1, 100000, 9, 18120.00), (1, 100000, 12, 15470.00), (1, 100000, 18, 13060.00), (1, 100000, 24, 12060.00),
        (1, 150000, 3, 61480.00), (1, 150000, 6, 35550.00), (1, 150000, 9, 27190.00), (1, 150000, 12, 23210.00), (1, 150000, 18, 19590.00), (1, 150000, 24, 18090.00),
        (1, 200000, 3, 81970.00), (1, 200000, 6, 47400.00), (1, 200000, 9, 36250.00), (1, 200000, 12, 30940.00), (1, 200000, 18, 26120.00), (1, 200000, 24, 24110.00),
        (1, 250000, 3, 102470.00), (1, 250000, 6, 59250.00), (1, 250000, 9, 45310.00), (1, 250000, 12, 38680.00), (1, 250000, 18, 32640.00), (1, 250000, 24, 30140.00),
        (1, 300000, 3, 122960.00), (1, 300000, 6, 71100.00), (1, 300000, 9, 54370.00), (1, 300000, 12, 46410.00), (1, 300000, 18, 39170.00), (1, 300000, 24, 36170.00),
        (1, 350000, 3, 143450.00), (1, 350000, 6, 82950.00), (1, 350000, 9, 63440.00), (1, 350000, 12, 54150.00), (1, 350000, 18, 45700.00), (1, 350000, 24, 42200.00),
        (1, 400000, 3, 163950.00), (1, 400000, 6, 94800.00), (1, 400000, 9, 72500.00), (1, 400000, 12, 61880.00), (1, 400000, 18, 52230.00), (1, 400000, 24, 48230.00),
        (1, 450000, 3, 184440.00), (1, 450000, 6, 106650.00), (1, 450000, 9, 81560.00), (1, 450000, 12, 69620.00), (1, 450000, 18, 58760.00), (1, 450000, 24, 54260.00),
        (1, 500000, 3, 204930.00), (1, 500000, 6, 118500.00), (1, 500000, 9, 90620.00), (1, 500000, 12, 77350.00), (1, 500000, 18, 65290.00), (1, 500000, 24, 60290.00),
        (1, 550000, 3, 225430.00), (1, 550000, 6, 130350.00), (1, 550000, 9, 99690.00), (1, 550000, 12, 85090.00), (1, 550000, 18, 71820.00), (1, 550000, 24, 66320.00),
        (1, 600000, 3, 245920.00), (1, 600000, 6, 142200.00), (1, 600000, 9, 108750.00), (1, 600000, 12, 92820.00), (1, 600000, 18, 78350.00), (1, 600000, 24, 72340.00),
        (1, 650000, 3, 266410.00), (1, 650000, 6, 154050.00), (1, 650000, 9, 117810.00), (1, 650000, 12, 100560.00), (1, 650000, 18, 84880.00), (1, 650000, 24, 78370.00),
        (1, 700000, 3, 286910.00), (1, 700000, 6, 165900.00), (1, 700000, 9, 126870.00), (1, 700000, 12, 108290.00), (1, 700000, 18, 91400.00), (1, 700000, 24, 84400.00),
        (1, 750000, 3, 307400.00), (1, 750000, 6, 177750.00), (1, 750000, 9, 135930.00), (1, 750000, 12, 116030.00), (1, 750000, 18, 97930.00), (1, 750000, 24, 90430.00),
        (1, 800000, 3, 327890.00), (1, 800000, 6, 189600.00), (1, 800000, 9, 145000.00), (1, 800000, 12, 123760.00), (1, 800000, 18, 104460.00), (1, 800000, 24, 96460.00),
        (1, 850000, 3, 348390.00), (1, 850000, 6, 201450.00), (1, 850000, 9, 154060.00), (1, 850000, 12, 131500.00), (1, 850000, 18, 110990.00), (1, 850000, 24, 102490.00),
        (1, 900000, 3, 368880.00), (1, 900000, 6, 213300.00), (1, 900000, 9, 163120.00), (1, 900000, 12, 139230.00), (1, 900000, 18, 117520.00), (1, 900000, 24, 108520.00),
        (1, 950000, 3, 389370.00), (1, 950000, 6, 225150.00), (1, 950000, 9, 172180.00), (1, 950000, 12, 146970.00), (1, 950000, 18, 124050.00), (1, 950000, 24, 114550.00),
        (1, 1000000, 3, 409870.00), (1, 1000000, 6, 237000.00), (1, 1000000, 9, 181250.00), (1, 1000000, 12, 154700.00), (1, 1000000, 18, 130580.00), (1, 1000000, 24, 120570.00),
        (1, 1050000, 3, 430360.00), (1, 1050000, 6, 248850.00), (1, 1050000, 9, 190310.00), (1, 1050000, 12, 162440.00), (1, 1050000, 18, 137110.00), (1, 1050000, 24, 126600.00),
        (1, 1100000, 3, 450850.00), (1, 1100000, 6, 260700.00), (1, 1100000, 9, 199370.00), (1, 1100000, 12, 170170.00), (1, 1100000, 18, 143640.00), (1, 1100000, 24, 132630.00),
        (1, 1150000, 3, 471350.00), (1, 1150000, 6, 272550.00), (1, 1150000, 9, 208430.00), (1, 1150000, 12, 177910.00), (1, 1150000, 18, 150160.00), (1, 1150000, 24, 138660.00),
        (1, 1200000, 3, 491840.00), (1, 1200000, 6, 284400.00), (1, 1200000, 9, 217490.00), (1, 1200000, 12, 185640.00), (1, 1200000, 18, 156690.00), (1, 1200000, 24, 144690.00),
        (1, 1250000, 3, 512330.00), (1, 1250000, 6, 296250.00), (1, 1250000, 9, 226560.00), (1, 1250000, 12, 193380.00), (1, 1250000, 18, 163220.00), (1, 1250000, 24, 150720.00),
        (1, 1300000, 3, 532830.00), (1, 1300000, 6, 308100.00), (1, 1300000, 9, 235620.00), (1, 1300000, 12, 201110.00), (1, 1300000, 18, 169750.00), (1, 1300000, 24, 156750.00),
        (1, 1350000, 3, 553320.00), (1, 1350000, 6, 319950.00), (1, 1350000, 9, 244680.00), (1, 1350000, 12, 208850.00), (1, 1350000, 18, 176280.00), (1, 1350000, 24, 162780.00),
        (1, 1400000, 3, 573810.00), (1, 1400000, 6, 331800.00), (1, 1400000, 9, 253740.00), (1, 1400000, 12, 216580.00), (1, 1400000, 18, 182810.00), (1, 1400000, 24, 168800.00),
        (1, 1450000, 3, 594310.00), (1, 1450000, 6, 343650.00), (1, 1450000, 9, 262810.00), (1, 1450000, 12, 224320.00), (1, 1450000, 18, 189340.00), (1, 1450000, 24, 174830.00),
        (1, 1500000, 3, 614800.00), (1, 1500000, 6, 355500.00), (1, 1500000, 9, 271870.00), (1, 1500000, 12, 232050.00), (1, 1500000, 18, 195870.00), (1, 1500000, 24, 180860.00);
    INSERT INTO tipos_asociado (id_tipo_asociado, codigo, nombre, destinatario, sistema_amort, plazo_min, plazo_max, monto_min, monto_max, alicuota_iva, pct_telemarketing, gasto_otorg_pct, activo, paga_cuota_social, color, punitorio_modo, punitorio_fijo, id_grilla) VALUES
        (1, 'EJ_DOC', 'Docentes Montealto', 'F', 'FRANCES', 1, 48, 50000, 2000000, 0.2100, 1.0000, 0.0000, 1, 1, '#2e7d32', 'TASA', NULL, NULL),
        (2, 'EJ_JUB', 'Jubilados Caja Montealto', 'F', 'FRANCES', 1, 48, 50000, 1500000, 0.2100, 1.0000, 0.0000, 1, 1, '#6a1b9a', 'FIJO', 500, NULL),
        (3, 'EJ_SEG', 'Guardia Montealto', 'F', 'FRANCES', 1, 36, 50000, 2000000, 0.2100, 1.0000, 0.0000, 1, 0, '#1565c0', 'TASA', NULL, NULL),
        (4, 'EJ_COM', 'Comercios Red Alba (grilla)', 'F', 'FRANCES', 3, 24, 50000, 1500000, 0.2100, 1.0000, 0.0000, 1, 0, '#ef6c00', 'TASA', NULL, 1),
        (5, 'EJ_PYME', 'Empresas Plan Brújula', 'J', 'FRANCES', 1, 60, 500000, 10000000, 0.2100, 1.0000, 0.0000, 1, 0, '#455a64', 'TASA', NULL, NULL);
    INSERT INTO tasas_vigencia (id_tipo_asociado, vigencia_desde, plazo_desde, plazo_hasta, monto_desde, monto_hasta, tna, creado_por) VALUES (1, '2026-01-01', 1, 48, 50000, 2000000, 0.95, 1), (2, '2026-01-01', 1, 48, 50000, 1500000, 0.8, 1), (3, '2026-01-01', 1, 36, 50000, 2000000, 0.9, 1), (5, '2026-01-01', 1, 60, 500000, 10000000, 0.7, 1);
    INSERT INTO comision_reglas (descripcion, id_tipo_asociado, id_vendedor, plazo_desde, plazo_hasta, porcentaje, base_calculo, momento, prioridad, vigencia_desde, activo) VALUES
        ('Personas: 3 % del capital', NULL, NULL, 1, 999, 0.030000, 'CAPITAL', 'LIQUIDACION', 0, '2026-01-01', 1),
        ('Empresas PyME: 1,5 % del capital', 5, NULL, 1, 999, 0.015000, 'CAPITAL', 'LIQUIDACION', 0, '2026-01-01', 1),
        ('Comercio con grilla: 4 % del capital', 4, NULL, 1, 999, 0.040000, 'CAPITAL', 'LIQUIDACION', 0, '2026-01-01', 1),
        ('Supervisora: 3,5 % del capital', NULL, 1, 1, 999, 0.035000, 'CAPITAL', 'LIQUIDACION', 0, '2026-01-01', 1);
    INSERT INTO cuota_social_valores (id_tipo_asociado, vigencia_desde, valor_inicial, meses_inicial, valor_mensual, valor_adicional, creado_por) VALUES
        (1, '2026-01-01', 7500, 6, 9000, NULL, 1), (2, '2026-01-01', NULL, NULL, 6500, NULL, 1);
    INSERT INTO gastos_conceptos_tipos (id_concepto_gasto, id_tipo_asociado, tipo_calculo, valor, forma_cobro, activo)
    SELECT g.id_concepto_gasto, t.id, 'PORCENTAJE', 0.012, 'DESCUENTO', 1 FROM gastos_conceptos g JOIN (SELECT 1 AS id UNION ALL SELECT 3 UNION ALL SELECT 5) t WHERE g.codigo = 'SELLADOS';
    UPDATE telemarketing_clasificaciones SET nombre = ELT(1 + MOD(id_clasif_tm - 1, 9), 'Operador 1 automático', 'Operador 1 tradicional', 'Comercializador automático', 'Comercializador tradicional', 'Operador 2 automático', 'Operador 2 tradicional', 'Operador 3 automático', 'Operador 3 tradicional', 'No');

    -- 3. Clientes -------------------------------------------------------------
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (1, 'F', '27284561274', 'ABRANTEL SOFIA ELENA', 'sofia.abrantel@correo-ejemplo.com.ar', '0070201200040284561279', 'SOFIA.ABRANTEL.CTA', 'CF', 1, 1, 1, 'ACTIVO', '2026-05-29 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (1, 'ABRANTEL', 'SOFIA ELENA', '28456127', '1981-03-14', 'F', 'CASADO', 'Docente', 'Escuela N.º 14 Distrito Cumbre', 850000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (1, 'CELULAR', '5492215550141', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (1, 'REAL', 'Calle 23', '1430', 'La Plata', 'Buenos Aires', '1900', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (2, 'F', '20257894526', 'BRUNETTO VALLE JULIAN ARIEL', 'julian.brunetto@correo-ejemplo.com.ar', '0140202700040257894522', 'JULIAN.BRUNETTO.CTA', 'CF', 1, 2, 2, 'ACTIVO', '2026-06-02 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (2, 'BRUNETTO VALLE', 'JULIAN ARIEL', '25789452', '1977-08-02', 'M', 'CASADO', 'Preceptor', 'Escuela Técnica N.º 3', 720000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (2, 'CELULAR', '5491155550222', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (2, 'REAL', 'Av. Las Acacias', '856', 'Quilmes', 'Buenos Aires', '1878', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (3, 'F', '27331245610', 'CASTRANOVA LUZ MARINA', 'luz.castranova@correo-ejemplo.com.ar', '0170203500040331245611', NULL, 'CF', 4, 7, 3, 'ACTIVO', '2026-06-06 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (3, 'CASTRANOVA', 'LUZ MARINA', '33124561', '1987-11-23', 'F', 'SOLTERO', 'Encargada de local', 'Almacén Los Pinos', 910000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (3, 'CELULAR', '5491155550333', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (3, 'REAL', 'Los Ceibos', '312', 'Florencio Varela', 'Buenos Aires', '1888', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (4, 'F', '20305678954', 'DELVENTO RAMIRO ANDRES', 'ramiro.delvento@correo-ejemplo.com.ar', '0720204100040305678953', 'RAMIRO.DELVENTO.CTA', 'CF', 3, 5, 4, 'ACTIVO', '2026-06-12 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (4, 'DELVENTO', 'RAMIRO ANDRES', '30567895', '1984-05-30', 'M', 'CONVIVIENTE', 'Guardia', 'Guardia Comunal de Montealto', 1050000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (4, 'CELULAR', '5491155550444', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (4, 'REAL', 'Del Arroyo', '745', 'Lomas de Zamora', 'Buenos Aires', '1832', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (5, 'F', '27223456710', 'ESTIGARRIBEL ADELA ROSA', 'adela.estigarribel@correo-ejemplo.com.ar', '2850205400040223456712', 'ADELA.ESTIGARRIBEL.C', 'CF', 2, 3, 1, 'ACTIVO', '2026-05-28 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (5, 'ESTIGARRIBEL', 'ADELA ROSA', '22345671', '1960-01-17', 'F', 'VIUDO', 'Jubilada', 'Caja de Retiros de Montealto', 480000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (5, 'CELULAR', '5491155550555', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (5, 'REAL', 'Av. Los Tilos', '2210', 'Avellaneda', 'Buenos Aires', '1870', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (6, 'F', '20356789149', 'FONTANELLA TOMAS IVAN', 'tomas.fontanella@correo-ejemplo.com.ar', '1910206900040356789149', NULL, 'CF', 1, 2, 2, 'ACTIVO', '2026-06-24 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (6, 'FONTANELLA', 'TOMAS IVAN', '35678914', '1990-09-08', 'M', 'SOLTERO', 'Maestro de grado', 'Escuela N.º 22 Distrito Llano', 780000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (6, 'CELULAR', '5491155550666', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (6, 'REAL', 'Las Golondrinas', '1180', 'Lanús', 'Buenos Aires', '1824', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (7, 'F', '27314567825', 'GARIBOTTI MARA JIMENA', 'mara.garibotti@correo-ejemplo.com.ar', '1500207300040314567826', 'MARA.GARIBOTTI.CTA', 'CF', 4, 8, 3, 'ACTIVO', '2026-06-30 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (7, 'GARIBOTTI', 'MARA JIMENA', '31456782', '1985-12-04', 'F', 'CASADO', 'Administrativa', 'Distribuidora El Trébol', 830000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (7, 'CELULAR', '5491155550777', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (7, 'REAL', 'Los Aromos', '540', 'Morón', 'Buenos Aires', '1708', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (8, 'F', '20245678933', 'HUALPEN ORTIZ CLAUDIO SERGIO', 'claudio.hualpen@correo-ejemplo.com.ar', '0340208700040245678930', 'CLAUDIO.HUALPEN.CTA', 'CF', 1, 1, 4, 'ACTIVO', '2026-07-06 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (8, 'HUALPEN ORTIZ', 'CLAUDIO SERGIO', '24567893', '1975-04-19', 'M', 'DIVORCIADO', 'Profesor', 'Escuela Media N.º 5', 760000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (8, 'CELULAR', '5492235550888', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (8, 'REAL', 'Av. Mar Azul', '3120', 'Mar del Plata', 'Buenos Aires', '7600', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (9, 'F', '27345671280', 'IBARLUCEA NADIA SOL', 'nadia.ibarlucea@correo-ejemplo.com.ar', '0440209300040345671282', NULL, 'CF', 4, 7, 5, 'ACTIVO', '2026-07-24 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (9, 'IBARLUCEA', 'NADIA SOL', '34567128', '1989-07-11', 'F', 'SOLTERO', 'Vendedora', 'Tienda La Brújula', 690000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (9, 'CELULAR', '5492915550999', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (9, 'REAL', 'Del Faro', '845', 'Bahía Blanca', 'Buenos Aires', '8000', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (10, 'F', '20278901255', 'JARAMILLO VESPA BRUNO EZEQUIEL', 'bruno.jaramillo@correo-ejemplo.com.ar', '0110210100040278901250', 'BRUNO.JARAMILLO.CTA', 'CF', 3, 5, 6, 'ACTIVO', '2026-08-04 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (10, 'JARAMILLO VESPA', 'BRUNO EZEQUIEL', '27890125', '1980-02-27', 'M', 'CASADO', 'Guardia primero', 'Guardia Comunal de Montealto', 880000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (10, 'CELULAR', '5491155551010', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (10, 'REAL', 'Av. Los Paraísos', '3356', 'El Palomar', 'Buenos Aires', '1684', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (11, 'F', '27290123475', 'KESSLAR LORENA BEATRIZ', 'lorena.kesslar@correo-ejemplo.com.ar', '0070211100040290123478', 'LORENA.KESSLAR.CTA', 'CF', 1, 1, 7, 'ACTIVO', '2026-08-13 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (11, 'KESSLAR', 'LORENA BEATRIZ', '29012347', '1982-10-15', 'F', 'CONVIVIENTE', 'Auxiliar escolar', 'Escuela N.º 9 Distrito Cumbre', 610000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (11, 'CELULAR', '5491155551111', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (11, 'REAL', 'Las Calandrias', '1520', 'Merlo', 'Buenos Aires', '1722', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (12, 'F', '20212345637', 'LUNARDELLI OSCAR ATILIO', 'oscar.lunardelli@correo-ejemplo.com.ar', '0140212600040212345634', NULL, 'CF', 2, 3, 1, 'ACTIVO', '2026-08-24 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (12, 'LUNARDELLI', 'OSCAR ATILIO', '21234563', '1958-06-21', 'M', 'CASADO', 'Jubilado', 'Caja de Retiros de Montealto', 520000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (12, 'CELULAR', '5491155551212', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (12, 'REAL', 'Los Fresnos', '2845', 'San Justo', 'Buenos Aires', '1754', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (13, 'F', '27367890180', 'MIRANDOLA PAZ MICAELA', 'paz.mirandola@correo-ejemplo.com.ar', '0170213400040367890180', 'PAZ.MIRANDOLA.CTA', 'MONO', 3, 6, 2, 'ACTIVO', '2026-09-01 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (13, 'MIRANDOLA', 'PAZ MICAELA', '36789018', '1992-03-03', 'F', 'SOLTERO', 'Agente', 'Guardia Comunal de Montealto', 700000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (13, 'CELULAR', '5493415551313', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (13, 'REAL', 'Los Jazmines', '1630', 'Rosario', 'Santa Fe', '2000', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (14, 'F', '20389012360', 'NARVAJAS LEANDRO TEO', 'leandro.narvajas@correo-ejemplo.com.ar', '0720214000040389012360', 'LEANDRO.NARVAJAS.CTA', 'MONO', 3, 5, 3, 'ACTIVO', '2026-09-08 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (14, 'NARVAJAS', 'LEANDRO TEO', '38901236', '1995-12-29', 'M', 'SOLTERO', 'Agente', 'Guardia Comunal de Montealto', 950000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (14, 'CELULAR', '5493515551414', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (14, 'REAL', 'Av. Las Sierras', '1245', 'Córdoba', 'Córdoba', '5000', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (21, 'F', '27374451206', 'OLMEDRANO VERA LUCIA', 'vera.olmedrano@correo-ejemplo.com.ar', '0070221000040374451200', NULL, 'CF', 1, 2, 4, 'ACTIVO', '2026-11-17 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (21, 'OLMEDRANO', 'VERA LUCIA', '37445120', '1993-05-09', 'F', 'SOLTERO', 'Docente', 'Escuela N.º 31 Distrito Llano', 780000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (21, 'CELULAR', '5491155552121', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (21, 'REAL', 'Los Robles', '410', 'Banfield', 'Buenos Aires', '1828', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (22, 'F', '20263014570', 'PANIZZOLI GERMAN ARIEL', 'german.panizzoli@correo-ejemplo.com.ar', '0140222500040263014570', 'GERMAN.PANIZZOLI.CTA', 'CF', 2, 4, 5, 'ACTIVO', '2026-10-02 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (22, 'PANIZZOLI', 'GERMAN ARIEL', '26301457', '1978-01-30', 'M', 'CASADO', 'Jubilado', 'Caja de Retiros de Montealto', 560000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (22, 'CELULAR', '5491155552222', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (22, 'REAL', 'Av. Del Sol', '1975', 'Temperley', 'Buenos Aires', '1834', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (23, 'F', '27325587402', 'QUINTERNO ALMA DELFINA', 'alma.quinterno@correo-ejemplo.com.ar', '0170223300040325587400', 'ALMA.QUINTERNO.CTA', 'MONO', 4, 8, 6, 'ACTIVO', '2026-10-15 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (23, 'QUINTERNO', 'ALMA DELFINA', '32558740', '1986-08-14', 'F', 'CASADO', 'Peluquera', 'Salón Alma', 650000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (23, 'CELULAR', '5491155552323', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (23, 'REAL', 'Las Violetas', '88', 'Ituzaingó', 'Buenos Aires', '1714', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (24, 'F', '20390125845', 'RIVOLTANA EZEQUIEL TOMAS', 'ezequiel.rivoltana@correo-ejemplo.com.ar', '0720224900040390125840', NULL, 'CF', 3, 5, 7, 'ACTIVO', '2026-10-20 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (24, 'RIVOLTANA', 'EZEQUIEL TOMAS', '39012584', '1996-02-11', 'M', 'SOLTERO', 'Guardia mayor', 'Guardia Comunal de Montealto', 990000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (24, 'CELULAR', '5493515552424', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (24, 'REAL', 'Los Cardones', '702', 'Villa Carlos Paz', 'Córdoba', '5152', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (25, 'F', '27308812362', 'SALVATIERRO NOELIA INES', 'noelia.salvatierro@correo-ejemplo.com.ar', '2850225200040308812363', 'NOELIA.SALVATIERRO.C', 'CF', 1, 1, 1, 'ACTIVO', '2026-09-27 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (25, 'SALVATIERRO', 'NOELIA INES', '30881236', '1984-06-02', 'F', 'DIVORCIADO', 'Docente', 'Escuela N.º 7 Distrito Cumbre', 820000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (25, 'CELULAR', '5492215552525', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (25, 'REAL', 'Calle 41', '1210', 'La Plata', 'Buenos Aires', '1900', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (26, 'F', '20275563014', 'TORNAGHI FACUNDO LEON', 'facundo.tornaghi@correo-ejemplo.com.ar', '1910226700040275563011', 'FACUNDO.TORNAGHI.CTA', 'CF', 3, 6, 2, 'ACTIVO', '2026-09-12 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (26, 'TORNAGHI', 'FACUNDO LEON', '27556301', '1979-11-19', 'M', 'CASADO', 'Retirado', 'Guardia Comunal de Montealto', 740000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (26, 'CELULAR', '5492615552626', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (26, 'REAL', 'Los Olivos', '1544', 'Godoy Cruz', 'Mendoza', '5501', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (27, 'F', '27239980142', 'URQUIBEL CELESTE ANA', 'celeste.urquibel@correo-ejemplo.com.ar', '1500227100040239980146', NULL, 'CF', 2, 4, 3, 'ACTIVO', '2026-08-30 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (27, 'URQUIBEL', 'CELESTE ANA', '23998014', '1963-04-25', 'F', 'VIUDO', 'Pensionada', 'Caja de Retiros de Montealto', 450000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (27, 'CELULAR', '5493875552727', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (27, 'REAL', 'Las Lomas', '390', 'Salta', 'Salta', '4400', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (28, 'F', '20281145607', 'VILLAMONTE RUBEN DARIO', 'ruben.villamonte@correo-ejemplo.com.ar', '0340228500040281145601', 'RUBEN.VILLAMONTE.CTA', 'MONO', 4, 8, 4, 'ACTIVO', '2026-11-05 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (28, 'VILLAMONTE', 'RUBEN DARIO', '28114560', '1980-09-07', 'M', 'CONVIVIENTE', 'Repartidor', 'Logística Puerto Verde', 700000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (28, 'CELULAR', '5491155552828', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (28, 'REAL', 'Los Plátanos', '2266', 'Moreno', 'Buenos Aires', '1744', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (15, 'J', '30712045686', 'ALFAJORES LA TORCAZA S.R.L.', 'administracion@latorcaza-ejemplo.com.ar', '2850315600090712045688', 'LATORCAZA.EMPRESA', 'RI', 5, NULL, 4, 'ACTIVO', '2026-06-19 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (15, 'ALFAJORES LA TORCAZA S.R.L.', 'LA TORCAZA', 'SRL', 'Elaboración de productos de panadería', '2012-04-10', 185000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (15, 'FIJO', '541145550015', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (15, 'COMERCIAL', 'Av. Los Inmigrantes', '4512', 'Avellaneda', 'Buenos Aires', '1870', 1);
    INSERT INTO contactos (entidad_tipo, entidad_id, nombre, vinculo, celular, email, principal, activo, creado_por) VALUES ('cliente', 15, 'Administración La Torcaza', 'Administración', '549115557015', 'administracion@latorcaza-ejemplo.com.ar', 1, 1, 2);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (16, 'J', '30709876542', 'LOGISTICA RIO TINTILLO S.A.', 'administracion@riotintillo-ejemplo.com.ar', '1910316100090709876543', 'RIOTINTILLO.EMPRESA', 'RI', 5, NULL, 1, 'ACTIVO', '2026-06-05 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (16, 'LOGISTICA RIO TINTILLO S.A.', 'RIO TINTILLO', 'SA', 'Transporte de cargas', '2005-09-01', 420000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (16, 'FIJO', '542235550016', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (16, 'COMERCIAL', 'Av. de la Costa', '5420', 'Mar del Plata', 'Buenos Aires', '7600', 1);
    INSERT INTO contactos (entidad_tipo, entidad_id, nombre, vinculo, celular, email, principal, activo, creado_por) VALUES ('cliente', 16, 'Administración Rio Tintillo', 'Administración', '549115557016', 'administracion@riotintillo-ejemplo.com.ar', 1, 1, 2);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (17, 'J', '30718765419', 'CONSTRUCCIONES CERRO AZULEJO S.A.S.', 'administracion@cerroazulejo-ejemplo.com.ar', '1500317500090718765416', 'CERROAZULEJO.EMPRESA', 'RI', 5, NULL, 2, 'ACTIVO', '2026-11-10 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (17, 'CONSTRUCCIONES CERRO AZULEJO S.A.S.', 'CERRO AZULEJO', 'SAS', 'Construcción de viviendas', '2019-02-18', 260000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (17, 'FIJO', '542215550017', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (17, 'COMERCIAL', 'Calle 9', '980', 'La Plata', 'Buenos Aires', '1900', 1);
    INSERT INTO contactos (entidad_tipo, entidad_id, nombre, vinculo, celular, email, principal, activo, creado_por) VALUES ('cliente', 17, 'Administración Cerro Azulejo', 'Administración', '549115557017', 'administracion@cerroazulejo-ejemplo.com.ar', 1, 1, 2);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (18, 'J', '30713456701', 'AGRICOLA LOS TRES OMBUES S.R.L.', 'administracion@tresombues-ejemplo.com.ar', '0340318900090713456702', 'TRESOMBUES.EMPRESA', 'RI', 5, NULL, 6, 'ACTIVO', '2026-10-11 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (18, 'AGRICOLA LOS TRES OMBUES S.R.L.', 'TRES OMBUES', 'SRL', 'Servicios agrícolas', '2010-07-05', 310000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (18, 'FIJO', '542395550018', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (18, 'COMERCIAL', 'Av. de los Pioneros', '1240', 'Trenque Lauquen', 'Buenos Aires', '6400', 1);
    INSERT INTO contactos (entidad_tipo, entidad_id, nombre, vinculo, celular, email, principal, activo, creado_por) VALUES ('cliente', 18, 'Administración Tres Ombues', 'Administración', '549115557018', 'administracion@tresombues-ejemplo.com.ar', 1, 1, 2);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (19, 'J', '30715678930', 'FARMACIA SAN TELMIN S.R.L.', 'administracion@santelmin-ejemplo.com.ar', '0440319500090715678931', 'FARMACIASANT.EMPRESA', 'RI', 5, NULL, 3, 'ACTIVO', '2026-06-08 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (19, 'FARMACIA SAN TELMIN S.R.L.', 'FARMACIA SAN TELMIN', 'SRL', 'Farmacia', '2008-11-20', 140000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (19, 'FIJO', '541145550019', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (19, 'COMERCIAL', 'Las Magnolias', '245', 'Quilmes', 'Buenos Aires', '1878', 1);
    INSERT INTO contactos (entidad_tipo, entidad_id, nombre, vinculo, celular, email, principal, activo, creado_por) VALUES ('cliente', 19, 'Administración Farmacia San Telmin', 'Administración', '549115557019', 'administracion@santelmin-ejemplo.com.ar', 1, 1, 2);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (20, 'J', '30704567878', 'METALURGICA PUNTA BRAVA S.A.', 'administracion@puntabrava-ejemplo.com.ar', '0110320300090704567877', 'PUNTABRAVA.EMPRESA', 'RI', 5, NULL, 5, 'ACTIVO', '2026-11-13 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (20, 'METALURGICA PUNTA BRAVA S.A.', 'PUNTA BRAVA', 'SA', 'Metalurgia', '1998-03-12', 520000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (20, 'FIJO', '541145550020', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (20, 'COMERCIAL', 'Av. Industrial', '3870', 'San Martín', 'Buenos Aires', '1650', 1);
    INSERT INTO contactos (entidad_tipo, entidad_id, nombre, vinculo, celular, email, principal, activo, creado_por) VALUES ('cliente', 20, 'Administración Punta Brava', 'Administración', '549115557020', 'administracion@puntabrava-ejemplo.com.ar', 1, 1, 2);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (29, 'J', '30716901242', 'PANADERIA LA ESPIGA DORADITA S.R.L.', 'administracion@espigadoradita-ejemplo.com.ar', '0440329400090716901247', 'LAESPIGADORA.EMPRESA', 'RI', 5, NULL, 7, 'ACTIVO', '2026-11-03 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (29, 'PANADERIA LA ESPIGA DORADITA S.R.L.', 'LA ESPIGA DORADITA', 'SRL', 'Panadería y confitería', '2015-05-04', 95000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (29, 'FIJO', '541145550029', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (29, 'COMERCIAL', 'Los Trigales', '615', 'Hurlingham', 'Buenos Aires', '1686', 1);
    INSERT INTO contactos (entidad_tipo, entidad_id, nombre, vinculo, celular, email, principal, activo, creado_por) VALUES ('cliente', 29, 'Administración La Espiga Doradita', 'Administración', '549115557029', 'administracion@espigadoradita-ejemplo.com.ar', 1, 1, 2);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (30, 'J', '30707789030', 'TEXTIL HILVANES DEL SUR S.A.', 'administracion@hilvanes-ejemplo.com.ar', '0110330200090707789036', 'HILVANESDELS.EMPRESA', 'RI', 5, NULL, 2, 'ACTIVO', '2026-10-28 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (30, 'TEXTIL HILVANES DEL SUR S.A.', 'HILVANES DEL SUR', 'SA', 'Fabricación de textiles', '2001-10-22', 360000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (30, 'FIJO', '543415550030', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (30, 'COMERCIAL', 'Av. Ribereña', '2950', 'Rosario', 'Santa Fe', '2000', 1);
    INSERT INTO contactos (entidad_tipo, entidad_id, nombre, vinculo, celular, email, principal, activo, creado_por) VALUES ('cliente', 30, 'Administración Hilvanes Del Sur', 'Administración', '549115557030', 'administracion@hilvanes-ejemplo.com.ar', 1, 1, 2);

    -- 4. Inversores -----------------------------------------------------------
    INSERT INTO inversores (id_inversor, tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, creado_el, creado_por)
    VALUES (1, 'F', '20173456787', 'ZAPIOLA BRENNER HORACIO', 'zapiola@correo-ejemplo.com.ar', '549115558001', 'Av. del Libertador 5120, CABA', '0070401800070173456782', NULL, 'ACTIVO', '2026-05-11 11:00:00', 1);
    INSERT INTO inversores (id_inversor, tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, creado_el, creado_por)
    VALUES (2, 'F', '27162345678', 'QUIRNO LASTRA ELVIRA', 'quirno@correo-ejemplo.com.ar', '549115558002', 'Calle 50 845, La Plata', '0140402300070162345673', NULL, 'ACTIVO', '2026-05-12 11:00:00', 1);
    INSERT INTO inversores (id_inversor, tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, creado_el, creado_por)
    VALUES (3, 'J', '30701234568', 'CAPITALES ALTO PALMAR S.A.', 'tesoreria@correo-ejemplo.com.ar', '549115558003', 'Reconquista 640, CABA', '0170403100070701234565', NULL, 'ACTIVO', '2026-05-13 11:00:00', 1);
    INSERT INTO inversores (id_inversor, tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, creado_el, creado_por)
    VALUES (4, 'J', '33706543215', 'HERMANOS CASTELNUOVO S.R.L.', 'tesoreria@correo-ejemplo.com.ar', '549115558004', 'Av. Costanera 3450, Mar del Plata', '0720404700070706543217', NULL, 'ACTIVO', '2026-05-14 11:00:00', 1);

    -- 5. Lo que se va a hacer, en orden de fecha (tablas tmp_ej_*, se borran al final)
    DROP TABLE IF EXISTS tmp_ej_prestamos;
    CREATE TABLE tmp_ej_prestamos (idx INT PRIMARY KEY, monto DECIMAL(15,2), plazo SMALLINT, tm TINYINT,
                                             id_prestamo INT UNSIGNED, id_solicitud INT UNSIGNED);
    INSERT INTO tmp_ej_prestamos (idx, monto, plazo, tm) VALUES
        (1, 600000, 12, 0),
        (2, 350000, 6, 1),
        (3, 450000, 9, 0),
        (4, 900000, 24, 0),
        (5, 250000, 3, 1),
        (6, 300000, 12, 0),
        (7, 520000, 18, 1),
        (8, 750000, 36, 0),
        (9, 280000, 6, 0),
        (10, 1200000, 24, 0),
        (11, 400000, 12, 1),
        (12, 500000, 48, 0),
        (13, 200000, 9, 1),
        (14, 650000, 18, 0),
        (15, 4000000, 36, 0),
        (16, 3500000, 24, 0),
        (17, 2500000, 60, 0),
        (18, 1800000, 12, 0),
        (19, 900000, 24, 1),
        (20, 3000000, 48, 0);
    DROP TABLE IF EXISTS tmp_ej_aportes;
    CREATE TABLE tmp_ej_aportes (idx INT PRIMARY KEY, id_inversor INT UNSIGNED, modalidad VARCHAR(20), moneda CHAR(3),
                                           capital DECIMAL(15,2), inicio DATE, vto DATE, tna DECIMAL(9,6), pct DECIMAL(9,6),
                                           cuenta INT UNSIGNED, medio VARCHAR(20), id_inversion INT UNSIGNED, id_liq INT UNSIGNED);
    INSERT INTO tmp_ej_aportes (idx, id_inversor, modalidad, moneda, capital, inicio, vto, tna, pct, cuenta, medio) VALUES
        (1, 1, 'TASA_FIJA', 'ARS', 8000000, '2026-05-20', '2026-08-18', '0.45', NULL, 4, 'TRANSFERENCIA'),
        (2, 2, 'TASA_FIJA', 'ARS', 20000000, '2026-05-15', '2027-05-15', '0.4', NULL, 4, 'TRANSFERENCIA'),
        (3, 3, 'TASA_FIJA', 'USD', 15000, '2026-08-03', '2027-02-03', '0.06', NULL, 6, 'EFECTIVO'),
        (4, 4, 'TASA_FIJA', 'ARS', 5000000, '2026-06-01', '2026-08-30', '0.5', NULL, 4, 'TRANSFERENCIA'),
        (5, 3, 'PARTICIPACION', 'ARS', 3000000, '2026-07-01', '2027-07-01', NULL, '0.6', 4, 'TRANSFERENCIA'),
        (6, 1, 'TASA_FIJA', 'ARS', 10000000, '2026-06-10', '2026-12-10', '0.38', NULL, 4, 'TRANSFERENCIA');
    DROP TABLE IF EXISTS tmp_ej_fondea;
    CREATE TABLE tmp_ej_fondea (aporte INT, prestamo INT, monto DECIMAL(15,2));
    INSERT INTO tmp_ej_fondea VALUES
        (5, 16, 2100000),
        (5, 19, 900000);
    DROP TABLE IF EXISTS tmp_ej_sol;
    CREATE TABLE tmp_ej_sol (idx INT PRIMARY KEY, cliente INT, tipo VARCHAR(20), monto DECIMAL(15,2), plazo SMALLINT, tm TINYINT,
                                       estado VARCHAR(20), creado DATETIME, resuelto DATETIME, por INT UNSIGNED, comentario VARCHAR(500));
    INSERT INTO tmp_ej_sol VALUES
        (1, 6, 'EJ_DOC', 200000, 6, 0, 'OBSERVADA', '2026-11-16 10:40:00', '2026-11-18 12:05:00', 1, 'Falta el último recibo de sueldo: que lo suba el vendedor y la reenvíe.'),
        (2, 11, 'EJ_DOC', 500000, 18, 1, 'RECHAZADA', '2026-11-16 15:10:00', '2026-11-17 09:50:00', 1, 'rechazo'),
        (3, 12, 'EJ_JUB', 300000, 12, 0, 'PENDIENTE', '2026-11-19 11:20:00', NULL, NULL, NULL),
        (4, 10, 'EJ_SEG', 800000, 12, 0, 'APROBADA', '2026-11-19 16:30:00', '2026-11-20 10:15:00', 1, 'Aprobada: al día con el préstamo anterior.'),
        (5, 21, 'EJ_DOC', 250000, 12, 0, 'PENDIENTE', '2026-11-20 09:45:00', NULL, NULL, NULL),
        (6, 29, 'EJ_PYME', 2000000, 24, 0, 'PENDIENTE', '2026-11-21 11:05:00', NULL, NULL, NULL);
    DROP TABLE IF EXISTS tmp_ej_lotes;
    CREATE TABLE tmp_ej_lotes (mes CHAR(7) PRIMARY KEY, id_lote INT UNSIGNED);
    DROP TABLE IF EXISTS tmp_ej_import;
    CREATE TABLE tmp_ej_import (mes CHAR(7) PRIMARY KEY, id_importacion INT UNSIGNED);
    DROP TABLE IF EXISTS tmp_ej_eventos;
    CREATE TABLE tmp_ej_eventos (id INT PRIMARY KEY, fecha DATE, tipo VARCHAR(10), a INT, b INT, x DECIMAL(9,4),
                                           medio VARCHAR(20), modo VARCHAR(20), f2 DATE, anular TINYINT);
    INSERT INTO tmp_ej_eventos VALUES
        (1, '2026-05-15', 'APORTE', 2, 0, 1.0, NULL, NULL, NULL, 0),
        (2, '2026-05-20', 'APORTE', 1, 0, 1.0, NULL, NULL, NULL, 0),
        (3, '2026-06-01', 'APORTE', 4, 0, 1.0, NULL, NULL, NULL, 0),
        (4, '2026-06-03', 'LIQ', 5, 0, 1.0, NULL, NULL, NULL, 0),
        (5, '2026-06-04', 'OP', 5, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (6, '2026-06-05', 'LIQ', 1, 0, 1.0, NULL, NULL, NULL, 0),
        (7, '2026-06-06', 'OP', 1, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (8, '2026-06-10', 'APORTE', 6, 0, 1.0, NULL, NULL, NULL, 0),
        (9, '2026-06-10', 'LIQ', 2, 0, 1.0, NULL, NULL, NULL, 0),
        (10, '2026-06-11', 'OP', 2, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (11, '2026-06-12', 'LIQ', 16, 0, 1.0, NULL, NULL, NULL, 0),
        (12, '2026-06-13', 'OP', 16, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (13, '2026-06-15', 'LIQ', 3, 0, 1.0, NULL, NULL, NULL, 0),
        (14, '2026-06-16', 'OP', 3, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (15, '2026-06-18', 'LIQ', 19, 0, 1.0, NULL, NULL, NULL, 0),
        (16, '2026-06-19', 'OP', 19, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (17, '2026-06-22', 'LIQ', 4, 0, 1.0, NULL, NULL, NULL, 0),
        (18, '2026-06-23', 'OP', 4, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (19, '2026-06-25', 'LIQ', 15, 0, 1.0, NULL, NULL, NULL, 0),
        (20, '2026-06-26', 'OP', 15, 0, 1.0, 'CHEQUE', NULL, NULL, 0),
        (21, '2026-06-30', 'MOVMES', 0, 0, 1.0, NULL, NULL, NULL, 0),
        (22, '2026-07-01', 'APORTE', 5, 0, 1.0, NULL, NULL, NULL, 0),
        (23, '2026-07-01', 'LIQ', 6, 0, 1.0, NULL, NULL, NULL, 0),
        (24, '2026-07-02', 'OP', 6, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (25, '2026-07-03', 'COBRO', 1, 1, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (26, '2026-07-03', 'COBRO', 5, 1, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (27, '2026-07-08', 'LIQ', 7, 0, 1.0, NULL, NULL, NULL, 0),
        (28, '2026-07-09', 'OP', 7, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (29, '2026-07-10', 'COBRO', 2, 1, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (30, '2026-07-10', 'COBRO', 16, 1, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (31, '2026-07-14', 'COBRO', 3, 1, 1.0, 'LOTE', 'NORMAL', '2026-07-20', 0),
        (32, '2026-07-15', 'LIQ', 8, 0, 1.0, NULL, NULL, NULL, 0),
        (33, '2026-07-16', 'OP', 8, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (34, '2026-07-16', 'COBRO', 19, 1, 1.0, 'LOTE', 'NORMAL', '2026-07-20', 0),
        (35, '2026-07-20', 'COBRO', 4, 1, 1.0, 'LOTE', 'NORMAL', '2026-07-20', 0),
        (36, '2026-07-24', 'COBRO', 15, 1, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (37, '2026-07-31', 'MOVMES', 0, 0, 1.0, NULL, NULL, NULL, 0),
        (38, '2026-08-01', 'COBRO', 6, 1, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (39, '2026-08-02', 'COBRO', 5, 2, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (40, '2026-08-03', 'APORTE', 3, 0, 1.0, NULL, NULL, NULL, 0),
        (41, '2026-08-03', 'LIQ', 9, 0, 1.0, NULL, NULL, NULL, 0),
        (42, '2026-08-04', 'OP', 9, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (43, '2026-08-05', 'COBRO', 1, 2, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (44, '2026-08-06', 'COBRO', 7, 1, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (45, '2026-08-10', 'LIQ', 10, 0, 1.0, NULL, NULL, NULL, 0),
        (46, '2026-08-10', 'COBRO', 2, 2, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (47, '2026-08-11', 'OP', 10, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (48, '2026-08-12', 'COBRO', 16, 2, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (49, '2026-08-13', 'COBRO', 3, 2, 1.0, 'LOTE', 'NORMAL', '2026-08-22', 0),
        (50, '2026-08-15', 'COBRO', 8, 1, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (51, '2026-08-18', 'COBRO', 19, 2, 1.0, 'LOTE', 'NORMAL', '2026-08-22', 0),
        (52, '2026-08-18', 'LIQINV', 1, 90, 1.0, 'CAPITAL', NULL, NULL, 0),
        (53, '2026-08-19', 'PAGOINV', 1, 0, 1.0, NULL, NULL, NULL, 0),
        (54, '2026-08-20', 'LIQ', 11, 0, 1.0, NULL, NULL, NULL, 0),
        (55, '2026-08-21', 'OP', 11, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (56, '2026-08-22', 'COBRO', 4, 2, 1.0, 'LOTE', 'NORMAL', '2026-08-22', 0),
        (57, '2026-08-23', 'COBRO', 15, 2, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (58, '2026-08-30', 'LIQINV', 4, 0, 1.0, 'NO', NULL, NULL, 0),
        (59, '2026-08-31', 'MOVMES', 0, 0, 1.0, NULL, NULL, NULL, 0),
        (60, '2026-09-01', 'LIQ', 12, 0, 1.0, NULL, NULL, NULL, 0),
        (61, '2026-09-01', 'COBRO', 5, 3, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (62, '2026-09-01', 'COBRO', 6, 2, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (63, '2026-09-01', 'PAGOINV', 4, 0, 1.0, NULL, NULL, NULL, 0),
        (64, '2026-09-02', 'OP', 12, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (65, '2026-09-04', 'COBRO', 1, 3, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (66, '2026-09-08', 'COBRO', 7, 2, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (67, '2026-09-08', 'COBRO', 10, 1, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (68, '2026-09-10', 'LIQ', 13, 0, 1.0, NULL, NULL, NULL, 0),
        (69, '2026-09-10', 'COBRO', 2, 3, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (70, '2026-09-11', 'OP', 13, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (71, '2026-09-11', 'COBRO', 16, 3, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (72, '2026-09-14', 'COBRO', 8, 2, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (73, '2026-09-15', 'COBRO', 3, 3, 1.0, 'LOTE', 'NORMAL', '2026-09-21', 0),
        (74, '2026-09-15', 'COBRO', 9, 1, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (75, '2026-09-17', 'COBRO', 19, 3, 1.0, 'LOTE', 'NORMAL', '2026-09-21', 0),
        (76, '2026-09-18', 'LIQ', 14, 0, 1.0, NULL, NULL, NULL, 0),
        (77, '2026-09-19', 'OP', 14, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (78, '2026-09-19', 'COBRO', 11, 1, 1.0, 'LOTE', 'NORMAL', '2026-09-21', 0),
        (79, '2026-09-21', 'COBRO', 4, 3, 1.0, 'LOTE', 'NORMAL', '2026-09-21', 0),
        (80, '2026-09-25', 'COBRO', 15, 3, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (81, '2026-09-30', 'COBRO', 12, 1, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (82, '2026-09-30', 'MOVMES', 0, 0, 1.0, NULL, NULL, NULL, 0),
        (83, '2026-10-01', 'COBRO', 6, 3, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (84, '2026-10-03', 'COBRO', 1, 4, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (85, '2026-10-07', 'COBRO', 7, 3, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (86, '2026-10-08', 'COBRO', 2, 99, 1.0, 'EFECTIVO', 'CANCELACION', NULL, 0),
        (87, '2026-10-08', 'COBRO', 13, 1, 1.0, 'LOTE', 'NORMAL', '2026-10-20', 0),
        (88, '2026-10-10', 'COBRO', 10, 2, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (89, '2026-10-10', 'COBRO', 16, 4, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (90, '2026-10-13', 'COBRO', 8, 3, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (91, '2026-10-16', 'COBRO', 19, 4, 1.0, 'LOTE', 'NORMAL', '2026-10-20', 0),
        (92, '2026-10-17', 'COBRO', 9, 2, 1.0, 'EFECTIVO', 'NORMAL', NULL, 1),
        (93, '2026-10-18', 'COBRO', 14, 1, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (94, '2026-10-20', 'LIQ', 18, 0, 1.0, NULL, NULL, NULL, 0),
        (95, '2026-10-20', 'COBRO', 4, 4, 1.0, 'LOTE', 'NORMAL', '2026-10-20', 0),
        (96, '2026-10-21', 'OP', 18, 0, 1.0, 'TRANSFERENCIA', NULL, NULL, 0),
        (97, '2026-10-24', 'COBRO', 15, 4, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (98, '2026-10-30', 'COBRO', 12, 2, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (99, '2026-10-30', 'MOVMES', 0, 0, 1.0, NULL, NULL, NULL, 0),
        (100, '2026-11-03', 'COBRO', 6, 4, 0.5, 'EFECTIVO', 'NORMAL', NULL, 0),
        (101, '2026-11-05', 'COBRO', 1, 5, 1.0, 'BANCO_PEND', 'NORMAL', NULL, 0),
        (102, '2026-11-06', 'COBRO', 7, 4, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (103, '2026-11-09', 'COBRO', 10, 3, 1.0, 'BANCO_PEND', 'NORMAL', NULL, 0),
        (104, '2026-11-10', 'COBRO', 13, 2, 1.0, 'LOTE', 'NORMAL', '2026-11-18', 0),
        (105, '2026-11-12', 'COBRO', 16, 5, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (106, '2026-11-12', 'SUELTO', 85000, 0, 1.0, NULL, NULL, NULL, 0),
        (107, '2026-11-14', 'COBRO', 9, 3, 1.0, 'EFECTIVO', 'NORMAL', NULL, 0),
        (108, '2026-11-16', 'LIQINV', 1, 90, 1.0, 'CAPITAL', NULL, NULL, 0),
        (109, '2026-11-16', 'SOLEXTRA', 1, 0, 1.0, NULL, NULL, NULL, 0),
        (110, '2026-11-16', 'SOLEXTRA', 2, 0, 1.0, NULL, NULL, NULL, 0),
        (111, '2026-11-18', 'LIQ', 17, 0, 1.0, NULL, NULL, NULL, 0),
        (112, '2026-11-18', 'COBRO', 19, 5, 1.0, 'LOTE', 'NORMAL', '2026-11-18', 0),
        (113, '2026-11-19', 'LIQ', 20, 0, 1.0, NULL, NULL, NULL, 0),
        (114, '2026-11-19', 'COBRO', 18, 1, 1.0, 'BANCO', 'NORMAL', NULL, 0),
        (115, '2026-11-19', 'SOLEXTRA', 3, 0, 1.0, NULL, NULL, NULL, 0),
        (116, '2026-11-19', 'SOLEXTRA', 4, 0, 1.0, NULL, NULL, NULL, 0),
        (117, '2026-11-20', 'SOLEXTRA', 5, 0, 1.0, NULL, NULL, NULL, 0),
        (118, '2026-11-21', 'SOLEXTRA', 6, 0, 1.0, NULL, NULL, NULL, 0);
END $$

DELIMITER ;

DELIMITER $$

DROP PROCEDURE IF EXISTS tmp_ej_motor $$
CREATE PROCEDURE tmp_ej_motor()
BEGIN
    DECLARE v_id INT UNSIGNED;
    DECLARE v_fecha, v_f2 DATE;
    DECLARE v_tipo, v_medio, v_modo VARCHAR(20);
    DECLARE v_a, v_b, v_anular INT;
    DECLARE v_x DECIMAL(9,4);
    DECLARE v_n, v_cli, v_pre, v_sol, v_op, v_pag, v_rec, v_ext, v_imp, v_lote, v_inv, v_liq, v_nueva, v_mov, v_tipoas, v_vend INT UNSIGNED;
    DECLARE v_importe, v_monto, v_ingreso DECIMAL(15,2);
    DECLARE v_plazo SMALLINT;
    DECLARE v_tm TINYINT;
    DECLARE v_nro VARCHAR(20);
    DECLARE v_nrosol VARCHAR(20);
    DECLARE v_cuit CHAR(11);
    DECLARE v_cbu VARCHAR(22);
    DECLARE v_den VARCHAR(150);
    DECLARE v_tna DECIMAL(9,6);
    DECLARE v_cheque BIGINT UNSIGNED;
    DECLARE v_creado, v_resuelto DATETIME;
    DECLARE v_estado VARCHAR(20);
    DECLARE v_coment VARCHAR(500);

    -- 6. Recorrer los eventos en orden ---------------------------------------
    SET v_id = (SELECT MIN(id) FROM tmp_ej_eventos);
    WHILE v_id IS NOT NULL DO
        SELECT fecha, tipo, a, b, x, medio, modo, f2, anular
          INTO v_fecha, v_tipo, v_a, v_b, v_x, v_medio, v_modo, v_f2, v_anular
          FROM tmp_ej_eventos WHERE id = v_id;

        -- Un aporte: el alta de la pantalla de inversiones
        IF v_tipo = 'APORTE' THEN
            INSERT INTO inversiones (id_inversor, modalidad, moneda, capital, id_cuenta_fondos, medio_ingreso, referencia_ingreso,
                                     fecha_inicio, fecha_vencimiento, tna, pct_participacion, observaciones, creado_por)
            SELECT id_inversor, modalidad, moneda, capital, cuenta, medio,
                   IF(medio = 'TRANSFERENCIA', CONCAT('TRF ', LPAD(900000 + idx * 137, 8, '0')), NULL),
                   inicio, vto, tna, pct, IF(modalidad = 'PARTICIPACION', 'Participa en los intereses de dos préstamos a empresas', NULL), 1
              FROM tmp_ej_aportes WHERE idx = v_a;
            SET v_inv = LAST_INSERT_ID();
            UPDATE inversiones
               SET nro_inversion = CONCAT(COALESCE((SELECT valor FROM parametros WHERE clave = 'NRO_INVERSION_PREFIJO'), 'I-'), LPAD(v_inv, 6, '0'))
             WHERE id_inversion = v_inv;
            INSERT INTO inversiones_prestamos (id_inversion, id_prestamo, monto)
            SELECT v_inv, p.id_prestamo, f.monto
              FROM tmp_ej_fondea f JOIN tmp_ej_prestamos p ON p.idx = f.prestamo
             WHERE f.aporte = v_a;
            CALL sp_fondos_aporte(v_inv);
            UPDATE tmp_ej_aportes SET id_inversion = v_inv WHERE idx = v_a;

        -- Un préstamo: la solicitud (cargada, aprobada) y la liquidación
        ELSEIF v_tipo = 'LIQ' THEN
            SELECT c.id_tipo_asociado, c.id_vendedor, c.denominacion, COALESCE(cp.ingreso_mensual, 0), p.monto, p.plazo, p.tm
              INTO v_tipoas, v_vend, v_den, v_ingreso, v_monto, v_plazo, v_tm
              FROM tmp_ej_prestamos p
              JOIN clientes c ON c.id_cliente = p.idx
              LEFT JOIN clientes_personas cp ON cp.id_cliente = c.id_cliente
             WHERE p.idx = v_a;
            CALL sp_numerador(CONCAT('SOL-', YEAR(v_fecha)), v_n);
            SET v_nrosol = CONCAT(YEAR(v_fecha), '-', LPAD(v_n, 8, '0'));
            SET v_creado = TIMESTAMP(DATE_SUB(v_fecha, INTERVAL 3 DAY), SEC_TO_TIME(34200 + MOD(v_a * 1931, 25200)));
            SET v_resuelto = TIMESTAMP(DATE_SUB(v_fecha, INTERVAL 1 DAY), SEC_TO_TIME(36000 + MOD(v_a * 2711, 21600)));
            INSERT INTO solicitudes (nro_solicitud, id_cliente, id_tipo_asociado, id_vendedor, telemarketing, monto, plazo, destino,
                                     estado, version, creado_el, creado_por, enviado_el, controles_el, resuelto_el, resuelto_por, resolucion)
            VALUES (v_nrosol, v_a, v_tipoas, v_vend, v_tm, v_monto, v_plazo,
                    ELT(1 + MOD(v_a, 5), 'Refacción de la vivienda', 'Compra de un vehículo', 'Cancelar deudas', 'Capital de trabajo', 'Gastos personales'),
                    'APROBADA', 1, v_creado, IF(MOD(v_a, 2) = 0, 4, 2), v_creado, v_creado, v_resuelto, 1, 'Aprobada.');
            SET v_sol = LAST_INSERT_ID();
            INSERT INTO solicitudes_historial (id_solicitud, fecha, id_usuario, accion, comentario) VALUES
                (v_sol, v_creado, IF(MOD(v_a, 2) = 0, 4, 2), 'CREADA', NULL),
                (v_sol, v_resuelto, 1, 'APROBADA', 'Aprobada.');

            SET v_nro = CONCAT(COALESCE((SELECT valor FROM parametros WHERE clave = 'NRO_PRESTAMO_PREFIJO'), 'P-'),
                               LPAD(COALESCE((SELECT MAX(CAST(SUBSTRING(nro_prestamo, 3) AS UNSIGNED)) FROM prestamos), 0) + 1, 6, '0'));
            CALL sp_liquidar_prestamo(v_nro, v_a, v_tipoas, v_vend, v_monto, v_plazo, v_fecha, NULL, NULL, v_tm, 1, v_pre);

            UPDATE solicitudes s
              JOIN prestamos p ON p.id_prestamo = v_pre
               SET s.estado = 'LIQUIDADA', s.id_prestamo = v_pre, s.tna = p.tna, s.valor_cuota = p.valor_cuota,
                   s.neto_desembolso = p.neto_desembolso, s.total_a_pagar = (SELECT SUM(importe) FROM cuotas WHERE id_prestamo = v_pre),
                   s.controles = JSON_ARRAY(
                       JSON_OBJECT('c', 'BCRA_SIT', 'n', 'ok', 't', 'BCRA: sin deudas informadas (datos de ejemplo).'),
                       JSON_OBJECT('c', 'CHEQUES', 'n', 'ok', 't', 'Sin cheques rechazados.'),
                       JSON_OBJECT('c', 'DOC', 'n', 'advertencia', 't', 'Documentación incompleta (datos de ejemplo: no tienen archivos).'),
                       IF(v_ingreso > 0,
                          JSON_OBJECT('c', 'CUOTA_INGRESO', 'n', IF(p.valor_cuota / v_ingreso > 0.30, 'advertencia', 'ok'),
                                      't', CONCAT('Cuotas $ ', FORMAT(p.valor_cuota, 2, 'es_AR'), ' = ', FORMAT(p.valor_cuota / v_ingreso * 100, 1, 'es_AR'),
                                                  ' % del ingreso ($ ', FORMAT(v_ingreso, 2, 'es_AR'), ').')),
                          JSON_OBJECT('c', 'EMPRESA', 'n', 'info', 't', 'Empresa: no se controla la relación cuota / ingreso.')))
             WHERE s.id_solicitud = v_sol;
            UPDATE prestamos p
              JOIN solicitudes s ON s.id_solicitud = v_sol
               SET p.controles = s.controles,
                   p.controles_motivo = CONCAT('Solicitud ', v_nrosol, ' aprobada por ', (SELECT nombre FROM usuarios WHERE id_usuario = 1), ': Aprobada.')
             WHERE p.id_prestamo = v_pre;
            INSERT INTO solicitudes_historial (id_solicitud, fecha, id_usuario, accion, comentario)
            VALUES (v_sol, TIMESTAMP(v_fecha, '12:10:00'), 1, 'LIQUIDADA', CONCAT('Préstamo ', v_nro));
            UPDATE tmp_ej_prestamos SET id_prestamo = v_pre, id_solicitud = v_sol WHERE idx = v_a;

        -- El desembolso: Tesorería paga la orden de pago desde Nación
        ELSEIF v_tipo = 'OP' THEN
            SET v_pre = (SELECT id_prestamo FROM tmp_ej_prestamos WHERE idx = v_a);
            SET v_op = (SELECT id_orden FROM ordenes_pago WHERE id_prestamo = v_pre);
            IF v_medio = 'CHEQUE' THEN
                SET v_cheque = (SELECT proximo_numero FROM chequeras WHERE id_cuenta_fondos = 4 AND habilitada = 1 ORDER BY numero_desde LIMIT 1);
                CALL sp_emitir_pago_op(v_op, v_fecha, 4, 'CHEQUE', v_cheque, NULL, NULL, NULL, 3, v_pag);
            ELSE
                SET v_cbu = (SELECT cbu FROM clientes WHERE id_cliente = v_a);
                CALL sp_emitir_pago_op(v_op, v_fecha, 4, 'TRANSFERENCIA', NULL, v_cbu, NULL,
                                       CONCAT('TRF ', LPAD(4100000 + v_a * 911, 8, '0')), 3, v_pag);
            END IF;

        -- Un cobro: hasta la cuota b (99: cancelación anticipada), por el factor x
        ELSEIF v_tipo = 'COBRO' THEN
            SET v_pre = (SELECT id_prestamo FROM tmp_ej_prestamos WHERE idx = v_a);
            CALL sp_calc_deuda(v_pre, v_fecha, v_modo);
            SET v_importe = (SELECT ROUND(COALESCE(SUM(pendiente), 0) * v_x, 2) FROM tmp_deuda WHERE nro_cuota <= v_b);
            IF v_importe > 0 THEN
                IF v_medio = 'EFECTIVO' THEN
                    IF v_anular = 1 THEN
                        -- Un recibo con el importe mal cargado, anulado en el momento
                        CALL sp_registrar_pago(v_pre, v_fecha, ROUND(v_importe / 2, -2), 'EFECTIVO', NULL, 'NORMAL', NULL, 2, 1, 1, v_pag);
                        CALL sp_anular_recibo((SELECT id_recibo FROM pagos WHERE id_pago = v_pag),
                                              'Se cargó un importe equivocado: se vuelve a emitir con el correcto', 2);
                    END IF;
                    CALL sp_registrar_pago(v_pre, v_fecha, v_importe, 'EFECTIVO', NULL, v_modo, NULL, 2, 1, 1, v_pag);
                ELSEIF v_medio = 'LOTE' THEN
                    SET v_lote = (SELECT id_lote FROM tmp_ej_lotes WHERE mes = DATE_FORMAT(v_fecha, '%Y-%m'));
                    IF v_lote IS NULL THEN
                        INSERT INTO lotes_cobranza (medio, id_cuenta_fondos, fecha, archivo, estado, creado_por)
                        VALUES ('RAPIPAGO', 4, v_f2, CONCAT('RAPIPAGO_', DATE_FORMAT(v_f2, '%Y%m%d'), '.txt'), 'ABIERTO', 2);
                        SET v_lote = LAST_INSERT_ID();
                        INSERT INTO tmp_ej_lotes VALUES (DATE_FORMAT(v_fecha, '%Y-%m'), v_lote);
                    END IF;
                    CALL sp_registrar_pago(v_pre, v_fecha, v_importe, 'RAPIPAGO', CONCAT('RP ', LPAD(7700000 + v_a * 100 + v_b, 10, '0')),
                                           'NORMAL', v_lote, 2, 4, (SELECT id_talonario FROM talonarios WHERE nombre = 'Recibos de lotes' LIMIT 1), v_pag);
                ELSE
                    -- Por el banco: el crédito llega al extracto de ICBC recaudadora y se emite el recibo
                    SET v_imp = (SELECT id_importacion FROM tmp_ej_import WHERE mes = DATE_FORMAT(v_fecha, '%Y-%m'));
                    IF v_imp IS NULL THEN
                        INSERT INTO extractos_importaciones (id_cuenta_fondos, archivo, formato, resultado, renglones, insertados, repetidos, importado_por)
                        VALUES (8, CONCAT('ICBC_recaudadora_', DATE_FORMAT(v_fecha, '%Y-%m'), '.txt'), 'ICBC_REC', 'OK', 0, 0, 0, 3);
                        SET v_imp = LAST_INSERT_ID();
                        INSERT INTO tmp_ej_import VALUES (DATE_FORMAT(v_fecha, '%Y-%m'), v_imp);
                    END IF;
                    SELECT cuit, denominacion INTO v_cuit, v_den FROM clientes WHERE id_cliente = v_a;
                    INSERT INTO extractos_bancarios (id_cuenta_fondos, id_importacion, fecha, fecha_valor, referencia, debito, credito, concepto, cuit, origen)
                    VALUES (8, v_imp, v_fecha, v_fecha, LPAD(5200000 + v_a * 100 + v_b, 8, '0'), NULL, v_importe,
                            LEFT(CONCAT('CREDITO RECAUDACION — CUIL ', v_cuit, ' ', v_den), 200), v_cuit, 'ICBC');
                    SET v_ext = LAST_INSERT_ID();
                    IF v_medio = 'BANCO' THEN
                        CALL sp_emitir_recibo(v_a, v_fecha, v_importe, 'TRANSFERENCIA', LPAD(5200000 + v_a * 100 + v_b, 8, '0'),
                                              8, (SELECT id_talonario FROM talonarios WHERE nombre = 'Recibos de cobranza' LIMIT 1),
                                              v_ext, v_cuit, 3, v_rec);
                    END IF;
                END IF;
            END IF;

        -- Un crédito del extracto que no es de ningún cliente (queda para asignar)
        ELSEIF v_tipo = 'SUELTO' THEN
            SET v_imp = (SELECT id_importacion FROM tmp_ej_import WHERE mes = DATE_FORMAT(v_fecha, '%Y-%m'));
            IF v_imp IS NULL THEN
                INSERT INTO extractos_importaciones (id_cuenta_fondos, archivo, formato, resultado, renglones, insertados, repetidos, importado_por)
                VALUES (8, CONCAT('ICBC_recaudadora_', DATE_FORMAT(v_fecha, '%Y-%m'), '.txt'), 'ICBC_REC', 'OK', 0, 0, 0, 3);
                SET v_imp = LAST_INSERT_ID();
                INSERT INTO tmp_ej_import VALUES (DATE_FORMAT(v_fecha, '%Y-%m'), v_imp);
            END IF;
            INSERT INTO extractos_bancarios (id_cuenta_fondos, id_importacion, fecha, fecha_valor, referencia, debito, credito, concepto, cuit, origen)
            VALUES (8, v_imp, v_fecha, v_fecha, '59990001', NULL, v_a, 'CREDITO RECAUDACION — CUIL 20184526388 ORTELLADO BRIZUELA MANUEL', '20184526388', 'ICBC');

        -- La liquidación de un aporte (con renovación o sin)
        ELSEIF v_tipo = 'LIQINV' THEN
            SET v_inv = (SELECT id_inversion FROM tmp_ej_aportes WHERE idx = v_a);
            CALL sp_liquidar_inversion(v_inv, v_fecha, v_medio, IF(v_b > 0, v_b, NULL), 1, v_liq, v_nueva);
            UPDATE tmp_ej_aportes SET id_liq = v_liq, id_inversion = COALESCE(v_nueva, v_inv) WHERE idx = v_a;

        -- El pago al inversor de su última liquidación
        ELSEIF v_tipo = 'PAGOINV' THEN
            SET v_liq = (SELECT id_liq FROM tmp_ej_aportes WHERE idx = v_a);
            UPDATE inversiones_liquidaciones
               SET estado = 'PAGADA', pagado_el = v_fecha, medio = 'TRANSFERENCIA',
                   referencia = CONCAT('TRF ', LPAD(8800000 + v_liq, 8, '0')), id_cuenta_fondos = 4
             WHERE id_liquidacion = v_liq AND estado = 'A_PAGAR';
            CALL sp_fondos_pago_inversor(v_liq);

        -- Fin de mes: la caja se deposita en Nación y la recaudadora pasa a Nación
        ELSEIF v_tipo = 'MOVMES' THEN
            SET v_importe = (SELECT FLOOR(COALESCE(SUM(importe), 0) / 1000) * 1000 FROM recibos
                              WHERE estado = 'APLICADO' AND id_cuenta_fondos = 1 AND fecha BETWEEN DATE_FORMAT(v_fecha, '%Y-%m-01') AND v_fecha);
            IF v_importe > 0 THEN
                INSERT INTO movimientos_entre_cuentas (fecha, tipo, id_cuenta_origen, id_cuenta_destino, importe, referencia, comentario, creado_por)
                VALUES (v_fecha, 'DEPOSITO', 1, 4, v_importe, CONCAT('Boleta ', LPAD(MONTH(v_fecha) * 1000 + 17, 6, '0')),
                        'Depósito de la cobranza en efectivo del mes', 3);
                SET v_mov = LAST_INSERT_ID();
                CALL sp_fondos_movimiento(v_mov);
            END IF;
            SET v_importe = (SELECT COALESCE(SUM(importe), 0) FROM recibos
                              WHERE estado = 'APLICADO' AND id_cuenta_fondos = 8 AND fecha BETWEEN DATE_FORMAT(v_fecha, '%Y-%m-01') AND v_fecha);
            IF v_importe > 0 THEN
                INSERT INTO movimientos_entre_cuentas (fecha, tipo, id_cuenta_origen, id_cuenta_destino, importe, referencia, comentario, creado_por)
                VALUES (v_fecha, 'ENTRE_CUENTAS', 8, 4, v_importe, CONCAT('TRF ', LPAD(MONTH(v_fecha) * 10000 + 8, 8, '0')),
                        'Pase de lo cobrado por la recaudadora', 3);
                SET v_mov = LAST_INSERT_ID();
                CALL sp_fondos_movimiento(v_mov);
            END IF;

        -- Una solicitud que no se liquidó (pendiente, observada, aprobada o rechazada)
        ELSEIF v_tipo = 'SOLEXTRA' THEN
            SELECT s.cliente, t.id_tipo_asociado, c.id_vendedor, s.monto, s.plazo, s.tm, s.estado, s.creado, s.resuelto, s.por, s.comentario
              INTO v_cli, v_tipoas, v_vend, v_monto, v_plazo, v_tm, v_estado, v_creado, v_resuelto, v_n, v_coment
              FROM tmp_ej_sol s
              JOIN tipos_asociado t ON t.codigo = s.tipo
              JOIN clientes c ON c.id_cliente = s.cliente
             WHERE s.idx = v_a;
            IF v_coment = 'rechazo' THEN
                SET v_coment = CONCAT('Rechazada: el préstamo ', (SELECT p.nro_prestamo FROM prestamos p WHERE p.id_cliente = v_cli LIMIT 1),
                                      ' tiene cuotas vencidas sin pagar.');
            END IF;
            SET v_tna = (SELECT tna FROM tasas_vigencia WHERE id_tasa = fn_tasa_vigente(v_tipoas, v_plazo, v_monto, DATE(v_creado)));
            CALL sp_cuadro_frances(v_monto, v_plazo, v_tna, 0.21, DATE_ADD(DATE(v_creado), INTERVAL 1 MONTH));
            SET v_ingreso = (SELECT COALESCE(ingreso_mensual, 0) FROM clientes_personas WHERE id_cliente = v_cli);
            CALL sp_numerador(CONCAT('SOL-', YEAR(v_creado)), v_b);
            INSERT INTO solicitudes (nro_solicitud, id_cliente, id_tipo_asociado, id_vendedor, telemarketing, monto, plazo, destino,
                                     tna, valor_cuota, neto_desembolso, total_a_pagar, controles, controles_el,
                                     estado, version, creado_el, creado_por, enviado_el, resuelto_el, resuelto_por, resolucion)
            SELECT CONCAT(YEAR(v_creado), '-', LPAD(v_b, 8, '0')), v_cli, v_tipoas, v_vend, v_tm, v_monto, v_plazo, 'Gastos personales',
                   v_tna, SUM(IF(nro_cuota = 1, importe, 0)), v_monto, SUM(importe),
                   JSON_ARRAY(JSON_OBJECT('c', 'BCRA_SIT', 'n', 'ok', 't', 'BCRA: sin deudas informadas (datos de ejemplo).'),
                              JSON_OBJECT('c', 'DOC', 'n', 'advertencia', 't', 'Documentación incompleta (datos de ejemplo: no tienen archivos).')),
                   v_creado, v_estado, 1, v_creado, 4, v_creado,
                   IF(v_estado IN ('APROBADA', 'RECHAZADA', 'OBSERVADA'), v_resuelto, NULL),
                   IF(v_estado IN ('APROBADA', 'RECHAZADA', 'OBSERVADA'), v_n, NULL),
                   IF(v_estado IN ('APROBADA', 'RECHAZADA', 'OBSERVADA'), v_coment, NULL)
              FROM tmp_cuadro;
            SET v_sol = LAST_INSERT_ID();
            INSERT INTO solicitudes_historial (id_solicitud, fecha, id_usuario, accion, comentario) VALUES (v_sol, v_creado, 4, 'CREADA', NULL);
            IF v_estado <> 'PENDIENTE' THEN
                INSERT INTO solicitudes_historial (id_solicitud, fecha, id_usuario, accion, comentario) VALUES (v_sol, v_resuelto, v_n, v_estado, v_coment);
            END IF;
        END IF;

        SET v_id = (SELECT MIN(id) FROM tmp_ej_eventos WHERE id > v_id);
    END WHILE;

    -- 7. Cierres y fechas de carga ---------------------------------------------
    -- Los lotes de Rapipago, procesados
    UPDATE lotes_cobranza l
       SET l.registros = (SELECT COUNT(*) FROM recibos r WHERE r.id_lote = l.id_lote AND r.estado = 'APLICADO'),
           l.total     = (SELECT COALESCE(SUM(r.importe), 0) FROM recibos r WHERE r.id_lote = l.id_lote AND r.estado = 'APLICADO'),
           l.estado    = 'PROCESADO',
           l.creado_el = TIMESTAMP(DATE_ADD(l.fecha, INTERVAL 1 DAY), '09:15:00');
    UPDATE lotes_cobranza SET creado_el = LEAST(creado_el, NOW());
    -- Las importaciones del extracto: una por mes, el día después del último movimiento
    UPDATE extractos_importaciones i
       SET i.renglones = (SELECT COUNT(*) FROM extractos_bancarios e WHERE e.id_importacion = i.id_importacion),
           i.insertados = (SELECT COUNT(*) FROM extractos_bancarios e WHERE e.id_importacion = i.id_importacion),
           i.total_debitos = 0,
           i.total_creditos = (SELECT SUM(e.credito) FROM extractos_bancarios e WHERE e.id_importacion = i.id_importacion),
           i.fecha_desde = (SELECT MIN(e.fecha) FROM extractos_bancarios e WHERE e.id_importacion = i.id_importacion),
           i.fecha_hasta = (SELECT MAX(e.fecha) FROM extractos_bancarios e WHERE e.id_importacion = i.id_importacion);
    UPDATE extractos_importaciones
       SET importado_el = LEAST(TIMESTAMP(DATE_ADD(fecha_hasta, INTERVAL 1 DAY), '09:30:00'), NOW());
    UPDATE extractos_bancarios e
      JOIN extractos_importaciones i ON i.id_importacion = e.id_importacion
       SET e.importado_el = i.importado_el;

    -- Cada cosa con la fecha y la hora en que se habría hecho
    UPDATE prestamos SET creado_el = TIMESTAMP(fecha_liquidacion, '12:10:00');
    UPDATE movimientos SET creado_el = TIMESTAMP(fecha, '12:10:00') WHERE tipo IN ('DESEMBOLSO', 'GASTO_OTORG');
    UPDATE comisiones_devengadas SET creado_el = TIMESTAMP(fecha, '12:10:00');
    UPDATE ordenes_pago SET creado_el = TIMESTAMP(fecha, '12:10:00');
    UPDATE pagos_emitidos SET creado_el = TIMESTAMP(fecha, SEC_TO_TIME(39600 + MOD(id_pago_emitido * 1373, 18000)));
    UPDATE recibos SET creado_el = TIMESTAMP(fecha, SEC_TO_TIME(33300 + MOD(id_recibo * 1373, 28800)))
     WHERE id_extracto IS NULL AND id_lote IS NULL;
    UPDATE recibos r
      JOIN extractos_bancarios e ON e.id_extracto = r.id_extracto
      JOIN extractos_importaciones i ON i.id_importacion = e.id_importacion
       SET r.creado_el = i.importado_el + INTERVAL (5 + MOD(r.id_recibo, 50)) MINUTE;
    UPDATE recibos r JOIN lotes_cobranza l ON l.id_lote = r.id_lote
       SET r.creado_el = l.creado_el + INTERVAL 2 MINUTE;
    UPDATE recibos SET anulado_el = creado_el + INTERVAL 4 MINUTE WHERE estado = 'ANULADO';
    UPDATE pagos p JOIN recibos r ON r.id_recibo = p.id_recibo
       SET p.creado_el = r.creado_el, p.anulado_el = IF(p.estado = 'ANULADO', r.anulado_el, p.anulado_el);
    UPDATE movimientos m JOIN pagos p ON p.id_pago = m.id_pago SET m.creado_el = p.creado_el;
    UPDATE movimientos_entre_cuentas SET creado_el = TIMESTAMP(fecha, '17:30:00');
    UPDATE inversiones SET creado_el = TIMESTAMP(fecha_inicio, '11:20:00');
    UPDATE inversiones_liquidaciones SET creado_el = TIMESTAMP(fecha, '11:40:00');
    UPDATE movimientos_fondos m
       SET m.creado_el = COALESCE((SELECT r.creado_el FROM recibos r WHERE r.id_recibo = m.id_recibo),
                                  (SELECT pe.creado_el FROM pagos_emitidos pe WHERE pe.id_pago_emitido = m.id_pago_emitido),
                                  TIMESTAMP(m.fecha, '12:00:00'));
    UPDATE movimientos_fondos SET anulado_el = creado_el + INTERVAL 4 MINUTE WHERE anulado_el IS NOT NULL;
    -- Lo del banco de hace más de tres días ya está acreditado
    UPDATE debitos_creditos_bancarios d
      JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
       SET d.creado_el = m.creado_el;
    UPDATE debitos_creditos_bancarios d
      JOIN movimientos_fondos m ON m.id_mov_fondos = d.id_mov_fondos
       SET d.fecha_banco = DATE_ADD(m.fecha, INTERVAL IF(d.tipo_instrumento = 'CHEQUE', 6, 1) DAY)
     WHERE m.fecha < DATE_SUB(CURDATE(), INTERVAL 3 DAY) AND d.estado IN ('EMITIDO', 'ENTREGADO');
    UPDATE debitos_creditos_bancarios
       SET estado = 'ACREDITADO'
     WHERE fecha_banco IS NOT NULL AND estado IN ('EMITIDO', 'ENTREGADO');

    -- Algunas observaciones de seguimiento
    INSERT INTO clientes_observaciones (id_cliente, id_prestamo, id_motivo, texto, recordatorio, creado_el, creado_por)
    SELECT 6, (SELECT id_prestamo FROM prestamos WHERE id_cliente = 6 LIMIT 1), (SELECT id_motivo FROM seguimiento_motivos WHERE nombre = 'Promesa pago' LIMIT 1),
           'Dice que paga el saldo de la cuota 4 el viernes.', '2026-11-27', '2026-11-20 15:10:00', 2
    UNION ALL
    SELECT 13, (SELECT id_prestamo FROM prestamos WHERE id_cliente = 13 LIMIT 1), (SELECT id_motivo FROM seguimiento_motivos WHERE nombre = 'Moroso' LIMIT 1),
           'No contesta el celular. Se le mandó un mensaje.', '2026-11-25', '2026-11-19 11:30:00', 2
    UNION ALL
    SELECT 22, NULL, (SELECT id_motivo FROM seguimiento_motivos WHERE nombre = 'Rellamado' LIMIT 1),
           'Interesado en un préstamo en diciembre, volver a llamar.', '2026-12-02', '2026-11-18 10:00:00', 4;

END $$

CALL tmp_ej_carga() $$
CALL tmp_ej_motor() $$
DROP PROCEDURE IF EXISTS tmp_ej_carga $$
DROP PROCEDURE IF EXISTS tmp_ej_motor $$

DELIMITER ;

DROP TABLE IF EXISTS tmp_ej_eventos;
DROP TABLE IF EXISTS tmp_ej_prestamos;
DROP TABLE IF EXISTS tmp_ej_aportes;
DROP TABLE IF EXISTS tmp_ej_fondea;
DROP TABLE IF EXISTS tmp_ej_sol;
DROP TABLE IF EXISTS tmp_ej_lotes;
DROP TABLE IF EXISTS tmp_ej_import;

-- ---------------------------------------------------------------------
-- Controles
-- ---------------------------------------------------------------------
-- Los préstamos: estado, cuotas pagadas y en mora
SELECT p.nro_prestamo, c.denominacion, t.codigo AS tipo, p.plazo, p.capital, p.estado,
       (SELECT COUNT(*) FROM cuotas q WHERE q.id_prestamo = p.id_prestamo AND q.estado = 'PAGADA') AS pagadas,
       (SELECT COUNT(*) FROM cuotas q WHERE q.id_prestamo = p.id_prestamo AND q.estado <> 'PAGADA' AND q.vencimiento < CURDATE()) AS vencidas,
       ROUND(p.comision_pct * 100, 2) AS comision_pct, p.comision_monto,
       (SELECT o.estado FROM ordenes_pago o WHERE o.id_prestamo = p.id_prestamo) AS orden_de_pago
  FROM prestamos p JOIN clientes c ON c.id_cliente = p.id_cliente JOIN tipos_asociado t ON t.id_tipo_asociado = p.id_tipo_asociado
 ORDER BY p.nro_prestamo;

-- Los saldos de las cuentas de fondos (ninguno negativo)
SELECT * FROM v_saldos_cuentas_fondos;

-- Recibos por origen
SELECT IF(id_extracto IS NOT NULL, 'banco', IF(id_lote IS NOT NULL, 'lote', 'efectivo')) AS origen, estado, COUNT(*) AS recibos, SUM(importe) AS importe
  FROM recibos GROUP BY 1, 2 ORDER BY 1, 2;

