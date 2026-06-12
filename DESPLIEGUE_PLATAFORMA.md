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
