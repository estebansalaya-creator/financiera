-- =====================================================================
--  FINANCIERA - Datos de ejemplo
--
--  SOLO PARA LA BASE DE PRUEBA. Borra los clientes, solicitudes,
--  préstamos, cobranzas, movimientos de fondos, órdenes de pago, extractos e
--  inversiones, y carga de nuevo, todo con los procedures del sistema (lo
--  mismo que harían las pantallas, con las fechas de cada operación):
--
--    20 clientes (14 personas y 6 empresas), con su tipo de asociado
--       (REGGIO DC, HABERES ACTIVOS y AP ACTIVOS), repartición, vendedor,
--       domicilio, celular y CBU
--    20 préstamos de 3 a 60 cuotas, liquidados entre junio y noviembre de
--       2026 desde su solicitud aprobada, con la comisión del vendedor
--       según las escalas: al día, en mora de 1 a 3 cuotas, con pagos
--       atrasados (punitorios), un pago parcial, uno cancelado por
--       anticipado, uno pagado entero y dos recién liquidados con la orden
--       de pago todavía sin pagar
--    los desembolsos (órdenes de pago pagadas por transferencia o cheque
--       desde Nación cuenta corriente)
--    los cobros: en efectivo (Caja en pesos), por el banco (créditos del
--       extracto de ICBC recaudadora con su recibo; dos de noviembre y uno
--       sin cliente quedan pendientes en Cobros bancarios) y por lotes de
--       Rapipago; un recibo anulado
--    el depósito de la caja y el pase de la recaudadora a Nación de cada
--       mes, hasta octubre
--    4 inversores y 6 aportes: tasa fija en pesos y en dólares, uno que se
--       renovó (y su renovación ya vencida, a pagar), uno liquidado y
--       pagado, y uno a participación que fondea dos préstamos
--    4 solicitudes sin liquidar: pendiente, observada, aprobada (para
--       probar la liquidación) y rechazada
--
--  Quedan como estaban: tipos de asociado, escalas de comisión,
--  reparticiones, vendedores, usuarios, cuentas de fondos, chequeras,
--  talonarios (los de recibos vuelven a empezar en 1), cotizaciones,
--  menú y parámetros. Las consultas al BCRA quedan, sin cliente. Los
--  archivos de la documentación de los clientes borrados no se tocan.
--  Carga las tasas de los tres tipos de asociado desde el 01/01/2026 si no
--  tienen una (las de AP ACTIVOS desde el 19/11/2026 quedan).
--
--  Después de financiera_tipos_asociado.sql. Se puede correr de nuevo:
--  vuelve a empezar de cero. En DBeaver: Ejecutar script (Alt+X); cada
--  cobro devuelve el detalle de su imputación, son pestañas de resultado
--  que se pueden cerrar. Lo que se va a hacer queda en tablas tmp_ej_*
--  que el script borra al final.
-- =====================================================================

DELIMITER $$

-- La limpieza y los datos de partida
DROP PROCEDURE IF EXISTS tmp_ej_carga $$
CREATE PROCEDURE tmp_ej_carga()
BEGIN
    -- 1. Limpieza ---------------------------------------------------------
    SET FOREIGN_KEY_CHECKS = 0;
    DELETE m FROM chat_mensajes m JOIN chat_conversaciones c ON c.id_conversacion = m.id_conversacion WHERE c.id_solicitud IS NOT NULL;
    DELETE p FROM chat_participantes p JOIN chat_conversaciones c ON c.id_conversacion = p.id_conversacion WHERE c.id_solicitud IS NOT NULL;
    DELETE FROM chat_conversaciones WHERE id_solicitud IS NOT NULL;
    UPDATE consultas_bcra SET id_cliente = NULL;
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
    SET FOREIGN_KEY_CHECKS = 1;
    UPDATE talonarios t
      JOIN talonarios_clasificaciones c ON c.id_clasificacion = t.id_clasificacion
      JOIN comprobantes_tipos ct ON ct.id_comprobante_tipo = c.id_comprobante_tipo
       SET t.ultimo_emitido = t.numero_desde - 1
     WHERE ct.nombre = 'Recibo' AND t.electronico = 0;
    UPDATE chequeras SET proximo_numero = numero_desde;

    -- 2. Tasas de los tres tipos de asociado (si no tienen una de antes) ----
    INSERT INTO tasas_vigencia (id_tipo_asociado, vigencia_desde, plazo_desde, plazo_hasta, monto_desde, monto_hasta, tna, creado_por)
    SELECT t.id_tipo_asociado, '2026-01-01', t.plazo_min, t.plazo_max, t.monto_min, t.monto_max, x.tna, 1
      FROM tipos_asociado t
      JOIN (SELECT 'REGGIO_DC' AS codigo, 0.95 AS tna UNION ALL SELECT 'HAB_ACTIVOS', 0.80 UNION ALL SELECT 'AP_ACTIVOS', 0.90) x
        ON x.codigo = t.codigo
     WHERE NOT EXISTS (SELECT 1 FROM tasas_vigencia v WHERE v.id_tipo_asociado = t.id_tipo_asociado AND v.vigencia_desde <= '2026-06-01');

    -- 3. Clientes -------------------------------------------------------------
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (1, 'F', '27284561231', 'GOMEZ MARIA LAURA', 'maria.gomez@correo-ejemplo.com.ar', '0110100900040284561231', 'MARIA.GOMEZ.CTA', 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'REGGIO_DC'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'MINISTERIO DE EDUCACION'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALMIRON'), 'ACTIVO', '2026-05-29 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (1, 'GOMEZ', 'MARIA LAURA', '28456123', '1981-03-14', 'F', 'CASADO', 'Docente', 'Dirección General de Escuelas', 850000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (1, 'CELULAR', '5492214567812', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (1, 'REAL', 'Calle 12', '1430', 'La Plata', 'Buenos Aires', '1900', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (2, 'F', '20257894569', 'FERNANDEZ JUAN CARLOS', 'juan.fernandez@correo-ejemplo.com.ar', '0140137600040257894560', 'JUAN.FERNANDEZ.CTA', 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'HAB_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'IPS 456'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALONSO'), 'ACTIVO', '2026-06-02 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (2, 'FERNANDEZ', 'JUAN CARLOS', '25789456', '1977-08-02', 'M', 'CASADO', 'Empleado administrativo', 'Municipalidad de Quilmes', 720000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (2, 'CELULAR', '5491145782310', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (2, 'REAL', 'Av. Mitre', '856', 'Quilmes', 'Buenos Aires', '1878', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (3, 'F', '27331245688', 'RODRIGUEZ ANA BELEN', 'ana.rodriguez@correo-ejemplo.com.ar', '0170174200040331245680', NULL, 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'HAB_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'IPS 789'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'AMTAE'), 'ACTIVO', '2026-06-06 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (3, 'RODRIGUEZ', 'ANA BELEN', '33124568', '1987-11-23', 'F', 'SOLTERO', 'Enfermera', 'Hospital de Florencio Varela', 910000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (3, 'CELULAR', '5491156893421', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (3, 'REAL', 'Monteagudo', '312', 'Florencio Varela', 'Buenos Aires', '1888', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (4, 'F', '20305678911', 'LOPEZ DIEGO MARTIN', 'diego.lopez@correo-ejemplo.com.ar', '0070211100040305678915', 'DIEGO.LOPEZ.CTA', 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'REGGIO_DC'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'POLICIA'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ARROYO'), 'ACTIVO', '2026-06-12 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (4, 'LOPEZ', 'DIEGO MARTIN', '30567891', '1984-05-30', 'M', 'CONVIVIENTE', 'Suboficial', 'Policía de la Provincia', 1050000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (4, 'CELULAR', '5491167349812', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (4, 'REAL', 'Boedo', '745', 'Lomas de Zamora', 'Buenos Aires', '1832', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (5, 'F', '27223456788', 'MARTINEZ SILVIA NOEMI', 'silvia.martinez@correo-ejemplo.com.ar', '0720248500040223456781', 'SILVIA.MARTINEZ.CTA', 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'REGGIO_DC'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'ANSES MINIMA 8'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALMIRON'), 'ACTIVO', '2026-05-28 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (5, 'MARTINEZ', 'SILVIA NOEMI', '22345678', '1960-01-17', 'F', 'VIUDO', 'Jubilada', 'ANSES', 480000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (5, 'CELULAR', '5491134561278', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (5, 'REAL', 'Av. Belgrano', '2210', 'Avellaneda', 'Buenos Aires', '1870', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (6, 'F', '20356789122', 'SOSA PABLO EZEQUIEL', 'pablo.sosa@correo-ejemplo.com.ar', '2850285600040356789125', NULL, 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'HAB_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'ACTIVOS ANSES'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALONSO'), 'ACTIVO', '2026-06-24 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (6, 'SOSA', 'PABLO EZEQUIEL', '35678912', '1990-09-08', 'M', 'SOLTERO', 'Chofer', 'Transportes Línea 148', 780000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (6, 'CELULAR', '5491145672390', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (6, 'REAL', 'Sitio de Montevideo', '1180', 'Lanús', 'Buenos Aires', '1824', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (7, 'F', '27314567892', 'ROMERO CAROLINA ANDREA', 'carolina.romero@correo-ejemplo.com.ar', '1910322200040314567895', 'CAROLINA.ROMERO.CTA', 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'HAB_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'IPS 789'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'AMTAE'), 'ACTIVO', '2026-06-30 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (7, 'ROMERO', 'CAROLINA ANDREA', '31456789', '1985-12-04', 'F', 'CASADO', 'Administrativa', 'Clínica del Oeste', 830000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (7, 'CELULAR', '5491162348907', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (7, 'REAL', 'Belgrano', '540', 'Morón', 'Buenos Aires', '1708', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (8, 'F', '20245678909', 'TORRES LUIS ALBERTO', 'luis.torres@correo-ejemplo.com.ar', '1500359500040245678909', 'LUIS.TORRES.CTA', 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'REGGIO_DC'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'MINISTERIO DE EDUCACION'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ARROYO'), 'ACTIVO', '2026-07-06 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (8, 'TORRES', 'LUIS ALBERTO', '24567890', '1975-04-19', 'M', 'DIVORCIADO', 'Docente', 'Dirección General de Escuelas', 760000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (8, 'CELULAR', '5492234561290', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (8, 'REAL', 'Av. Independencia', '3120', 'Mar del Plata', 'Buenos Aires', '7600', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (9, 'F', '27345671248', 'DIAZ GABRIELA SOLEDAD', 'gabriela.diaz@correo-ejemplo.com.ar', '0340396700040345671244', NULL, 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'HAB_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'ACTIVOS ANSES'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALMIRON'), 'ACTIVO', '2026-07-24 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (9, 'DIAZ', 'GABRIELA SOLEDAD', '34567124', '1989-07-11', 'F', 'SOLTERO', 'Empleada de comercio', 'Supermercados del Sur', 690000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (9, 'CELULAR', '5492914562378', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (9, 'REAL', 'Brown', '845', 'Bahía Blanca', 'Buenos Aires', '8000', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (10, 'F', '20278901239', 'ACOSTA MARCELO DANIEL', 'marcelo.acosta@correo-ejemplo.com.ar', '0440433400040278901236', 'MARCELO.ACOSTA.CTA', 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'IPS 123'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALONSO'), 'ACTIVO', '2026-08-04 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (10, 'ACOSTA', 'MARCELO DANIEL', '27890123', '1980-02-27', 'M', 'CASADO', 'Enfermero', 'Hospital Posadas', 880000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (10, 'CELULAR', '5491147893456', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (10, 'REAL', 'Av. Martín Fierro', '3356', 'El Palomar', 'Buenos Aires', '1684', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (11, 'F', '27290123459', 'BENITEZ ROXANA ELIZABETH', 'roxana.benitez@correo-ejemplo.com.ar', '0110470100040290123454', 'ROXANA.BENITEZ.CTA', 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'MINISTERIO DE EDUCACION'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'AMTAE'), 'ACTIVO', '2026-08-13 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (11, 'BENITEZ', 'ROXANA ELIZABETH', '29012345', '1982-10-15', 'F', 'CONVIVIENTE', 'Auxiliar escolar', 'Dirección General de Escuelas', 610000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (11, 'CELULAR', '5491159234578', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (11, 'REAL', 'Libertador', '1520', 'Merlo', 'Buenos Aires', '1722', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (12, 'F', '20212345688', 'MEDINA JORGE OSVALDO', 'jorge.medina@correo-ejemplo.com.ar', '0140507100040212345689', NULL, 'CF',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'REGGIO_DC'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'ANSES MAXIMA 8-9'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ARROYO'), 'ACTIVO', '2026-08-24 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (12, 'MEDINA', 'JORGE OSVALDO', '21234568', '1958-06-21', 'M', 'CASADO', 'Jubilado', 'ANSES', 520000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (12, 'CELULAR', '5491143218765', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (12, 'REAL', 'Arieta', '2845', 'San Justo', 'Buenos Aires', '1754', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (13, 'F', '27367890121', 'HERRERA LUCIA MARIEL', 'lucia.herrera@correo-ejemplo.com.ar', '0170544700040367890128', 'LUCIA.HERRERA.CTA', 'MONO',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'HAB_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'PENSION GRACIABLE 2-3'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALMIRON'), 'ACTIVO', '2026-09-01 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (13, 'HERRERA', 'LUCIA MARIEL', '36789012', '1992-03-03', 'F', 'SOLTERO', 'Peluquera (cuenta propia)', NULL, 700000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (13, 'CELULAR', '5493416789012', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (13, 'REAL', 'Córdoba', '1630', 'Rosario', 'Santa Fe', '2000', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (14, 'F', '20389012344', 'RUIZ MATIAS NICOLAS', 'matias.ruiz@correo-ejemplo.com.ar', '0070581300040389012346', 'MATIAS.RUIZ.CTA', 'MONO',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            (SELECT id_reparticion FROM reparticiones WHERE nombre = 'ACTIVOS ANSES'),
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALONSO'), 'ACTIVO', '2026-09-08 10:30:00', 2);
    INSERT INTO clientes_personas (id_cliente, apellido, nombres, dni, fecha_nac, sexo, estado_civil, ocupacion, empleador, ingreso_mensual)
    VALUES (14, 'RUIZ', 'MATIAS NICOLAS', '38901234', '1995-12-29', 'M', 'SOLTERO', 'Técnico electricista', NULL, 950000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (14, 'CELULAR', '5493516723489', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (14, 'REAL', 'Av. Colón', '1245', 'Córdoba', 'Córdoba', '5000', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (15, 'J', '30712345604', 'DISTRIBUIDORA DEL SUR S.R.L.', 'administracion@delsur-ejemplo.com.ar', '0070200500090712345603', 'DELSUR.EMPRESA', 'RI',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            NULL,
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ARROYO'), 'ACTIVO', '2026-06-19 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (15, 'DISTRIBUIDORA DEL SUR S.R.L.', 'DEL SUR', 'SRL', 'Distribución mayorista de alimentos', '2012-04-10', 185000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (15, 'FIJO', '541142095566', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (15, 'COMERCIAL', 'Av. Hipólito Yrigoyen', '4512', 'Avellaneda', 'Buenos Aires', '1870', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (16, 'J', '30709876542', 'TRANSPORTES LA COSTA S.A.', 'administracion@lacostacargas-ejemplo.com.ar', '0720241600090709876543', 'LACOSTACARGAS.EMPRES', 'RI',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            NULL,
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALMIRON'), 'ACTIVO', '2026-06-05 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (16, 'TRANSPORTES LA COSTA S.A.', 'LA COSTA CARGAS', 'SA', 'Transporte de cargas', '2005-09-01', 420000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (16, 'FIJO', '542234780011', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (16, 'COMERCIAL', 'Av. Juan B. Justo', '5420', 'Mar del Plata', 'Buenos Aires', '7600', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (17, 'J', '30718765435', 'CONSTRUCTORA PAMPA S.A.S.', 'administracion@pampaobras-ejemplo.com.ar', '2850282500090718765430', 'PAMPAOBRAS.EMPRESA', 'RI',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            NULL,
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALONSO'), 'ACTIVO', '2026-11-10 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (17, 'CONSTRUCTORA PAMPA S.A.S.', 'PAMPA OBRAS', 'SAS', 'Construcción de viviendas', '2019-02-18', 260000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (17, 'FIJO', '542214270099', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (17, 'COMERCIAL', 'Calle 7', '980', 'La Plata', 'Buenos Aires', '1900', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (18, 'J', '30713456787', 'AGROSERVICIOS DEL OESTE S.R.L.', 'administracion@agrooeste-ejemplo.com.ar', '1910323900090713456788', 'AGROOESTE.EMPRESA', 'RI',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            NULL,
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'AMTAE'), 'ACTIVO', '2026-10-11 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (18, 'AGROSERVICIOS DEL OESTE S.R.L.', 'AGRO OESTE', 'SRL', 'Servicios agrícolas', '2010-07-05', 310000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (18, 'FIJO', '542392417733', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (18, 'COMERCIAL', 'Av. García Salinas', '1240', 'Trenque Lauquen', 'Buenos Aires', '6400', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (19, 'J', '30715678906', 'FARMACIA CENTRAL QUILMES S.R.L.', 'administracion@farmaciacentral-ejemplo.com.ar', '1500364900090715678900', 'FARMACIACENTRAL.EMPR', 'RI',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            NULL,
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ARROYO'), 'ACTIVO', '2026-06-08 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (19, 'FARMACIA CENTRAL QUILMES S.R.L.', 'FARMACIA CENTRAL', 'SRL', 'Farmacia', '2008-11-20', 140000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (19, 'FIJO', '541142538800', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (19, 'COMERCIAL', 'Rivadavia', '245', 'Quilmes', 'Buenos Aires', '1878', 1);
    INSERT INTO clientes (id_cliente, tipo, cuit, denominacion, email, cbu, alias_cbu, condicion_iva, id_tipo_asociado, id_reparticion, id_vendedor, estado, creado_el, creado_por)
    VALUES (20, 'J', '30704567894', 'TALLERES METALURGICOS NORTE S.A.', 'administracion@metalnorte-ejemplo.com.ar', '0340405200090704567891', 'METALNORTE.EMPRESA', 'RI',
            (SELECT id_tipo_asociado FROM tipos_asociado WHERE codigo = 'AP_ACTIVOS'),
            NULL,
            (SELECT id_vendedor FROM vendedores WHERE codigo = 'ALMIRON'), 'ACTIVO', '2026-11-13 10:30:00', 2);
    INSERT INTO clientes_empresas (id_cliente, razon_social, nombre_fantasia, tipo_societario, actividad, fecha_inicio, facturacion_anual)
    VALUES (20, 'TALLERES METALURGICOS NORTE S.A.', 'METALNORTE', 'SA', 'Metalurgia', '1998-03-12', 520000000);
    INSERT INTO telefonos (id_cliente, tipo, numero, principal) VALUES (20, 'FIJO', '541147540022', 1);
    INSERT INTO domicilios (id_cliente, tipo, calle, numero, localidad, provincia, cp, principal) VALUES (20, 'COMERCIAL', 'Av. Ricardo Balbín', '3870', 'San Martín', 'Buenos Aires', '1650', 1);

    -- 4. Inversores -----------------------------------------------------------
    INSERT INTO inversores (id_inversor, tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, creado_el, creado_por)
    VALUES (1, 'F', '20173456787', 'RAMIREZ HECTOR OSCAR', 'hector.ramirez@correo-ejemplo.com.ar', '5491144556677', 'Av. Rivadavia 5120, CABA', '2850300200070173456782', 'HECTOR.RAMIREZ.INV', 'ACTIVO', '2026-05-12 11:00:00', 1);
    INSERT INTO inversores (id_inversor, tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, creado_el, creado_por)
    VALUES (2, 'F', '27162345678', 'VILLALBA NORMA BEATRIZ', 'norma.villalba@correo-ejemplo.com.ar', '5492214455667', 'Calle 50 845, La Plata', '1910311600070162345673', NULL, 'ACTIVO', '2026-05-08 11:00:00', 1);
    INSERT INTO inversores (id_inversor, tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, creado_el, creado_por)
    VALUES (3, 'J', '30701234568', 'INVERSORA DEL PLATA S.A.', 'tesoreria@inversoradelplata-ejemplo.com.ar', '541143217788', 'Reconquista 640, CABA', '1500322900070701234565', 'DELPLATA.INVERSORA', 'ACTIVO', '2026-06-20 11:00:00', 1);
    INSERT INTO inversores (id_inversor, tipo, cuit, denominacion, email, telefono, domicilio, cbu, alias_cbu, estado, creado_el, creado_por)
    VALUES (4, 'J', '33706543215', 'CASTRO HERMANOS S.R.L.', 'administracion@castrohermanos-ejemplo.com.ar', '542234567788', 'Av. Luro 3450, Mar del Plata', '0340333200070706543217', NULL, 'ACTIVO', '2026-05-25 11:00:00', 1);

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
        (1, 6, 'HAB_ACTIVOS', 200000, 6, 0, 'OBSERVADA', '2026-11-16 10:40:00', '2026-11-18 12:05:00', 1, 'Falta el último recibo de sueldo: que lo suba el vendedor y la reenvíe.'),
        (2, 11, 'AP_ACTIVOS', 500000, 18, 1, 'RECHAZADA', '2026-11-16 15:10:00', '2026-11-17 09:50:00', 1, 'rechazo'),
        (3, 12, 'REGGIO_DC', 300000, 12, 0, 'PENDIENTE', '2026-11-19 11:20:00', NULL, NULL, NULL),
        (4, 10, 'AP_ACTIVOS', 800000, 12, 0, 'APROBADA', '2026-11-19 16:30:00', '2026-11-20 10:15:00', 1, 'Aprobada: al día con el préstamo anterior.');
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
        (116, '2026-11-19', 'SOLEXTRA', 4, 0, 1.0, NULL, NULL, NULL, 0);
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
            VALUES (8, v_imp, v_fecha, v_fecha, '59990001', NULL, v_a, 'CREDITO RECAUDACION — CUIL 20184526377 PEREZ CARLOS ALBERTO', '20184526377', 'ICBC');

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

