# Despliegue de la Plataforma Casa Monarca v2

Este documento contiene las instrucciones tecnicas y procedimientos para desplegar la plataforma del servidor backend de Casa Monarca v2. Esta guia se enfoca exclusivamente en la infraestructura del servidor (servicios PHP/Apache y base de datos MySQL) y es completamente independiente de la instalacion o uso del SDK de Python.

## Prerrequisitos

Para realizar el despliegue del backend de la plataforma, el entorno del servidor debe contar con las siguientes herramientas instaladas y configuradas:

1. Git (para la clonacion y control de versiones del repositorio).
2. Docker Engine (version 20.10.0 o superior).
3. Docker Compose (version 1.29.0 o superior, compatible con la especificacion v3.8).

## Variables de Entorno

La plataforma utiliza variables de entorno para parametrizar la conexion con la base de datos, el ciclo de vida de las sesiones y la ruta de almacenamiento de claves criptograficas. Estas variables se definen en el archivo `.env` ubicado en la raiz del repositorio.

El repositorio incluye un archivo de plantilla denominado `.env.example`. Para configurar su entorno local:

1. Copie el archivo de plantilla con el nombre de destino correspondiente:
   ```bash
   cp .env.example .env
   ```

2. Ajuste las siguientes variables en el archivo `.env` conforme a sus requisitos de seguridad:
   * `APP_ENV`: Define el entorno de ejecucion (ej. `development`, `production`).
   * `DB_HOST`: Nombre de host de la base de datos (por defecto `db` dentro del entorno de red virtualizado).
   * `DB_PORT`: Puerto TCP de conexion de base de datos (por defecto `3306`).
   * `DB_NAME`: Nombre de la base de datos relacional (por defecto `casa_monarca`).
   * `DB_USER`: Nombre de usuario con privilegios de escritura/lectura en la base de datos (por defecto `app_user`).
   * `DB_PASS`: Contrasena asociada al usuario de la base de datos.
   * `DB_ROOT_PASS`: Contrasena administrativa para el rol root de MySQL.
   * `SESSION_LIFETIME`: Tiempo limite de inactividad de las sesiones en segundos (por defecto `3600`).
   * `CM_KEYS_DIR`: Directorio absoluto del contenedor destinado al almacenamiento persistente de claves publicas.

## Ejecucion con Docker

La infraestructura completa se encuentra orquestada a traves de Docker Compose. Para compilar las imagenes personalizadas y levantar los servicios en segundo plano:

1. Compile la imagen del servidor web (que instala las extensiones de PHP pdo y pdo_mysql, configura Apache con soporte de reescritura de URLs y copia el codigo fuente del backend) e inicie los contenedores ejecutando:
   ```bash
   docker-compose up --build -d
   ```

2. Verifique que los servicios esten activos mediante el listado de contenedores:
   ```bash
   docker-compose ps
   ```

El servicio `web` estara expuesto localmente en el puerto `8081` de la maquina anfitriona y enlazado al puerto `80` del contenedor Apache.

## Migraciones y Semilla de Datos

El contenedor de base de datos (`db`) esta configurado para inicializarse automaticamente durante el primer arranque. El proceso se realiza a traves de la importacion ordenada de scripts ubicados en el directorio `/database` del proyecto, los cuales se mapean en la ruta de inicio de MySQL `/docker-entrypoint-initdb.d/`:

1. `01-schema.sql`: Creacion de las tablas del sistema (usuarios, documentos, firmas, bitacora de auditoria).
2. `02-migration.sql` a `06-migration.sql`: Migraciones sucesivas que adaptan el esquema a la version v2 de la plataforma (incluyendo el soporte de multifirma secuencial).

Si la base de datos ya ha sido inicializada y desea ejecutar o restaurar los esquemas manualmente, puede hacerlo accediendo al contenedor de MySQL:

```bash
docker-compose exec -T db mysql -u root -p"su_contrasena_root" casa_monarca < database/schema.sql
```

## Verificacion de Despliegue

Para confirmar que el backend se encuentra operando correctamente y respondiendo peticiones HTTP, puede ejecutar las siguientes pruebas:

1. Validar la respuesta del servidor web accediendo a la raiz a traves de curl o un navegador:
   ```bash
   curl -I http://localhost:8081/
   ```
   Debe retornar un codigo HTTP de estado 200.

2. Consultar el estado de la sesion del servidor llamando al endpoint especifico de la API:
   ```bash
   curl http://localhost:8081/auth/session.php
   ```
   El servidor debe responder con un objeto formateado en JSON con la estructura del estado de sesion (ej. `{"status": "error", "message": "No autenticado"}` si no se ha iniciado sesion activa).

## Arquitectura del Pipeline de Autenticación

La plataforma Casa Monarca v2 delega la gestion de seguridad, identidad y validez de los documentos a un pipeline de autenticacion robusto implementado en el backend. Este pipeline implementa mecanismos de control de sesion con estado, derivacion de claves locales para conocimiento cero (Zero-Knowledge) y firmas criptograficas asimetricas.

### Ciclo de Vida de la Peticion y Validacion de Middleware

El flujo de ejecucion de las peticiones HTTP destinadas a recursos protegidos en el backend sigue una arquitectura de interceptacion secuencial:

```mermaid
graph TD
    Client["Cliente (SDK / CLI / Navegador)"] -->|1. Envia credenciales| LoginEndpoint["POST /auth/login.php"]
    LoginEndpoint -->|2. Valida contra DB (Bcrypt cost 10)| VerifyCreds{"¿Credenciales validas?"}
    VerifyCreds -->|No| AuthErr["Retorna 401 Unauthorized"]
    VerifyCreds -->|Si| CreateSession["Inicia sesion (PHP Session Manager)"]
    CreateSession -->|3. Responde Cookie PHPSESSID (HttpOnly, Secure)| Client
    
    Client -->|4. Envia peticion con Cookie PHPSESSID| APIEndpoint["GET /api/documentos-list.php"]
    APIEndpoint -->|5. Intercepta Middleware de Autenticacion| CheckSession{"¿Existe PHPSESSID activa?"}
    CheckSession -->|No| SessionErr["Retorna 401 Unauthorized"]
    CheckSession -->|Si| CheckRBAC{"¿Rol tiene permisos para el modulo?"}
    
    CheckRBAC -->|No| RBACErr["Retorna 403 Forbidden"]
    CheckRBAC -->|Si| ExecuteAction["Ejecuta logica del controlador y consulta BD"]
    ExecuteAction -->|6. Retorna respuesta estructurada JSON| Client
```

### Fases Operativas del Pipeline Criptografico y de Identidad

El ciclo de seguridad de identidades, firmas y persistencia de documentos se estructura en las siguientes fases tecnicas:

1. Fase de Registro y Setup Inicial de Claves:
   Cuando un usuario es registrado en el sistema, se ejecuta el aprovisionamiento de su par de claves asimetricas RSA de 3072 bits mediante la extension OpenSSL de PHP (openssl_pkey_new). Para garantizar el almacenamiento seguro y el principio de conocimiento cero, el servidor genera un salt criptografico de 16 bytes y un vector de inicializacion (IV) de 16 bytes de manera aleatoria. La clave privada en formato PEM se encripta de forma simetrica usando AES-256-CBC. La clave de cifrado simetrico se deriva utilizando la funcion KDF (Key Derivation Function) PBKDF2 basada en el identificador unico del usuario y el salt generado, aplicando 100,000 iteraciones con hash SHA-256. El certificado publico (clave publica PEM), la clave privada cifrada, el salt y el IV se almacenan en la tabla de base de datos asociada, indexandose por la huella digital (fingerprint) generada con SHA-256 sobre la clave publica.

2. Fase de Carga y Creacion de Borradores:
   El ciclo de vida de un documento inicia con la recepcion del archivo binario PDF a traves del endpoint multipart/form-data. El servidor valida la integridad del archivo, calcula su hash SHA-256 del contenido crudo y le asigna un folio unico determinista (ejemplo: DOC-AAAAMMDD-HEX6). Los metadatos iniciales del documento se registran en la tabla correspondiente con el estado inicial de borrador (borrador). Esta accion genera un registro de auditoria inmutable en la bitacora historica del sistema que identifica al usuario creador y la IP de procedencia. Mientras permanezca en estado de borrador, el contenido puede ser actualizado o el documento eliminado exclusivamente por su creador.

3. Fase de Asignacion de Cadena de Firmantes (RBAC y Multifirma):
   El emisor o creador asocia al documento una coleccion ordenada de identificadores de usuario que deben visar el documento de forma secuencial. La API valida que cada uno de los firmantes este activo en el sistema, posea llaves criptograficas validas y pertenezca a los roles autorizados (control de acceso basado en roles - RBAC). Al confirmarse la asignacion, el documento cambia de estado a firma activa (en_firma), bloqueando cualquier modificacion posterior sobre el titulo, descripcion o contenido del archivo PDF.

4. Fase de Desencriptacion y Firma Criptografica:
   La firma de un documento se realiza de forma secuencial segun el turno asignado. El SDK o cliente solicita al servidor el hash del borrador (solicitar-firma.php). El servidor valida la autenticidad de la sesion (PHPSESSID), comprueba que sea el turno exacto del usuario autenticado segun el RBAC y genera un hash de sesion temporal. Localmente, utilizando la frase mnemonic BIP39 de 12 palabras en español provista por el firmante, el SDK deriva la semilla de clave y la clave privada ECDSA P-256 del usuario (la cual nunca sale del entorno del cliente). El SDK firma localmente el hash SHA-256 enviado por el servidor, produciendo una firma compacta de 64 bytes (formato r + s en hexadecimal). Esta firma hexadecimal es transmitida al servidor (completar-firma.php), que valida la firma contra la clave publica del usuario firmante registrada en la base de datos. Si la firma es valida y corresponde al firmante de turno, el servidor registra la firma, actualiza la bitacora y avanza el turno al siguiente firmante. Cuando el ultimo firmante en la cadena autoriza la peticion, el estado del documento cambia de manera definitiva a emitido (emitido).

5. Fase de Verificacion Publica y Auditoria de Integridad:
   El estado emitido de un documento habilita su consulta publica y validacion criptografica sin requerir autenticacion en la API. A traves del endpoint consulta_qr.php con el token o folio unico del documento, cualquier tercero puede recuperar la metadata basica, las firmas registradas en la base de datos y las claves publicas asociadas a los firmantes. El sistema reconstruye el mensaje firmado (folio | contenido) y ejecuta la validacion de la firma asimetrica contra la clave publica almacenada. Si alguna de las firmas ha sido alterada o no corresponde con el contenido actual del documento, la validacion falla. En caso de requerirse la anulacion de la validez legal de un documento emitido, un administrador o supervisor con los permisos adecuados en el RBAC puede ejecutar el endpoint de revocacion, guardando el motivo de la anulacion en base de datos. La firma y el registro de auditoria original permanecen intactos en la base de datos para fines de peritaje y cumplimiento normativo.
