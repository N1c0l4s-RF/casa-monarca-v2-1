# Casa Monarca v2

Plataforma institucional de gestión segura de documentos digitales con firmas RSA-3072, control de acceso basado en roles y auditoría completa.

## Stack

- **Backend**: PHP 8.2 + Apache 2.4
- **Base de datos**: MySQL 8.0
- **Frontend**: HTML5 + CSS3 + Vanilla JS (ES6 modules)
- **Criptografía**: ECDSA P-256 (firmas client-side), SHA-256, BIP39 mnemonics
- **Deploy**: Docker Compose

## Inicio rápido

```bash
# 1. Clonar el repo
git clone https://github.com/TU_USUARIO/casa-monarca-v2.git
cd casa-monarca-v2

# 2. Configurar variables de entorno
cp .env.example .env
# Editar .env con tus valores

# 3. Levantar servicios
docker-compose up -d

# 4. Abrir en el navegador
open http://localhost:8081
```

## Credenciales por defecto

| Campo | Valor |
|-------|-------|
| Email | `admin@empresa.local` |
| Password | `admin123` |
| Rol | Administrador |

> ⚠️ **Cambia la contraseña del admin en producción.**

## Roles y permisos

| Rol | Crear | Emitir | Revocar | Gestión usuarios | Llaves |
|-----|-------|--------|---------|------------------|--------|
| Administrador | ✅ | ✅ | ✅ | ✅ | ✅ |
| Supervisor | ❌ | ✅ | ✅ | ❌ | ✅ |
| Emisor | ✅ | ✅ | ❌ | ❌ | ❌ |
| Verificador | ❌ | ❌ | ❌ | ❌ | ❌ |
| Consultor | ❌ | ❌ | ❌ | ❌ | ❌ |

## Estructura

```
casa-monarca-v2/
├── frontend/           # HTML, CSS, JS
├── src/
│   ├── auth/           # Login, logout, session, middleware
│   ├── api/            # Endpoints REST
│   ├── modules/        # Lógica de negocio
│   └── config/         # Conexión DB
├── database/           # Schema SQL y migrations
├── Dockerfile
└── docker-compose.yml
```

## Variables de entorno

| Variable | Descripción |
|----------|-------------|
| `APP_ENV` | `development` o `production` |
| `DB_HOST` | Host MySQL (default: `db`) |
| `DB_NAME` | Nombre de la base de datos |
| `DB_USER` | Usuario MySQL |
| `DB_PASS` | Contraseña MySQL |
| `SESSION_LIFETIME` | Duración de sesión en segundos |

## Seguridad

- Contraseñas hasheadas con **bcrypt** (cost 10)
- Llaves privadas encriptadas con **AES-256-CBC + PBKDF2** (100k iteraciones)
- Tokens de descarga **SHA-256**, single-use, TTL 10 min
- Todos los queries con **prepared statements**
- Headers HTTP de seguridad en todas las respuestas
- Sesiones PHP server-side con HttpOnly cookie

## Comandos Docker útiles

```bash
docker-compose up -d          # Iniciar
docker-compose logs -f web    # Ver logs
docker-compose down           # Detener
docker-compose down -v        # Reset completo (pierde datos)
docker exec web php -v        # Verificar PHP
```

## Validación pública

Los documentos emitidos pueden verificarse sin autenticación:

```
http://localhost:8081/verificar.html?folio=DOC-20260428-XXXXXX
```

## Firmas múltiples (multi-firma)

Un documento puede requerir N firmas en orden estricto. Se configura al crearlo:

1. En "Nuevo documento" activa el toggle **"Requiere múltiples firmas en orden"**
2. Añade los firmantes en el orden deseado (chips numerados con flechas para reordenar)
3. Cada firmante solo puede firmar cuando le toca su turno; el doc queda en estado `en_firma`
4. Al completar la última firma, el doc pasa a `emitido`
5. La verificación pública valida toda la cadena en orden

**Excepción**: si el creador es administrador, se auto-añade como firmante final.

## Próximas fases

- [ ] Integración PKCS#7 / timestamp de confianza
- [ ] Dashboard de métricas
- [ ] API pública con webhooks
- [ ] OAuth2 / SAML
- [ ] App móvil
