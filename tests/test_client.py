import pytest
import responses
from requests.exceptions import ConnectionError
from casa_monarca_sdk.client import CasaMonarcaClient
from casa_monarca_sdk.exceptions import AuthError, APIError, ValidationError

BASE_URL = "http://localhost:8081"


@pytest.fixture
def client():
    return CasaMonarcaClient(BASE_URL)


def test_client_initialization(client):
    """Valida que el cliente guarde correctamente la URL base y configure el timeout."""
    assert client.base_url == "http://localhost:8081"
    assert client.timeout == 10

    # Valida remoción de la barra diagonal final
    c = CasaMonarcaClient("http://localhost:8081/")
    assert c.base_url == "http://localhost:8081"


@responses.activate
def test_login_success(client):
    """Valida un inicio de sesión exitoso y que las cookies se persistan."""
    mock_response = {
        "status": "success",
        "data": {
            "usuario": {
                "id": "1",
                "nombre": "Juan Pérez",
                "email": "juan@empresa.local",
                "rol": "administrador",
                "activo": "1",
                "fecha_creacion": "2026-06-12 10:16:03",
                "ultimo_login": "2026-06-12 10:16:03",
            }
        },
    }

    responses.add(
        responses.POST,
        f"{BASE_URL}/auth/login.php",
        json=mock_response,
        status=200,
        headers={"Set-Cookie": "PHPSESSID=session123"},
    )

    user = client.login("juan@empresa.local", "secreto123")
    assert user.id == 1
    assert user.nombre == "Juan Pérez"
    assert user.email == "juan@empresa.local"
    assert user.rol == "administrador"
    assert user.activo is True
    assert client.session.cookies.get("PHPSESSID") == "session123"


@pytest.mark.parametrize(
    "email,password",
    [
        ("", "secreto123"),
        ("juan@empresa.local", ""),
        ("", ""),
    ],
)
def test_login_validation_error(client, email, password):
    """Valida que campos vacíos levanten un ValidationError localmente."""
    with pytest.raises(ValidationError):
        client.login(email, password)


@responses.activate
def test_login_failed_auth_error(client):
    """Valida que una respuesta 401 del servidor levante AuthError."""
    mock_response = {"status": "error", "message": "Credenciales inválidas"}

    responses.add(
        responses.POST,
        f"{BASE_URL}/auth/login.php",
        json=mock_response,
        status=401,
    )

    with pytest.raises(AuthError) as exc_info:
        client.login("juan@empresa.local", "incorrecto")
    assert exc_info.value.code == 401
    assert "Credenciales inválidas" in exc_info.value.message


@responses.activate
def test_logout(client):
    """Valida que el cierre de sesión limpie las cookies locales."""
    responses.add(
        responses.POST,
        f"{BASE_URL}/auth/logout.php",
        json={"status": "success"},
        status=200,
    )
    client.session.cookies.set("PHPSESSID", "session123")
    client.logout()
    assert len(client.session.cookies) == 0


@responses.activate
def test_get_session(client):
    """Valida la recuperación correcta de los detalles de sesión actuales."""
    mock_response = {
        "status": "success",
        "data": {
            "usuario": {
                "id": 1,
                "nombre": "Juan Pérez",
                "email": "juan@empresa.local",
                "rol": "administrador",
                "activo": 1,
            }
        },
    }
    responses.add(
        responses.GET,
        f"{BASE_URL}/auth/session.php",
        json=mock_response,
        status=200,
    )
    user = client.get_session()
    assert user.id == 1
    assert user.nombre == "Juan Pérez"


@responses.activate
def test_list_documents(client):
    """Valida el listado correcto de documentos asociados al usuario."""
    mock_response = {
        "status": "success",
        "data": {
            "documentos": [
                {
                    "id": 10,
                    "folio": "DOC-2026-A1B2",
                    "titulo": "Contrato A",
                    "estado": "borrador",
                    "creado_por": 1,
                    "fecha_creacion": "2026-06-12 10:16:03",
                }
            ]
        },
    }
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/documentos-list.php",
        json=mock_response,
        status=200,
    )
    docs = client.list_documents()
    assert len(docs) == 1
    assert docs[0].id == 10
    assert docs[0].titulo == "Contrato A"
    assert docs[0].estado == "borrador"


@responses.activate
def test_create_document(client, tmp_path):
    """Valida la creación de un borrador subiendo un archivo PDF real simulado."""
    temp_pdf = tmp_path / "contrato.pdf"
    temp_pdf.write_bytes(b"%PDF-1.4 dummy contents")

    mock_response = {
        "status": "success",
        "data": {
            "documento_id": 15,
            "folio": "DOC-2026-X1Y2",
            "hash_sha256": "4a5e6f332211aa887766554433221100aa887766554433221100aa8877665544",
        },
    }

    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-create.php",
        json=mock_response,
        status=200,
    )

    doc = client.create_document(
        "Contrato Servicios", str(temp_pdf), "Descripción corta"
    )
    assert doc.id == 15
    assert doc.folio == "DOC-2026-X1Y2"
    assert (
        doc.hash_sha256
        == "4a5e6f332211aa887766554433221100aa887766554433221100aa8877665544"
    )
    assert doc.estado == "borrador"
    assert doc.descripcion == "Descripción corta"

    # Validaciones locales de fallos de entrada
    with pytest.raises(ValidationError):
        client.create_document("  ", str(temp_pdf))
    with pytest.raises(ValidationError):
        client.create_document("Titulo", "archivo_inexistente.pdf")

    temp_txt = tmp_path / "contrato.txt"
    temp_txt.write_text("not a pdf")
    with pytest.raises(ValidationError):
        client.create_document("Titulo", str(temp_txt))


@responses.activate
def test_update_and_delete_document(client):
    """Valida la actualización y eliminación de borradores."""
    # Actualización exitosa
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-update.php",
        json={"status": "success"},
        status=200,
    )
    client.update_document(15, "Nuevo Titulo", "Nueva desc")

    # Validaciones de actualización
    with pytest.raises(ValidationError):
        client.update_document(0, "Nuevo Titulo")
    with pytest.raises(ValidationError):
        client.update_document(15, "  ")

    # Eliminación exitosa
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-delete.php",
        json={"status": "success"},
        status=200,
    )
    client.delete_document(15)

    with pytest.raises(ValidationError):
        client.delete_document(0)


@responses.activate
def test_revoke_document(client):
    """Valida la revocación de un documento emitido."""
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-revocar.php",
        json={"status": "success"},
        status=200,
    )
    client.revoke_document(15, "Motivo de prueba")

    with pytest.raises(ValidationError):
        client.revoke_document(0, "Motivo")
    with pytest.raises(ValidationError):
        client.revoke_document(15, "   ")


@responses.activate
def test_assign_and_list_signers(client):
    """Valida la asignación secuencial y consulta de firmantes."""
    # Asignar firmantes
    mock_assign_response = {
        "status": "success",
        "data": {
            "firmantes": [
                {
                    "id": 1,
                    "usuario_id": 2,
                    "orden": 1,
                    "estado": "pendiente",
                    "nombre": "Firmante Uno",
                    "email": "f1@test.com",
                    "rol": "emisor",
                }
            ]
        },
    }
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-firmantes-asignar.php",
        json=mock_assign_response,
        status=200,
    )

    signers = client.assign_signers(15, [2])
    assert len(signers) == 1
    assert signers[0].usuario_id == 2
    assert signers[0].nombre == "Firmante Uno"

    with pytest.raises(ValidationError):
        client.assign_signers(0, [2])
    with pytest.raises(ValidationError):
        client.assign_signers(15, [])

    # Listar firmantes
    mock_list_response = {
        "status": "success",
        "data": {
            "firmantes": [
                {
                    "id": 1,
                    "usuario_id": 2,
                    "orden": 1,
                    "estado": "pendiente",
                    "nombre": "Firmante Uno",
                    "email": "f1@test.com",
                    "rol": "emisor",
                }
            ],
            "siguiente_usuario_id": 2,
            "siguiente_orden": 1,
        },
    }
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/documentos-firmantes-list.php",
        json=mock_list_response,
        status=200,
    )

    signers_list, next_uid, next_order = client.list_signers(15)
    assert len(signers_list) == 1
    assert next_uid == 2
    assert next_order == 1

    with pytest.raises(ValidationError):
        client.list_signers(0)


@responses.activate
def test_get_key_info_and_register_public_key(client):
    """Valida la obtención de información criptográfica y registro de clave pública."""
    mock_key_info = {
        "status": "success",
        "data": {
            "clave": {
                "version": 1,
                "activo": 1,
                "fingerprint": "fp1234",
                "created_at": "2026-06-12 10:16:03",
                "download_count": 0,
            }
        },
    }
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/claves-info.php",
        json=mock_key_info,
        status=200,
    )

    kinfo = client.get_key_info()
    assert kinfo.fingerprint == "fp1234"
    assert kinfo.activo is True

    # Caso en que la clave no exista (HTTP 404)
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/claves-info.php",
        json={"status": "error", "message": "No encontrado"},
        status=404,
    )
    assert client.get_key_info() is None

    # Caso en que falle por error del servidor (500)
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/claves-info.php",
        json={"status": "error", "message": "Error interno"},
        status=500,
    )
    with pytest.raises(APIError):
        client.get_key_info()

    # Registrar clave pública
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/claves-registrar-publica.php",
        json={"status": "success", "data": {"fingerprint": "newfp"}},
        status=200,
    )

    pub_key_66 = "0" * 66
    fp = client.register_public_key(pub_key_66)
    assert fp == "newfp"

    with pytest.raises(ValidationError):
        client.register_public_key("0" * 50)


@responses.activate
def test_solicitar_completar_y_sign_document(client):
    """Valida los flujos de firma paso a paso e integrado (sign_document)."""
    mock_solicitar = {
        "status": "success",
        "data": {
            "hash": "4a5e6f332211aa887766554433221100aa887766554433221100aa8877665544",
            "session_id": 999,
            "folio": "DOC-2026-X1Y2",
            "orden": 1,
            "es_multifirma": False,
        },
    }
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-solicitar-firma.php",
        json=mock_solicitar,
        status=200,
    )

    info = client.solicitar_firma(15)
    assert (
        info["hash"]
        == "4a5e6f332211aa887766554433221100aa887766554433221100aa8877665544"
    )
    assert info["session_id"] == 999
    with pytest.raises(ValidationError):
        client.solicitar_firma(0)

    # Completar firma
    mock_completar = {
        "status": "success",
        "data": {"completado": True, "folio": "DOC-2026-X1Y2"},
    }
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-completar-firma.php",
        json=mock_completar,
        status=200,
    )

    sig_128 = "a" * 128
    res = client.completar_firma(15, sig_128, 999)
    assert res["completado"] is True

    # Validaciones de completar
    with pytest.raises(ValidationError):
        client.completar_firma(0, sig_128, 999)
    with pytest.raises(ValidationError):
        client.completar_firma(15, "firma_corta", 999)
    with pytest.raises(ValidationError):
        client.completar_firma(15, sig_128, 0)

    # Método de alto nivel sign_document (flujo completo integrado)
    mnemonic = "ábaco abdomen abeja abierto abogado abono aborto abrazo abrir abuelo abuso acabar"
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-solicitar-firma.php",
        json=mock_solicitar,
        status=200,
    )
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/documentos-completar-firma.php",
        json=mock_completar,
        status=200,
    )

    result = client.sign_document(15, mnemonic)
    assert result["completado"] is True


@responses.activate
def test_download_document(client, tmp_path):
    """Valida la descarga de archivos PDF del servidor."""
    dest_file = tmp_path / "dest.pdf"

    responses.add(
        responses.GET,
        f"{BASE_URL}/api/documentos-archivo.php",
        body=b"%PDF-1.4 simulated file content",
        status=200,
        headers={"Content-Type": "application/pdf"},
    )

    client.download_document("DOC-2026", str(dest_file))
    assert dest_file.exists()
    assert dest_file.read_bytes() == b"%PDF-1.4 simulated file content"

    # Validaciones locales
    with pytest.raises(ValidationError):
        client.download_document("", str(dest_file))
    with pytest.raises(ValidationError):
        client.download_document("INVALID-FOLIO", str(dest_file))

    # Error retornado por el servidor en la descarga
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/documentos-archivo.php",
        json={"status": "error", "message": "No autorizado"},
        status=403,
        headers={"Content-Type": "application/json"},
    )
    with pytest.raises(APIError):
        client.download_document("DOC-403", str(dest_file))


@responses.activate
def test_verify_document(client):
    """Valida la consulta pública y verificación de firmas de un folio."""
    mock_verify = {
        "status": "success",
        "data": {
            "documento": {
                "folio": "DOC-123",
                "titulo": "Doc verificado",
                "estado": "emitido",
                "creador": "Juan Creador",
                "es_multifirma": 0,
                "fecha_creacion": "2026-06-12 10:16:03",
            },
            "verificacion": {
                "firma_valida": 1,
                "mensaje": "Firma totalmente verificada",
                "firmas": [
                    {
                        "orden": 1,
                        "firmante": "Juan Creador",
                        "rol": "emisor",
                        "fecha_firma": "2026-06-12 10:16:03",
                        "firma_valida": 1,
                    }
                ],
            },
        },
    }
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/consulta_qr.php",
        json=mock_verify,
        status=200,
    )

    res = client.verify_document("DOC-123")
    assert res.folio == "DOC-123"
    assert res.firma_valida is True
    assert len(res.firmas) == 1
    assert res.firmas[0].firmante == "Juan Creador"
    assert res.firmas[0].firma_valida is True

    with pytest.raises(ValidationError):
        client.verify_document("")


@responses.activate
def test_user_management(client):
    """Valida las operaciones de administración de usuarios."""
    # Listar usuarios
    mock_list = {
        "status": "success",
        "data": {
            "usuarios": [
                {
                    "id": 1,
                    "nombre": "User 1",
                    "email": "u1@test.com",
                    "rol": "supervisor",
                    "activo": 1,
                }
            ]
        },
    }
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/usuarios-list.php",
        json=mock_list,
        status=200,
    )
    users = client.list_users()
    assert len(users) == 1
    assert users[0].nombre == "User 1"

    # Crear usuario
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/usuarios-create.php",
        json={"status": "success", "data": {"usuario_id": 88}},
        status=200,
    )
    uid = client.create_user("Nuevo", "nuevo@test.com", "pass123", "emisor")
    assert uid == 88

    with pytest.raises(ValidationError):
        client.create_user("", "nuevo@test.com", "pass123", "emisor")
    with pytest.raises(ValidationError):
        client.create_user("Nuevo", "nuevo@test.com", "12345", "emisor")

    # Cambiar rol
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/usuarios-cambiar-rol.php",
        json={"status": "success"},
        status=200,
    )
    client.change_user_role(88, "supervisor")

    with pytest.raises(ValidationError):
        client.change_user_role(0, "supervisor")

    # Desactivar usuario
    responses.add(
        responses.POST,
        f"{BASE_URL}/api/usuarios-desactivar.php",
        json={"status": "success"},
        status=200,
    )
    client.deactivate_user(88)

    with pytest.raises(ValidationError):
        client.deactivate_user(0)


@responses.activate
def test_audit_logs_and_summary(client):
    """Valida la bitácora de auditoría y el resumen del administrador."""
    # Bitácora
    mock_logs = {
        "status": "success",
        "data": {
            "registros": [
                {
                    "id": 1001,
                    "usuario_id": 1,
                    "accion": "login",
                    "modulo": "auth",
                    "fecha": "2026-06-12 10:16:03",
                }
            ],
            "total": 150,
        },
    }
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/bitacora-list.php",
        json=mock_logs,
        status=200,
    )
    logs, total = client.list_audit_logs()
    assert len(logs) == 1
    assert total == 150
    assert logs[0].id == 1001

    # Resumen
    mock_summary = {
        "status": "success",
        "data": {
            "documentos": {
                "total": 50,
                "emitidos": 20,
                "borradores": 25,
                "revocados": 5,
                "emitidos_hoy": 2,
                "revocados_hoy": 0,
            },
            "top_firmantes": [],
            "firmas_fallidas": 1,
            "firmas_fallidas_hoy": 0,
            "actividad_semana": [],
            "eventos": [],
        },
    }
    responses.add(
        responses.GET,
        f"{BASE_URL}/api/resumen-admin.php",
        json=mock_summary,
        status=200,
    )
    summary = client.get_admin_summary()
    assert summary.total_documentos == 50
    assert summary.emitidos == 20


@responses.activate
def test_api_error_handling(client):
    """Valida el control de excepciones de la API ante errores del backend."""
    # Retorno de estado de error por el servidor
    responses.add(
        responses.GET,
        f"{BASE_URL}/auth/session.php",
        json={"status": "error", "message": "Sesión inválida"},
        status=400,
    )
    with pytest.raises(APIError) as exc_info:
        client.get_session()
    assert exc_info.value.code == 400
    assert "Sesión inválida" in exc_info.value.message

    # Respuesta corrupta no JSON
    responses.add(
        responses.GET,
        f"{BASE_URL}/auth/session.php",
        body="Internal Server Error",
        status=500,
    )
    with pytest.raises(APIError) as exc_info:
        client.get_session()
    assert exc_info.value.code == 500
    assert "no es JSON válido" in exc_info.value.message

    # Error de conexión
    responses.add(
        responses.GET,
        f"{BASE_URL}/auth/session.php",
        body=ConnectionError("Host unresolved"),
    )
    with pytest.raises(APIError) as exc_info:
        client.get_session()
    assert "Error de conexión" in exc_info.value.message
