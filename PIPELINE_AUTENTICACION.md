# Casa Monarca v2 - Pipeline de Autenticación de Documentos

## 🔐 Arquitectura General

```
┌─────────────────────────────────────────────────────────────────────┐
│                    CASA MONARCA v2                                  │
│            Plataforma de Gestión Segura de Documentos               │
└─────────────────────────────────────────────────────────────────────┘

┌──────────────────────────────────────────────────────────────────────┐
│  FASE 1: SETUP INICIAL (Por usuario)                                 │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  Usuario se registra → Sistema genera par RSA-3072                   │
│                                                                       │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ 1. Generación de llaves (src/modules/claves.php)              │  │
│  │    ├─ openssl_pkey_new(3072 bits)                             │  │
│  │    ├─ Extrae clave privada PEM                                │  │
│  │    └─ Extrae clave pública PEM                                │  │
│  │                                                                 │  │
│  │ 2. Encriptación de clave privada                              │  │
│  │    ├─ Salt aleatorio (16 bytes random)                        │  │
│  │    ├─ IV aleatorio (16 bytes random)                          │  │
│  │    ├─ PBKDF2(usuarioId + salt, 100k iteraciones) → key       │  │
│  │    └─ AES-256-CBC(clave privada PEM, key, IV)                │  │
│  │                                                                 │  │
│  │ 3. Almacenamiento en BD                                        │  │
│  │    ├─ usuario_claves.clave_privada_encriptada = encrypted::salt │  │
│  │    ├─ usuario_claves.iv_encriptacion = IV                    │  │
│  │    ├─ usuario_claves.certificado_publico = clave pública PEM │  │
│  │    └─ usuario_claves.fingerprint = SHA256(clave_publica)     │  │
│  │                                                                 │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 📄 Flujo de Creación y Emisión de Documentos

```
┌──────────────────────────────────────────────────────────────────────┐
│  FASE 2A: CREAR DOCUMENTO (Estado: BORRADOR)                         │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  POST /api/documentos-create.php                                     │
│  ├─ titulo: "Certificado"                                            │
│  ├─ descripcion: "..."                                               │
│  └─ contenido: "Texto del documento"                                 │
│                                                                       │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ Función: crearDocumento()                                      │  │
│  │                                                                 │  │
│  │ 1. Generar folio único                                         │  │
│  │    folio = "DOC-" + fecha(Ymd) + "-" + random_hex(6)           │  │
│  │    Ejemplo: "DOC-20260511-ABC123"                              │  │
│  │                                                                 │  │
│  │ 2. Calcular hash del contenido                                 │  │
│  │    hash_sha256 = SHA256(contenido)                             │  │
│  │                                                                 │  │
│  │ 3. Insertar en BD (tabla documentos)                           │  │
│  │    documentos = {                                              │  │
│  │        folio: "DOC-20260511-ABC123",                           │  │
│  │        titulo: "Certificado",                                  │  │
│  │        contenido: "Texto del documento",                       │  │
│  │        hash_sha256: "a1b2c3...",                               │  │
│  │        estado: "borrador",                                     │  │
│  │        creado_por: usuario_id,                                 │  │
│  │        fecha_creacion: NOW()                                   │  │
│  │    }                                                            │  │
│  │                                                                 │  │
│  │ 4. Registrar en bitácora (auditoría)                           │  │
│  │    bitacora = {                                                │  │
│  │        usuario_id: usuario_id,                                 │  │
│  │        accion: "created",                                      │  │
│  │        modulo: "documentos",                                   │  │
│  │        documento_folio: "DOC-20260511-ABC123",                 │  │
│  │        fecha: NOW()                                            │  │
│  │    }                                                            │  │
│  │                                                                 │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                       │
│  ESTADO EN BD: documento.estado = "borrador"                         │
│                documento.firma = NULL                                │
│                documento.firmado_por_usuario_id = NULL              │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

```
┌──────────────────────────────────────────────────────────────────────┐
│  FASE 2B: EDITAR DOCUMENTO (Mientras está en BORRADOR)               │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  POST /api/documentos-update.php/{docId}                             │
│  ├─ titulo: "Nuevo título"                                           │
│  ├─ contenido: "Nuevo contenido"                                     │
│  └─ (Solo si está en estado "borrador")                             │
│                                                                       │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ Función: actualizarDocumento()                                 │  │
│  │                                                                 │  │
│  │ 1. Validar                                                      │  │
│  │    - Documento existe ✓                                        │  │
│  │    - Usuario es el creador ✓                                   │  │
│  │    - Estado = "borrador" ✓                                     │  │
│  │                                                                 │  │
│  │ 2. Recalcular hash SHA-256                                     │  │
│  │    hash_sha256 = SHA256(nuevo_contenido)                       │  │
│  │                                                                 │  │
│  │ 3. Actualizar registro                                         │  │
│  │    UPDATE documentos SET                                       │  │
│  │        titulo = ?,                                             │  │
│  │        contenido = ?,                                          │  │
│  │        hash_sha256 = ?,                                        │  │
│  │        fecha_actualizacion = NOW()                             │  │
│  │    WHERE id = ?                                                │  │
│  │                                                                 │  │
│  │ 4. Registrar en bitácora                                       │  │
│  │                                                                 │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                       │
│  ESTADO EN BD: documento.estado = "borrador" (sin cambios)           │
│                documento.firma = NULL                                │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

```
┌──────────────────────────────────────────────────────────────────────┐
│  FASE 3: EMITIR DOCUMENTO (Estado: BORRADOR → EMITIDO)               │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  POST /api/documentos-emitir.php/{docId}                             │
│  └─ Sin payload adicional (la firma es automática)                   │
│                                                                       │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ Función: emitirDocumento()                                     │  │
│  │                                                                 │  │
│  │ PASO 1: Obtener documento de BD                                │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ SELECT folio, contenido, estado FROM documentos WHERE id │  │  │
│  │  │                                                          │  │  │
│  │  │ Resultado:                                               │  │  │
│  │  │ {                                                        │  │  │
│  │  │   folio: "DOC-20260511-ABC123",                         │  │  │
│  │  │   contenido: "Texto del documento",                     │  │  │
│  │  │   estado: "borrador"                                    │  │  │
│  │  │ }                                                        │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 2: Reconstituir mensaje a firmar                           │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ contenidoFirmar = folio + "|" + contenido              │  │  │
│  │  │                                                          │  │  │
│  │  │ Ejemplo:                                                 │  │  │
│  │  │ "DOC-20260511-ABC123|Texto del documento"              │  │  │
│  │  │                                                          │  │  │
│  │  │ ⚠️ IMPORTANTE: Este formato es crítico para la          │  │  │
│  │  │    verificación posterior                               │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 3: Obtener clave privada (desencriptada)                  │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ función: obtenerClavePrivadaDesencriptada(usuarioId)    │  │  │
│  │  │                                                          │  │  │
│  │  │ 1. SELECT de usuario_claves                             │  │  │
│  │  │    - clave_privada_encriptada (formato: encrypted::salt)│  │  │
│  │  │    - iv_encriptacion                                    │  │  │
│  │  │                                                          │  │  │
│  │  │ 2. Derivar clave de desencriptación                     │  │  │
│  │  │    clave = PBKDF2(                                       │  │  │
│  │  │        input: usuarioId,                                │  │  │
│  │  │        salt: salt_base64_decodificado,                  │  │  │
│  │  │        iteraciones: 100000,                             │  │  │
│  │  │        length: 32 bytes,                                │  │  │
│  │  │        hash: SHA-256                                    │  │  │
│  │  │    )                                                     │  │  │
│  │  │                                                          │  │  │
│  │  │ 3. Desencriptar clave privada                           │  │  │
│  │  │    privatePem = openssl_decrypt(                        │  │  │
│  │  │        encrypted,                                       │  │  │
│  │  │        "AES-256-CBC",                                   │  │  │
│  │  │        clave,                                           │  │  │
│  │  │        iv                                               │  │  │
│  │  │    )                                                     │  │  │
│  │  │                                                          │  │  │
│  │  │ RESULTADO: Clave privada PEM desencriptada              │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 4: Firmar contenido (RSA-3072 + SHA-256)                  │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ función: firmarContenido(contenidoFirmar, usuarioId)    │  │  │
│  │  │                                                          │  │  │
│  │  │ 1. openssl_pkey_get_private(privatePem)                 │  │  │
│  │  │    → Cargar la clave privada RSA-3072                   │  │  │
│  │  │                                                          │  │  │
│  │  │ 2. openssl_sign(                                        │  │  │
│  │  │        contenidoFirmar,                                 │  │  │
│  │  │        $signature,     (output: binary)                 │  │  │
│  │  │        privateKey,                                      │  │  │
│  │  │        OPENSSL_ALGO_SHA256                              │  │  │
│  │  │    )                                                     │  │  │
│  │  │                                                          │  │  │
│  │  │ 3. Codificar firma en base64                            │  │  │
│  │  │    firma = base64_encode(signature)                     │  │  │
│  │  │                                                          │  │  │
│  │  │ RESULTADO: Firma en base64 (~512 caracteres)            │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 5: Actualizar documento con firma                         │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ UPDATE documentos SET                                   │  │  │
│  │  │     estado = "emitido",                                 │  │  │
│  │  │     firmado_por_usuario_id = usuarioId,                 │  │  │
│  │  │     fecha_emision = NOW(),                              │  │  │
│  │  │     firma = firma_base64                                │  │  │
│  │  │ WHERE id = docId                                        │  │  │
│  │  │                                                          │  │  │
│  │  │ ESTADO EN BD:                                            │  │  │
│  │  │ - estado: "emitido" ✓                                   │  │  │
│  │  │ - firma: "base64_encoded_signature..." ✓                │  │  │
│  │  │ - firmado_por_usuario_id: usuarioId ✓                   │  │  │
│  │  │ - fecha_emision: timestamp ✓                            │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 6: Registrar en bitácora                                  │  │
│  │                                                                  │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                       │
│  ✅ DOCUMENTO AHORA ESTÁ EMITIDO Y FIRMADO                          │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

---

## ✅ Flujo de Verificación Pública (Sin Autenticación)

```
┌──────────────────────────────────────────────────────────────────────┐
│  FASE 4: VERIFICAR DOCUMENTO (Público, sin autenticación)             │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  GET /verificar.html?folio=DOC-20260511-ABC123                       │
│  o                                                                    │
│  GET /api/consulta_qr.php?token=DOC-20260511-ABC123                 │
│                                                                       │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ PASO 1: Obtener documento de BD (sin autenticación)            │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ SELECT d.*, u.nombre, f.nombre                           │  │  │
│  │  │ FROM documentos d                                         │  │  │
│  │  │ JOIN usuarios u ON u.id = d.creado_por                   │  │  │
│  │  │ LEFT JOIN usuarios f ON f.id = d.firmado_por_usuario_id  │  │  │
│  │  │ WHERE d.folio = ?                                         │  │  │
│  │  │                                                          │  │  │
│  │  │ Resultado:                                               │  │  │
│  │  │ {                                                        │  │  │
│  │  │   folio: "DOC-20260511-ABC123",                         │  │  │
│  │  │   estado: "emitido",                                    │  │  │
│  │  │   contenido: "Texto del documento",                     │  │  │
│  │  │   firma: "base64_encoded_signature...",                │  │  │
│  │  │   firmado_por_usuario_id: 42,                           │  │  │
│  │  │   creador_nombre: "Juan López",                         │  │  │
│  │  │   firmante_nombre: "Juan López"                         │  │  │
│  │  │ }                                                        │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 2: Validar que documento esté emitido                     │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ if estado !== "emitido" OR !firma:                       │  │  │
│  │  │     return {                                             │  │  │
│  │  │         firma_valida: false,                             │  │  │
│  │  │         mensaje: "Documento no emitido"                  │  │  │
│  │  │     }                                                    │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 3: Obtener clave pública del firmante                     │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ función: obtenerClavePublica(firmado_por_usuario_id)    │  │  │
│  │  │                                                          │  │  │
│  │  │ SELECT certificado_publico FROM usuario_claves          │  │  │
│  │  │ WHERE usuario_id = ? AND activo = 1                     │  │  │
│  │  │                                                          │  │  │
│  │  │ RESULTADO: Clave pública PEM (sin encriptación)          │  │  │
│  │  │ Ejemplo:                                                 │  │  │
│  │  │ -----BEGIN PUBLIC KEY-----                               │  │  │
│  │  │ MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC...              │  │  │
│  │  │ -----END PUBLIC KEY-----                                 │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 4: Reconstituir mensaje original (IGUAL a la emisión)    │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ contenidoFirmar = folio + "|" + contenido              │  │  │
│  │  │                                                          │  │  │
│  │  │ Ejemplo:                                                 │  │  │
│  │  │ "DOC-20260511-ABC123|Texto del documento"              │  │  │
│  │  │                                                          │  │  │
│  │  │ ⚠️ CRÍTICO: Debe ser EXACTAMENTE igual al que se firmó  │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 5: Verificar firma (RSA-3072 + SHA-256)                   │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ función: verificarFirma(contenido, firma_b64, pubPem)   │  │  │
│  │  │                                                          │  │  │
│  │  │ 1. openssl_pkey_get_public(publicPem)                   │  │  │
│  │  │    → Cargar clave pública RSA-3072                       │  │  │
│  │  │                                                          │  │  │
│  │  │ 2. base64_decode(firma_b64)                             │  │  │
│  │  │    → Obtener firma en formato binario                    │  │  │
│  │  │                                                          │  │  │
│  │  │ 3. openssl_verify(                                      │  │  │
│  │  │        contenidoFirmar,                                 │  │  │
│  │  │        signature_binary,                                │  │  │
│  │  │        publicKey,                                       │  │  │
│  │  │        OPENSSL_ALGO_SHA256                              │  │  │
│  │  │    )                                                     │  │  │
│  │  │                                                          │  │  │
│  │  │ RESULTADO:                                               │  │  │
│  │  │ - 1:  Firma válida ✓                                    │  │  │
│  │  │ - 0:  Firma inválida ✗                                  │  │  │
│  │  │ - -1: Error en verificación                             │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  │ PASO 6: Devolver respuesta al cliente                          │  │
│  │  ┌──────────────────────────────────────────────────────────┐  │  │
│  │  │ return {                                                 │  │  │
│  │  │     documento: {                                         │  │  │
│  │  │         folio: "DOC-20260511-ABC123",                   │  │  │
│  │  │         titulo: "Certificado",                          │  │  │
│  │  │         estado: "emitido",                              │  │  │
│  │  │         creador: "Juan López",                          │  │  │
│  │  │         firmante: "Juan López",                         │  │  │
│  │  │         fecha_creacion: "2026-05-11 10:30:00",          │  │  │
│  │  │         fecha_emision: "2026-05-11 10:32:00"            │  │  │
│  │  │     },                                                   │  │  │
│  │  │     verificacion: {                                      │  │  │
│  │  │         firma_valida: true,  ← Resultado de OpenSSL     │  │  │
│  │  │         mensaje: "Firma digital válida ✓"               │  │  │
│  │  │     }                                                    │  │  │
│  │  │ }                                                        │  │  │
│  │  └──────────────────────────────────────────────────────────┘  │  │
│  │                                                                  │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                       │
│  ✅ VERIFICACIÓN COMPLETADA (resultado público)                      │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 🔄 Flujo de Revocación

```
┌──────────────────────────────────────────────────────────────────────┐
│  FASE 5: REVOCAR DOCUMENTO (Estado: EMITIDO → REVOCADO)              │
├──────────────────────────────────────────────────────────────────────┤
│                                                                       │
│  POST /api/documentos-revocar.php/{docId}                            │
│  {                                                                    │
│      "motivo": "Documento emitido por error"                         │
│  }                                                                    │
│                                                                       │
│  ┌────────────────────────────────────────────────────────────────┐  │
│  │ 1. Validar documento existe y está emitido                     │  │
│  │ 2. Actualizar estado a "revocado"                              │  │
│  │ 3. Guardar motivo de revocación                                │  │
│  │ 4. Registrar timestamp de revocación                           │  │
│  │ 5. Registrar en bitácora                                       │  │
│  │                                                                 │  │
│  │ ESTADO EN BD:                                                   │  │
│  │ - estado: "revocado"                                           │  │
│  │ - fecha_revocacion: NOW()                                      │  │
│  │ - motivo_revocacion: "Documento emitido por error"             │  │
│  │                                                                 │  │
│  │ NOTA: La firma permanece en la BD para auditoría               │  │
│  │ Cuando se verifica un documento revocado, se devuelve:         │  │
│  │ {                                                              │  │
│  │     estado: "revocado",                                        │  │
│  │     fecha_revocacion: "2026-05-11 10:35:00",                  │  │
│  │     motivo_revocacion: "Documento emitido por error"           │  │
│  │ }                                                              │  │
│  │                                                                 │  │
│  └────────────────────────────────────────────────────────────────┘  │
│                                                                       │
└──────────────────────────────────────────────────────────────────────┘
```

---

## 🔗 Diagrama de Relaciones de Tablas

```
┌─────────────────────────────────────────────────────────────────────┐
│                    MODELO DE DATOS                                  │
└─────────────────────────────────────────────────────────────────────┘

usuarios (tabla central)
├── id (PK)
├── nombre
├── email
├── password_hash (bcrypt, cost 10)
├── rol (administrador, supervisor, emisor, verificador, consultor)
└── activo

        ↓ 1:1 ↓                    ↓ 1:M ↓                  ↓ 1:M ↓
        │     │                    │     │                  │     │
usuario_claves             documentos (creado_por)   bitacora (usuario_id)
├── id (PK)               ├── id (PK)                 ├── id (PK)
├── usuario_id (FK, U)    ├── folio (UNIQUE)          ├── usuario_id (FK)
├── version               ├── titulo                  ├── documento_folio
├── activo                ├── contenido               ├── accion
├── clave_privada_        ├── hash_sha256             ├── resultado
│   encriptada (AES-256)  ├── estado                  └── fecha
├── iv_encriptacion       ├── creado_por (FK)
├── certificado_publico   ├── firmado_por_usuario_id
├── fingerprint (SHA256)  ├── firma (base64)
└── created_at            └── fecha_emision


key_download_tokens
├── id (PK)
├── usuario_id (FK)
├── token_hash (SHA256)
├── tipo (key, cer)
├── expires_at
└── used_at (single-use)
```

---

## 🔐 Seguridad en Cada Punto

| Punto | Mecanismo | Detalles |
|-------|-----------|----------|
| **Clave privada** | AES-256-CBC + PBKDF2 | 100k iteraciones, salt aleatorio, IV aleatorio |
| **Contraseña usuario** | bcrypt | cost = 10 |
| **Firma documento** | RSA-3072 + SHA-256 | Cumple estándares NIST |
| **Descarga claves** | Token single-use | SHA-256, TTL 10 min, marcado como usado |
| **Auditoría** | Tabla bitácora | Todos los eventos registrados |
| **BD queries** | Prepared statements | Previene SQL injection |
| **HTTP headers** | Security headers | CSRF, XSS, clickjacking |
| **Sesión** | HttpOnly cookie | Server-side, no accesible desde JS |

---

## 📊 Estados de Documento

```
┌──────────┐
│ BORRADOR │ ← Creación inicial
└────┬─────┘
     │
     ├─→ Editar (contenido, título)
     │
     ├─→ Eliminar (solo en borrador)
     │
     └─→ EMITIR (genera firma RSA-3072)
            │
            └──────┐
                   │
            ┌──────▼────────┐
            │    EMITIDO    │
            │  (firmado)    │
            └──────┬────────┘
                   │
                   └─→ Verificar (público, sin auth)
                   │
                   └─→ Revocar
                            │
                   ┌────────▼────────┐
                   │    REVOCADO     │
                   │ (auditable)     │
                   └─────────────────┘
```

---

## 🎯 Puntos Críticos del Pipeline

### 1️⃣ **Generación de claves** (único por usuario)
- RSA-3072 es estándar NIST para documentos de largo plazo
- Clave privada NUNCA se envía al cliente (solo pública)
- Encriptación con derivación PBKDF2 usando ID de usuario

### 2️⃣ **Formato de firma** (CRÍTICO)
```
contenidoFirmar = folio + "|" + contenido

Ejemplo:
"DOC-20260511-ABC123|Texto del documento"

Este formato DEBE ser idéntico en:
1. Emisión (cuando se crea la firma)
2. Verificación (cuando se valida)

Si cambia, la firma no verifica.
```

### 3️⃣ **Desencriptación de clave privada**
- Ocurre en tiempo de emisión
- Solo disponible si el usuario está autenticado
- Usa PBKDF2 con salt almacenado + ID de usuario
- Si no puedes desencriptar → usuario no autorizado

### 4️⃣ **Verificación pública**
- No requiere autenticación
- Solo necesita clave pública (guardada en BD)
- La clave pública NO está encriptada (es pública)
- El resultado puede ser URL-compartible

### 5️⃣ **Revocación es auditable**
- No elimina la firma (la mantiene para auditoría)
- Simplemente marca como "revocado"
- La verificación pública mostrará el estado revocado

---

## 🚀 Flujo Completo Resumido

```
┌─────────────────────────────────────────────────────────────────────┐
│                     FLUJO COMPLETO                                  │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  USUARIO 1: Registra cuenta                                         │
│  ↓                                                                   │
│  Sistema genera RSA-3072 para Usuario 1                             │
│  ├─ Privada encriptada en BD                                        │
│  └─ Pública guardada en BD                                          │
│                                                                      │
│  USUARIO 1: Crea documento                                          │
│  ↓                                                                   │
│  Documento en estado "borrador"                                     │
│  ├─ Se calcula SHA-256(contenido)                                   │
│  └─ Se registra en bitácora                                         │
│                                                                      │
│  USUARIO 1: Emite documento                                         │
│  ↓                                                                   │
│  1. Obtiene su clave privada (desencriptada con PBKDF2)            │
│  2. Crea mensaje: folio + "|" + contenido                          │
│  3. Firma con RSA-3072 SHA-256                                      │
│  4. Guarda firma en BD, estado → "emitido"                         │
│  5. Registra en bitácora                                            │
│                                                                      │
│  USUARIO 2 (o anónimo): Verifica documento                         │
│  ↓                                                                   │
│  GET /api/consulta_qr.php?token=DOC-20260511-ABC123               │
│  ├─ SIN autenticación                                               │
│  ├─ Obtiene documento de BD (folio público)                        │
│  ├─ Obtiene clave pública del firmante                             │
│  ├─ Reconstruye: folio + "|" + contenido                          │
│  ├─ Verifica RSA-3072 + SHA-256                                    │
│  └─ Retorna: ✓ Firma válida O ✗ Firma inválida                     │
│                                                                      │
│  USUARIO 1: Revoca documento (si es necesario)                     │
│  ↓                                                                   │
│  Estado → "revocado"                                                │
│  Motivo guardado en BD                                              │
│  Verificación pública muestra: "Documento revocado"                 │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 📚 Referencias de Código

| Función | Archivo | Línea |
|---------|---------|-------|
| `generarLlavesRSA()` | `src/modules/claves.php` | 9 |
| `encriptarPrivada()` | `src/modules/crypto.php` | 12 |
| `crearDocumento()` | `src/modules/documentos.php` | 16 |
| `emitirDocumento()` | `src/modules/documentos.php` | 106 |
| `firmarContenido()` | `src/modules/claves.php` | 123 |
| `verificarFirma()` | `src/modules/claves.php` | 133 |
| Verificación pública | `src/api/consulta_qr.php` | - |
| `revocarDocumento()` | `src/modules/documentos.php` | 128 |
