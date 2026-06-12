# Casa Monarca SDK

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![Python Version](https://img.shields.io/badge/python-3.8%20%7C%203.9%20%7C%203.10%20%7C%203.11%20%7C%203.12-blue)](https://www.python.org/)
[![Version](https://img.shields.io/badge/sdk-v1.0.0-green.svg)]()
[![Package Manager](https://img.shields.io/badge/package--manager-uv-purple.svg)](https://github.com/astral-sh/uv)

SDK modular e hiper-completo para la plataforma de firmas digitales y control de acceso seguro Casa Monarca v2.

## Descripción General

**Casa Monarca v2** es una plataforma institucional diseñada para la gestión y firma segura de documentos digitales mediante criptografía de curva elíptica (ECDSA P-256) y RSA-3072, con un enfoque estricto en el control de acceso basado en roles (RBAC) y auditoría completa (Bitácora).

Este SDK en Python proporciona una API orientada a objetos sumamente limpia y profesional que envuelve la funcionalidad de los controladores PHP del servidor. El SDK no solo abstrae las peticiones REST HTTP, sino que además ejecuta **criptografía local del lado del cliente** (Client-Side Cryptography) para mantener segura la clave privada del usuario, la cual se deriva determinísticamente de una frase mnemónica BIP39 de 12 palabras en español. De este modo, la clave privada nunca viaja por la red ni se almacena en el servidor, garantizando el principio de conocimiento cero (Zero-Knowledge).

## Diagramas de Arquitectura

### 1. Diagrama de Flujo (Flowchart)
Muestra cómo el código del usuario y la CLI interactúan con el SDK y cómo este se comunica con el servidor central PHP y las bases de datos.

```mermaid
graph TD
    subgraph Client [SDK - Python Client (Local)]
        A[App del Desarrollador / CLI] -->|1. Invoca Métodos| B[CasaMonarcaClient]
        B -->|2. Derivación y Firmas Locales| C[crypto.py Cryptographic Engine]
        C -->|Par de Llaves y Firma Compacta| B
    end

    subgraph API [REST API Backend (Server)]
        B -->|3. Peticiones HTTP REST| D[Servidor Apache / PHP Router]
        D -->|Rutea Endpoints| E[src/api/*.php Controllers]
        E -->|Carga Lógica de Negocio| F[src/modules/*.php Core Modules]
    end

    subgraph Storage [Infraestructura / Persistencia]
        F -->|CRUD Metadatos y Llaves Públicas| G[(MySQL Database)]
        F -->|Almacenamiento PDF| H[File System /var/uploads/]
    end
```

### 2. Diagrama de Secuencia de la Funcionalidad Principal
Describe el flujo completo de autenticación, creación de borrador y proceso de firma en dos pasos (solicitud del hash, firma criptográfica local con BIP39/ECDSA, y confirmación final).

```mermaid
sequenceDiagram
    autonumber
    actor Dev as Developer Code / CLI
    participant SDK as Python SDK (CasaMonarcaClient)
    participant API as PHP REST API (src/api/)
    participant Crypt as Local Crypto Engine (crypto.py)
    database DB as MySQL Database
    participant FS as File System (/var/uploads/)

    Note over Dev, Crypt: 1. Flujo de Autenticación
    Dev->>SDK: login(email, password)
    SDK->>API: POST /auth/login.php
    API->>DB: Verificar credenciales (bcrypt)
    DB-->>API: Datos del usuario
    API-->>SDK: Set-Cookie (PHPSESSID)
    SDK-->>Dev: User Profile Model

    Note over Dev, FS: 2. Flujo de Creación de Documento (Borrador)
    Dev->>SDK: create_document(titulo, archivo_path)
    SDK->>API: POST /api/documentos-create.php (Multipart PDF)
    API->>FS: Guardar archivo PDF en /var/uploads/{folio}.pdf
    API->>API: Calcular hash SHA-256 del archivo
    API->>DB: INSERT INTO documentos (estado = 'borrador')
    DB-->>API: Last Insert ID (doc_id)
    API-->>SDK: JSON (doc_id, folio, hash_sha256)
    SDK-->>Dev: Document Model

    Note over Dev, Crypt: 3. Proceso de Firma Local y Confirmación
    Dev->>SDK: sign_document(doc_id, mnemonic)
    SDK->>API: POST /api/documentos-solicitar-firma.php
    API->>DB: Verificar turno y clave pública del firmante
    API->>API: Construir contenido: folio | base_hash | orden | firma_prev
    API->>API: Calcular SHA-256 del contenido
    API->>DB: INSERT INTO firma_sessions (expires_at = NOW + 10m)
    API-->>SDK: JSON (hash, session_id, orden)
    
    SDK->>Crypt: derivar_llaves(mnemonic)
    Crypt->>Crypt: Normalizar palabras y PBKDF2 a Seed 64 bytes
    Crypt->>Crypt: Derivar par de claves ECDSA P-256 (comprimida)
    Crypt-->>SDK: private_key_bytes, public_key_hex
    
    SDK->>Crypt: firmar_hash(hash, private_key_bytes)
    Crypt->>Crypt: Firma ECDSA P-256 del hash -> Compact raw (r + s, 64 bytes)
    Crypt-->>SDK: compact_signature_hex (128 caracteres)

    SDK->>API: POST /api/documentos-completar-firma.php (firma_hex, session_id)
    API->>DB: Obtener sesión de firma activa y llave pública
    API->>API: Verificar firma ECDSA contra llave pública
    alt Firma Válida y Turno Final
        API->>DB: UPDATE documentos SET estado = 'emitido'
        API->>DB: INSERT INTO bitacora (accion = 'emitted')
        API-->>SDK: JSON (folio, completado = true)
    else Firma Válida y Turno Parcial
        API->>DB: UPDATE documento_firmantes SET estado = 'firmado'
        API->>DB: INSERT INTO bitacora (accion = 'firma_parcial')
        API-->>SDK: JSON (folio, completado = false, siguiente_turno)
    end
    SDK-->>Dev: Response Dictionary / Success Model
```

## Instalación

Se recomienda utilizar `uv` para una gestión rápida y moderna de dependencias. Puedes instalar el SDK localmente en tu proyecto o entorno virtual:

```bash
# Instalar en el entorno virtual actual usando uv
uv pip install ./sdk

# O instalar de forma editable para desarrollo activo
uv pip install -e ./sdk
```

Si prefieres usar `pip` estándar:

```bash
pip install ./sdk
```

### Requisitos del Sistema
- Python `>= 3.8`
- `requests>=2.28.0`
- `cryptography>=38.0.0`

## Quickstart

A continuación se muestra el caso de uso más común para autenticarse, listar documentos y verificar una firma pública.

```python
from casa_monarca_sdk import CasaMonarcaClient

# 1. Inicializar el cliente con la URL del servidor
client = CasaMonarcaClient("http://localhost:8081")

# 2. Iniciar sesión (guarda la sesión HTTP por cookies automáticamente)
user = client.login(email="admin@empresa.local", password="admin123")
print(f"Sesión iniciada: {user.nombre} ({user.rol})")

# 3. Listar borradores y documentos visibles
documentos = client.list_documents()
for doc in documentos:
    print(f"Documento: {doc.titulo} | Folio: {doc.folio} | Estado: {doc.estado}")
```

## Guía de Uso Avanzado

### 1. Generación de Frase Mnemónica y Registro de Llave Pública
El SDK genera una frase mnemónica de 12 palabras en español de forma segura y uniforme. A partir de ella se derivan las claves para registrarlas en el servidor.

```python
from casa_monarca_sdk import generar_mnemonic, derivar_llaves, CasaMonarcaClient

client = CasaMonarcaClient("http://localhost:8081")
client.login("emisor@empresa.local", "emisor123")

# Generar un nuevo mnemonic (12 palabras)
mnemonic = generar_mnemonic()
print(f"Mnemonic generado: {mnemonic}")

# Derivar par de claves localmente
keys = derivar_llaves(mnemonic)
pub_key_hex = keys["public_key_hex"]
print(f"Clave Pública derivada (Hex): {pub_key_hex}")

# Registrar la llave pública en el servidor (la clave privada nunca sale de aquí)
fingerprint = client.register_public_key(pub_key_hex)
print(f"Clave pública registrada con fingerprint: {fingerprint}")
```

### 2. Creación, Asignación de Firmantes Múltiples y Firma de Documento
Flujo completo que crea un borrador, asigna firmantes en orden estricto, y firma el documento usando la llave privada local:

```python
from casa_monarca_sdk import CasaMonarcaClient
from casa_monarca_sdk.exceptions import CasaMonarcaError

client = CasaMonarcaClient("http://localhost:8081")

try:
    # 1. Login
    client.login("emisor@empresa.local", "emisor123")

    # 2. Crear borrador subiendo un archivo PDF
    doc = client.create_document(
        titulo="Contrato de Prestación de Servicios",
        archivo_path="C:/documentos/contrato.pdf",
        descripcion="Contrato institucional con el proveedor de TI"
    )
    print(f"Borrador creado. Folio: {doc.folio} | ID: {doc.id}")

    # 3. Asignar cadena de firmantes en orden estricto (IDs de usuarios)
    # Por ejemplo: el usuario 2 firma primero, y el usuario 3 después.
    firmantes = client.assign_signers(doc_id=doc.id, firmantes_ids=[2, 3])
    print(f"Firmantes asignados. Total: {len(firmantes)}")

    # 4. El primer firmante (ej. id 2) inicia sesión y firma con su mnemonic
    client_firmante = CasaMonarcaClient("http://localhost:8081")
    client_firmante.login("firmante1@empresa.local", "firmante123")
    
    mnemonic_firmante1 = "ábaco abdomen abeja abierto abogado abono aborto abrazo abrir abuelo abuso acabar"
    
    # El método sign_document realiza la solicitud de hash, firma local e inserción del lado del servidor
    res_firma = client_firmante.sign_document(doc_id=doc.id, mnemonic=mnemonic_firmante1)
    print(f"Firma parcial registrada: {res_firma}")

except CasaMonarcaError as e:
    print(f"Ocurrió un error en el flujo: {e}")
```

### 3. Manejo de Errores Exhaustivo
El SDK expone excepciones estructuradas para capturar errores de red, fallos de permisos, errores criptográficos locales o de validación de datos:

```python
from casa_monarca_sdk import CasaMonarcaClient
from casa_monarca_sdk.exceptions import AuthError, APIError, ValidationError, CryptographyError

client = CasaMonarcaClient("http://localhost:8081")

try:
    client.login("admin@empresa.local", "password_incorrecto")
except AuthError as e:
    print(f"Fallo de Autenticación: {e.message} (Código HTTP: {e.code})")
except ValidationError as e:
    print(f"Fallo de Validación: {e.message}")
except APIError as e:
    print(f"Fallo general del Servidor/API: {e.message}")
except CryptographyError as e:
    print(f"Error Criptográfico Local: {e.message}")
```

## 📚 Documentación Extendida y Referencias

* [Manual de Usuario - Equipo 3](./Documentos/Manual_Usuario_Equipo_3.pdf): Manual detallado que describe las funcionalidades, interfaz y flujos de usuario de la plataforma Casa Monarca v2.
* [Reporte Ejecutivo - Equipo 3](./Documentos/Reporte_Ejecutivo_Equipo_3.pdf): Resumen de alto nivel del proyecto que resume el alcance, objetivos logrados y valor institucional del sistema.
* [Reporte Técnico - Equipo 3](./Documentos/Reporte_Tecnico_Equipo_3.pdf): Documentación técnica profunda que detalla la arquitectura, diagramas de base de datos, protocolos de criptografía y decisiones de diseño.
* [Prueba](./Documentos/prueba): Archivo genérico utilizado para pruebas de integración y validación de carga de documentos.

## 📊 Hallazgos y Cobertura de Pruebas

Para garantizar la estabilidad y confiabilidad de Casa Monarca v2 SDK, se diseñó e implementó una infraestructura de pruebas automatizadas guiada por rigurosos estándares de ingeniería de calidad.

### Estrategia de Ingeniería de Calidad

Nuestra arquitectura de pruebas se fundamenta en la **separación de responsabilidades** (Separation of Concerns), dividiendo el análisis en dos frentes complementarios:
1. **Lógica Matemática y Criptografía Pura ([crypto.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/sdk/src/casa_monarca_sdk/crypto.py))**: Se prueba localmente y sin efectos secundarios. Validamos que la generación de mnemónicos BIP39 en español sea determinista y uniforme, que la derivación de claves ECDSA P-256 (tanto privadas de 32 bytes como públicas comprimidas de 33 bytes) sea correcta, y que el ciclo completo de firma (compacta de 64 bytes) y verificación local sea matemáticamente infalible.
2. **Lógica de Comunicación y Cliente REST ([client.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/sdk/src/casa_monarca_sdk/client.py))**: Para aislar completamente las pruebas de la disponibilidad del servidor de Casa Monarca v2, se implementó un sistema de simulación e interceptación HTTP local a través de la librería `responses`. Esto nos permite:
   - Simular de manera exacta todos los endpoints del backend en escenarios exitosos y fallidos (errores HTTP 400, 401, 403, 404, 500, etc.).
   - Validar que los encabezados, parámetros de consulta y cargas de datos estructurados (incluyendo transmisiones binarias multipart `application/pdf`) se construyan y transmitan correctamente.
   - Confirmar de forma offline que el cliente intercepta cookies (`Set-Cookie`) y gestiona sesiones con estado de manera segura.

Esta estrategia de mocks evita peticiones de red reales que introduzcan latencia o inestabilidad en los pipelines de integración continua (CI/CD), permitiendo que la suite completa de pruebas corra en milisegundos de manera determinista y totalmente reproducible offline.

### Cobertura de Código (Test Coverage)

Tras la implementación de la suite de pruebas unitarias en [test_crypto.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/tests/test_crypto.py) y [test_client.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/tests/test_client.py) con `pytest` y `pytest-cov`, se obtuvieron las siguientes métricas de cobertura para el núcleo del SDK:

| Módulo | Sentencias (Stmts) | Líneas No Cubiertas (Miss) | Cobertura (Cover) |
| :--- | :---: | :---: | :---: |
| [__init__.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/sdk/src/casa_monarca_sdk/__init__.py) | 6 | 0 | **100%** |
| [client.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/sdk/src/casa_monarca_sdk/client.py) | 198 | 10 | **95%** |
| [crypto.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/sdk/src/casa_monarca_sdk/crypto.py) | 79 | 5 | **94%** |
| [models.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/sdk/src/casa_monarca_sdk/models.py) | 144 | 5 | **97%** |
| [exceptions.py](file:///C:/Users/jeggs/OneDrive/Escritorio/milio/casa-monarca-v2-1/sdk/src/casa_monarca_sdk/exceptions.py) | 17 | 2 | **88%** |
| **Total (Módulos Core del SDK)** | **444** | **22** | **95%** |

*Nota: La interfaz de línea de comandos (`cli.py`) no forma parte del núcleo programático del SDK y es excluida del cómputo de cobertura del núcleo de la API.*

## Referencia de la API (Resumen)

| Clase / Función | Tipo | Descripción | Parámetros Principales |
|---|---|---|---|
| `CasaMonarcaClient` | Clase | Cliente API principal para gestionar sesiones y recursos. | `base_url`, `timeout` |
| `generar_mnemonic` | Función | Genera una mnemónica aleatoria uniforme en español de 12 palabras. | Ninguno |
| `validar_mnemonic` | Función | Comprueba si un mnemonic tiene 12 palabras y son válidas. | `mnemonic: str` |
| `derivar_llaves` | Función | Deriva la clave privada y pública comprimida P-256 a partir de una mnemónica. | `mnemonic: str` |
| `firmar_hash` | Función | Firma localmente un hash hex con una clave privada. Retorna firma compacta hex. | `hash_hex: str`, `private_key_bytes: bytes` |
| `verificar_firma_local` | Función | Valida localmente si una firma compacta corresponde a un hash y llave pública. | `hash_hex`, `signature_hex`, `public_key_hex` |
| `User` | Dataclass | Modelo de datos de un usuario del sistema. | `id`, `nombre`, `email`, `rol`, `activo` |
| `Document` | Dataclass | Modelo de datos de un documento digital. | `id`, `folio`, `titulo`, `estado`, etc. |
| `DocumentSigner` | Dataclass | Representa un firmante asignado y su turno de firma. | `usuario_id`, `orden`, `estado`, `firma` |
| `AuditLogEntry` | Dataclass | Registro de auditoría (Bitácora). | `usuario_id`, `accion`, `modulo`, `fecha` |
| `DocumentVerificationResult` | Dataclass | Resultado detallado de validación pública de un folio. | `folio`, `firma_valida`, `mensaje`, `firmas` |

## Herramienta de Línea de Comandos (CLI)

El SDK expone el comando ejecutable `casa-monarca` tras su instalación.

```bash
# Iniciar sesión y guardar sesión localmente
casa-monarca login http://localhost:8081 admin@empresa.local admin123

# Generar llaves y frase mnemónica
casa-monarca keygen

# Listar documentos visibles
casa-monarca doc-list

# Crear un documento subiendo un archivo PDF
casa-monarca doc-create "Minuta de Reunión" "C:/minuta.pdf" --descripcion "Reunión de Consejo de Administración"

# Firmar un documento con tu mnemónica local
casa-monarca doc-sign 42 "palabra1 palabra2 ... palabra12"

# Verificar públicamente la integridad de un documento
casa-monarca doc-verify DOC-20260612-F5A3E9
```

## Contribución

1. Haz un Fork del repositorio.
2. Crea una rama para tu feature: `git checkout -b feature/nueva-funcionalidad`.
3. Confirma tus cambios: `git commit -m "feat: agrega nueva funcionalidad"`.
4. Sube la rama: `git push origin feature/nueva-funcionalidad`.
5. Abre un Pull Request describiendo tus cambios y asegurándote de incluir pruebas unitarias aplicables.
