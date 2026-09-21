# Política de Respaldo y Recuperación

| | |
|---|---|
| **Identificador** | POL-004 |
| **Versión** | 1.0 |
| **Fecha de emisión** | 21 de septiembre de 2026 |
| **Elaborado por** | Nivel táctico — Gerencia de Operaciones |
| **Aprobado por** | Nivel estratégico — Director de Seguridad de la Información (CISO) |
| **Clasificación** | Uso interno del proyecto |
| **Controles del Anexo A** | A.8.13, A.5.29, A.5.30, A.8.24 |
| **Próxima revisión** | 21 de marzo de 2027 |

---

## 1. Propósito y origen de esta política

La propuesta original del proyecto declaraba, en su tabla de plataforma tecnológica, respaldos
mediante **instantáneas semanales** con un costo de Q37.00 mensuales. El anexo del triángulo de la
ciberresiliencia detectó que esa decisión producía una contradicción verificable dentro del propio
documento.

El razonamiento del anexo era el siguiente. El primer objetivo del modelo de negocio compromete una
disponibilidad del 99.9 %, equivalente a unos 43 minutos de indisponibilidad mensual. Un respaldo
semanal implica un objetivo de punto de recuperación de hasta siete días: ante un cifrado malicioso o
una pérdida de la base de datos, la restauración se mediría en días y con pérdida de **una semana
completa de transacciones comerciales**. Es exactamente lo contrario de lo que el modelo del triángulo
exige cuando pide restaurar operaciones en minutos y no en semanas.

Una plataforma de comercio electrónico que pierde siete días de pedidos no tiene un problema de
respaldo: tiene un problema de negocio que el respaldo debía haber evitado. Los pedidos perdidos no se
reconstruyen, porque el cliente no vuelve a hacerlos.

Esta política sustituye aquel esquema.

## 2. El cambio y su fundamento

| Aspecto | Esquema original | Esquema vigente |
|---|---|---|
| Frecuencia | Instantánea semanal | Volcado diario |
| Cifrado | No declarado | Cifrado antes de salir del servidor |
| Ubicación | Instantánea del proveedor, en la misma cuenta | Copia local más copia en almacenamiento externo de proveedor distinto |
| Esquema | Copia única | 3-2-1 |
| Objetivo de punto de recuperación | Hasta 7 días, implícito | **24 horas, declarado** |
| Objetivo de tiempo de recuperación | No declarado | **4 horas, declarado** |
| Verificación | No contemplada | Prueba mensual de restauración con acta |
| Costo incremental | — | Q20.00 mensuales |

El costo incremental asciende a Q80.00 para los cuatro meses de duración del proyecto, monto que se
absorbe dentro de la reserva de contingencia de Q97.20 ya prevista en el presupuesto original. La
corrección del vértice de respuesta no incrementa, por tanto, el costo total del proyecto, que se
mantiene en Q1,069.20 de desembolso real.

### 2.1 Por qué un respaldo semanal no es un respaldo

Conviene nombrar con precisión el problema, porque la instantánea semanal parecía suficiente y no lo
era por dos razones distintas.

La primera es la ventana de pérdida. Entre dos instantáneas semanales existen siete días de operación
que no están en ninguna parte. El objetivo de punto de recuperación no es el intervalo entre respaldos
por casualidad: es exactamente ese intervalo, porque lo que no se copió se pierde.

La segunda es más grave y afecta a cualquier frecuencia: una instantánea alojada en la misma cuenta
del proveedor, accesible con las mismas credenciales que el servidor, no protege frente a un atacante
que obtuvo esas credenciales. El cifrado malicioso moderno busca y destruye las copias antes de cifrar
los datos, precisamente porque sabe que la copia es la única defensa que importa. Una copia que el
atacante puede borrar con el mismo acceso que usó para entrar no es una copia de seguridad.

## 3. Alcance

### 3.1 Qué se respalda

| Activo | Contenido | Frecuencia | Clasificación |
|---|---|---|---|
| Base de datos MariaDB | Catálogo, pedidos, usuarios, roles, registros de auditoría, eventos y alertas de seguridad | Diaria | Confidencial |
| Archivos subidos por usuarios | Imágenes de producto y contenido cargado | Diaria | Uso interno |
| Configuración del entorno | Archivo de variables de entorno, con sus secretos | Ante cada cambio | **Restringido** |
| Reglas propias del cortafuegos y exclusiones | Configuración de ModSecurity del proyecto | Bajo control de versiones | Uso interno |
| Registros de eventos archivados | Archivo frío conforme a POL-005 | Mensual | Confidencial |
| Código de la aplicación | Repositorio completo | Continuo, por control de versiones | Uso interno |

### 3.2 Qué no se respalda, y por qué

El sistema operativo y los contenedores no se respaldan: se reconstruyen desde los guiones de
aprovisionamiento y endurecimiento, que están bajo control de versiones. Un sistema reconstruido desde
su definición es preferible a uno restaurado desde una imagen, porque la imagen puede contener el
compromiso que motivó la restauración.

La memoria caché y las sesiones activas tampoco se respaldan. Su pérdida obliga a los usuarios a
iniciar sesión de nuevo, que es una molestia aceptable y, tras un incidente, una medida deseable.

## 4. Esquema 3-2-1

El esquema exige tres copias de los datos, en dos tipos de soporte distintos, con una de ellas fuera
del sitio. Su aplicación concreta en el proyecto es la siguiente.

| Copia | Ubicación | Soporte | Propósito |
|---|---|---|---|
| **1 · Producción** | Volumen de datos de MariaDB en la máquina virtual | Disco persistente del proveedor de cómputo | El dato vivo |
| **2 · Local** | Directorio de respaldos en la misma máquina, con retención de 7 días | Disco persistente, ruta distinta | Restauración rápida ante error operativo, sin descarga |
| **3 · Externa** | Almacenamiento de objetos de un proveedor **distinto** al de cómputo, con retención de 30 días | Almacenamiento de objetos remoto | Supervivencia ante compromiso del servidor o pérdida de la cuenta de cómputo |

### 4.1 Requisitos de la copia externa

La copia externa debe cumplir tres condiciones, y las tres responden a la misma amenaza: que quien
comprometa el servidor no pueda destruir la copia.

1. **Proveedor distinto al de cómputo.** Si la copia vive en la misma cuenta que el servidor, un
   compromiso de esa cuenta alcanza ambos.
2. **Credencial de escritura sin permiso de borrado.** La credencial que el servidor emplea para subir
   el respaldo no debe poder eliminar objetos existentes. Un atacante con acceso al servidor obtiene
   esa credencial; con ella podrá subir basura, pero no destruir lo anterior.
3. **Cifrado antes de la transmisión.** El respaldo se cifra en el servidor, de modo que el proveedor
   de almacenamiento custodia un archivo que no puede leer.

## 5. Procedimiento de respaldo

### 5.1 Secuencia diaria

El respaldo se ejecuta de forma automática a las 02:00 (UTC−6), hora de menor actividad, y sigue esta
secuencia:

1. **Volcado consistente de la base de datos.** Se emplea un volcado lógico con transacción única, de
   modo que la copia refleje un estado coherente sin bloquear las tablas durante la operación.
2. **Compresión.**
3. **Cifrado simétrico** con una clave que **no reside en el servidor**. La clave se custodia fuera de
   la máquina y se registra su custodio. Un respaldo cifrado con una clave que el atacante encuentra
   en el mismo servidor es un respaldo en texto claro con pasos adicionales.
4. **Cálculo del resumen SHA-256** del archivo cifrado, que se almacena junto a él.
5. **Copia al directorio local** con nomenclatura `marketgt-AAAAMMDD-HHMM.sql.gz.enc`.
6. **Transmisión al almacenamiento externo.**
7. **Verificación de la transmisión**: se lee el tamaño y el resumen del objeto remoto y se contrasta
   con el local. Un respaldo que no se verificó tras subir es un respaldo que se supone.
8. **Purga de copias vencidas**: locales con más de 7 días, externas con más de 30.
9. **Registro del resultado** en la bitácora de operación, con la hora de inicio, la hora de fin, el
   tamaño y el resumen.

### 5.2 Notificación de fallo

Un respaldo fallido genera una alerta de severidad **alta**. La razón es que el fallo del respaldo no
produce ningún síntoma visible: el sistema sigue funcionando con normalidad y nadie se entera hasta
que hace falta restaurar, que es el peor momento posible para descubrirlo. Tres fallos consecutivos
elevan la alerta a severidad crítica conforme a POL-002.

### 5.3 Los secretos

El archivo de variables de entorno contiene información clasificada como restringida: credenciales de
la base de datos, clave de la aplicación y secretos operativos. Se respalda de forma separada, con su
propio cifrado, y **nunca se incorpora al repositorio**, que contiene únicamente una plantilla con
valores de ejemplo.

La pérdida de la clave de la aplicación tiene una consecuencia que conviene declarar antes de que
ocurra: los datos personales de los clientes —dirección, teléfono y documento de identidad— están
cifrados con ella. Sin esa clave, el respaldo de la base de datos se restaura pero esos campos quedan
ilegibles de forma permanente. La custodia de la clave es, por tanto, parte del respaldo y no un
asunto aparte.

## 6. Objetivos de recuperación

| Objetivo | Valor | Qué significa | De qué depende |
|---|---|---|---|
| **Objetivo de punto de recuperación (RPO)** | **24 horas** | Pérdida máxima tolerable de datos, medida desde el último respaldo verificado | De la frecuencia del respaldo. Con volcado diario, el peor caso es perder las transacciones del día en curso |
| **Objetivo de tiempo de recuperación (RTO)** | **4 horas** | Plazo máximo para restablecer el servicio tras una interrupción mayor | Del tiempo de aprovisionamiento, descarga, descifrado, restauración y verificación |

### 6.1 Desglose del tiempo de recuperación

El objetivo de 4 horas no es una aspiración: es la suma de operaciones medibles. Este desglose es el
que la prueba mensual debe contrastar.

| Etapa | Tiempo estimado |
|---|---|
| Decisión de restaurar y convocatoria del equipo | 30 minutos |
| Aprovisionamiento de la máquina virtual mediante el guion correspondiente | 20 minutos |
| Endurecimiento del sistema operativo mediante el guion correspondiente | 25 minutos |
| Descarga del respaldo externo y su descifrado | 20 minutos |
| Restauración de la base de datos y verificación de integridad | 45 minutos |
| Despliegue de la aplicación y emisión del certificado | 40 minutos |
| Verificación funcional y del cortafuegos | 30 minutos |
| Margen de contingencia | 30 minutos |
| **Total** | **4 horas** |

### 6.2 Coherencia con el compromiso de disponibilidad

Un objetivo de tiempo de recuperación de 4 horas no es compatible con una disponibilidad del 99.9 %
anual si la interrupción mayor llega a ocurrir: una sola interrupción de 4 horas consume el
presupuesto de indisponibilidad de varios meses.

El anexo del proyecto ya señaló esta tensión y propuso dos salidas: acotar el compromiso del 99.9 % a
la resistencia frente a denegación de servicio, que es lo que la arquitectura efectivamente sostiene,
o ajustar la cifra a un valor respaldado por la capacidad de recuperación declarada. Esta política
**adopta la primera**: el compromiso de disponibilidad se refiere a la operación normal y a la
resistencia frente a tráfico hostil, mientras que la interrupción mayor se gobierna por el objetivo de
tiempo de recuperación de 4 horas. Mantener ambas cifras sin relacionarlas habría sido la opción
cómoda y también la insostenible ante una revisión.

Alcanzar un objetivo de recuperación más agresivo exigiría infraestructura redundante —una réplica en
espera con conmutación automática— que el presupuesto vigente no contempla y que, para un entorno de
demostración académica, no se justifica.

## 7. Procedimiento de restauración

### 7.1 Antes de restaurar

Tres verificaciones son obligatorias y su orden no es indiferente:

1. **¿El respaldo es anterior al incidente?** Restaurar desde una copia ya comprometida reinicia el
   incidente y consume el único juego de datos sano. Ante un cifrado malicioso, esta verificación es
   la que decide si la recuperación es posible.
2. **¿El resumen coincide?** Se contrasta el SHA-256 del archivo descargado con el registrado en el
   momento del respaldo. Una discrepancia significa alteración o transmisión corrupta, y en ambos
   casos se pasa al respaldo anterior.
3. **¿El descifrado produce un volcado válido?** Se verifica la estructura del volcado antes de
   aplicarlo sobre una base de datos.

### 7.2 Restauración

La restauración se realiza siempre sobre infraestructura reconstruida desde los guiones de
aprovisionamiento, nunca sobre el sistema comprometido. Una vez restaurados los datos, se verifica:
el recuento de registros de las tablas principales; la integridad referencial entre pedidos y sus
líneas; que la aplicación arranca y autentica; que el cortafuegos inspecciona, comprobado con el guion
de demostración; y que la ingesta de eventos avanza.

Al finalizar se rotan todos los secretos si la restauración obedeció a un compromiso, y se registra el
volumen de datos perdido entre el último respaldo y el momento del incidente, que es la medición real
del objetivo de punto de recuperación.

## 8. Prueba mensual de restauración

El anexo del proyecto señaló que **no se contemplaba la verificación de que los respaldos fueran
restaurables**. Es la brecha más habitual y la más costosa: un respaldo que nunca se probó tiene una
probabilidad apreciable de no servir, y esa probabilidad solo se descubre el día en que hace falta.

### 8.1 Régimen de la prueba

| Aspecto | Definición |
|---|---|
| Frecuencia | Mensual, el primer lunes de cada mes |
| Responsable | Responsable de Plataforma; el Custodio de Evidencia verifica el resultado |
| Entorno | Aislado, sin acceso a la red de producción ni a datos vivos |
| Origen | La copia **externa**, no la local. Probar la copia local no verifica el eslabón que puede fallar |
| Alcance | Restauración completa de la base de datos y verificación funcional de la aplicación |
| Duración objetivo | Dentro de las 4 horas del objetivo de tiempo de recuperación |

### 8.2 Acta de resultado

Cada prueba produce un acta en `evidencias/restauraciones/` con los campos siguientes. El acta es la
evidencia del control A.8.13 ante una auditoría; sin ella, la política es una declaración de
intenciones.

| Campo | Contenido |
|---|---|
| Identificador | `RES-AAAA-MM` |
| Fecha y hora de inicio y de fin | En UTC−6 |
| Respaldo empleado | Nombre del archivo y su resumen SHA-256 |
| Ejecutado por / verificado por | Función, no nombre de persona |
| Tiempo total empleado | Y su comparación contra el objetivo de 4 horas |
| Verificaciones realizadas | Recuento de registros, integridad referencial, arranque, autenticación, cortafuegos e ingesta |
| Resultado | Satisfactorio, satisfactorio con observaciones, o fallido |
| Deficiencias detectadas | Con responsable y fecha de corrección |

Una prueba fallida genera un incidente de severidad alta y obliga a repetir la prueba tras la
corrección. Una prueba no ejecutada en su fecha se registra como no ejecutada; **no se pospone en
silencio**.

### 8.3 Estado al 21 de septiembre de 2026

No se ha ejecutado ninguna prueba de restauración. El guion de respaldo y el de restauración no
constan todavía en el repositorio al cierre de este documento, de modo que el control A.8.13 debe
declararse **definido pero no implementado**, y así figura en la matriz de controles.

Esta política define el procedimiento, sus objetivos y su evidencia; su implementación es requisito de
cierre del proyecto y no puede darse por satisfecha con la existencia de este documento. Declarar el
control implementado sobre la base de haberlo escrito sería precisamente el hallazgo que el anexo del
triángulo reprochaba a la propuesta original.

## 9. Retención de los respaldos

| Copia | Retención | Fundamento |
|---|---|---|
| Local diaria | 7 días | Cubre la detección de un error operativo dentro de la semana |
| Externa diaria | 30 días | Cubre la detección tardía de un compromiso, que rara vez es inmediata |
| Externa mensual consolidada | 12 meses | Coherente con la retención en frío de los registros conforme a POL-005 |

Los respaldos vencidos se eliminan de forma definitiva. Conservar más allá del plazo declarado no
aumenta la seguridad: aumenta la superficie de exposición de datos personales que ya no deberían
existir.

## 10. Controles del Anexo A que esta política desarrolla

| Control | Nombre | Cómo lo desarrolla |
|---|---|---|
| A.8.13 | Respaldo de la información | Secciones 4 a 9 en su totalidad |
| A.5.29 | Seguridad de la información durante la disrupción | Procedimiento de restauración y conmutación al servicio de respaldo estático |
| A.5.30 | Preparación de las TIC para la continuidad del negocio | Objetivos de recuperación declarados y verificados mediante la prueba mensual |
| A.8.24 | Uso de criptografía | Cifrado del respaldo antes de la transmisión y custodia de la clave fuera del servidor |

## 11. Control de versiones

| Versión | Fecha | Autor | Cambio |
|---|---|---|---|
| 1.0 | 2026-09-21 | Nivel táctico — Gerencia de Operaciones | Emisión inicial. Sustituye el esquema de instantáneas semanales por volcado diario cifrado bajo esquema 3-2-1, conforme al anexo del triángulo de la ciberresiliencia |
</content>
