"""
Casa Monarca SDK - Command Line Interface (CLI).

Exposes command line utilities for key generation, signing, and administrative tasks.
"""

import argparse
import json
import os
import sys
from typing import Dict

from casa_monarca_sdk.client import CasaMonarcaClient
from casa_monarca_sdk.crypto import derivar_llaves, generar_mnemonic, validar_mnemonic
from casa_monarca_sdk.exceptions import CasaMonarcaError

CONFIG_PATH = os.path.expanduser("~/.casa_monarca_cli.json")


def load_client() -> CasaMonarcaClient:
    """Loads a client initialized with saved cookies and URL."""
    if not os.path.exists(CONFIG_PATH):
        print(
            "Error: No has iniciado sesión. Corre 'casa-monarca login <url> <email>' primero."
        )
        sys.exit(1)

    try:
        with open(CONFIG_PATH, "r") as f:
            config = json.load(f)
    except Exception as e:
        print(f"Error leyendo configuración: {e}")
        sys.exit(1)

    base_url = config.get("base_url")
    cookies = config.get("cookies", {})

    if not base_url:
        print("Error: Base URL no configurada.")
        sys.exit(1)

    client = CasaMonarcaClient(base_url)
    if cookies:
        client.session.cookies.update(cookies)
    return client


def save_config(base_url: str, cookies: Dict[str, str]) -> None:
    """Saves base url and cookies to home directory config file."""
    try:
        with open(CONFIG_PATH, "w") as f:
            json.dump({"base_url": base_url, "cookies": cookies}, f, indent=2)
    except Exception as e:
        print(f"Advertencia: No se pudo guardar la sesión en disco: {e}")


def handle_login(args: argparse.Namespace) -> None:
    """Authenticates the client and persists session cookies."""
    client = CasaMonarcaClient(args.url)
    try:
        user = client.login(args.email, args.password)
        cookies = client.session.cookies.get_dict()
        save_config(args.url, cookies)
        print(f"¡Inicio de sesión exitoso! Bienvenido, {user.nombre} ({user.rol}).")
    except CasaMonarcaError as e:
        print(f"Error al iniciar sesión: {e}")
        sys.exit(1)


def handle_logout(args: argparse.Namespace) -> None:
    """Performs server logout and clears local config file."""
    if os.path.exists(CONFIG_PATH):
        try:
            client = load_client()
            client.logout()
        except Exception:
            pass  # Fail silently on server logout if session is already dead
        try:
            os.remove(CONFIG_PATH)
            print("Sesión cerrada y configuración local eliminada.")
        except Exception as e:
            print(f"Error borrando archivo de configuración: {e}")
    else:
        print("No hay una sesión activa.")


def handle_keygen(args: argparse.Namespace) -> None:
    """Generates a new Spanish BIP39 mnemonic and derives keys."""
    try:
        mnemonic = generar_mnemonic()
        keys = derivar_llaves(mnemonic)
        print("=== NUEVA FRASE MNEMÓNICA (12 palabras en español) ===")
        print("Guarda estas palabras en un lugar seguro. El servidor nunca las verá.")
        print(f"\n{mnemonic}\n")
        print("=== LLAVES DERIVADAS ===")
        print(f"Clave Pública (Hex): {keys['public_key_hex']}")
        print(f"Fingerprint:          {hashlib_sha256(keys['public_key_hex'])}")
    except Exception as e:
        print(f"Error en keygen: {e}")
        sys.exit(1)


def hashlib_sha256(data: str) -> str:
    import hashlib

    return hashlib.sha256(data.encode("utf-8")).hexdigest()


def handle_derive(args: argparse.Namespace) -> None:
    """Derives key pair from a provided mnemonic."""
    mnemonic = " ".join(args.mnemonic)
    if not validar_mnemonic(mnemonic):
        print(
            "Error: Frase mnemónica inválida. Debe constar de 12 palabras válidas en español."
        )
        sys.exit(1)

    try:
        keys = derivar_llaves(mnemonic)
        print("=== LLAVES DERIVADAS ===")
        print(f"Clave Pública (Hex): {keys['public_key_hex']}")
        print(f"Fingerprint:          {hashlib_sha256(keys['public_key_hex'])}")
    except Exception as e:
        print(f"Error al derivar llaves: {e}")
        sys.exit(1)


def handle_session(args: argparse.Namespace) -> None:
    """Checks the status of the current user session."""
    client = load_client()
    try:
        user = client.get_session()
        print("=== SESIÓN ACTIVA ===")
        print(f"ID:       {user.id}")
        print(f"Nombre:   {user.nombre}")
        print(f"Email:    {user.email}")
        print(f"Rol:      {user.rol}")
        print(f"Activo:   {'Sí' if user.activo else 'No'}")
    except CasaMonarcaError as e:
        print(f"Error al consultar sesión: {e}")
        sys.exit(1)


def handle_doc_list(args: argparse.Namespace) -> None:
    """Lists all documents visible to the user."""
    client = load_client()
    try:
        docs = client.list_documents()
        if not docs:
            print("No se encontraron documentos.")
            return

        print(
            f"{'ID':<6} | {'Folio':<24} | {'Estado':<10} | {'Título':<30} | {'Firmantes':<10}"
        )
        print("-" * 90)
        for doc in docs:
            firmantes_str = (
                f"{doc.firmas_completadas or 0}/{doc.total_firmantes or 0}"
                if doc.total_firmantes
                else "1/1"
                if doc.estado == "emitido"
                else "0/1"
            )
            title_truncated = (
                doc.titulo[:27] + "..." if len(doc.titulo) > 30 else doc.titulo
            )
            print(
                f"{doc.id:<6} | {doc.folio:<24} | {doc.estado:<10} | {title_truncated:<30} | {firmantes_str:<10}"
            )
    except CasaMonarcaError as e:
        print(f"Error al listar documentos: {e}")
        sys.exit(1)


def handle_doc_create(args: argparse.Namespace) -> None:
    """Creates a new document draft by uploading a PDF."""
    client = load_client()
    try:
        doc = client.create_document(args.titulo, args.archivo, args.descripcion)
        print("Documento creado exitosamente.")
        print(f"ID:    {doc.id}")
        print(f"Folio: {doc.folio}")
        print(f"SHA-256: {doc.hash_sha256}")
    except CasaMonarcaError as e:
        print(f"Error al crear documento: {e}")
        sys.exit(1)


def handle_doc_sign(args: argparse.Namespace) -> None:
    """Executes the full local cryptographic signing process."""
    client = load_client()
    mnemonic = " ".join(args.mnemonic)
    if not validar_mnemonic(mnemonic):
        print("Error: Frase mnemónica inválida.")
        sys.exit(1)

    try:
        result = client.sign_document(args.id, mnemonic)
        print("¡Operación completada con éxito!")
        print(json.dumps(result, indent=2, ensure_ascii=False))
    except CasaMonarcaError as e:
        print(f"Error al firmar documento: {e}")
        sys.exit(1)


def handle_doc_verify(args: argparse.Namespace) -> None:
    """Executes public verification of a document folio."""
    # No authenticating load_client needed; public endpoint
    # Find base url from config, if not found, use default or ask for it
    base_url = "http://localhost:8081"
    if os.path.exists(CONFIG_PATH):
        try:
            with open(CONFIG_PATH, "r") as f:
                config = json.load(f)
                base_url = config.get("base_url", base_url)
        except Exception:
            pass

    client = CasaMonarcaClient(base_url)
    try:
        res = client.verify_document(args.folio)
        print("=== RESULTADO DE VERIFICACIÓN ===")
        print(f"Folio:            {res.folio}")
        print(f"Título:           {res.titulo}")
        print(f"Estado:           {res.estado}")
        print(f"Creador:          {res.creador}")
        print(f"Firmante Final:   {res.firmante or '—'}")
        print(f"Firma Válida:     {'SÍ ✓' if res.firma_valida else 'NO ✗'}")
        print(f"Mensaje:          {res.mensaje}")
        if res.es_multifirma:
            print("\nDetalle de Firmas Múltiples:")
            for f in res.firmas:
                status = "VÁLIDA ✓" if f.firma_valida else "PENDIENTE/INVÁLIDA ✗"
                print(f"  Turno {f.orden}: {f.firmante} ({f.rol}) — {status}")
    except CasaMonarcaError as e:
        print(f"Error al verificar documento: {e}")
        sys.exit(1)


def handle_user_list(args: argparse.Namespace) -> None:
    """Lists system users."""
    client = load_client()
    try:
        users = client.list_users()
        print(
            f"{'ID':<6} | {'Nombre':<25} | {'Email':<30} | {'Rol':<15} | {'Activo':<8}"
        )
        print("-" * 90)
        for u in users:
            print(
                f"{u.id:<6} | {u.nombre:<25} | {u.email:<30} | {u.rol:<15} | {'Sí' if u.activo else 'No':<8}"
            )
    except CasaMonarcaError as e:
        print(f"Error al listar usuarios: {e}")
        sys.exit(1)


def main() -> None:
    parser = argparse.ArgumentParser(
        prog="casa-monarca", description="Casa Monarca Digital Signature SDK CLI Tool"
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    # login
    p_login = subparsers.add_parser(
        "login", help="Inicia sesión en un servidor de Casa Monarca"
    )
    p_login.add_argument("url", help="URL del servidor (ej. http://localhost:8081)")
    p_login.add_argument("email", help="Correo electrónico")
    p_login.add_argument("password", help="Contraseña")
    p_login.set_defaults(func=handle_login)

    # logout
    p_logout = subparsers.add_parser(
        "logout", help="Cierra sesión y limpia credenciales guardadas"
    )
    p_logout.set_defaults(func=handle_logout)

    # keygen
    p_keygen = subparsers.add_parser(
        "keygen", help="Genera una nueva frase mnemónica y par de claves P-256"
    )
    p_keygen.set_defaults(func=handle_keygen)

    # derive
    p_derive = subparsers.add_parser(
        "derive", help="Deriva par de llaves a partir de una mnemónica"
    )
    p_derive.add_argument(
        "mnemonic", nargs=12, help="Las 12 palabras de la frase mnemónica"
    )
    p_derive.set_defaults(func=handle_derive)

    # session
    p_session = subparsers.add_parser(
        "session", help="Muestra información de la sesión de usuario activa"
    )
    p_session.set_defaults(func=handle_session)

    # doc-list
    p_doc_list = subparsers.add_parser(
        "doc-list", help="Lista los documentos visibles en la sesión"
    )
    p_doc_list.set_defaults(func=handle_doc_list)

    # doc-create
    p_doc_create = subparsers.add_parser(
        "doc-create", help="Crea un borrador de documento subiendo un PDF"
    )
    p_doc_create.add_argument("titulo", help="Título del documento")
    p_doc_create.add_argument("archivo", help="Ruta al archivo PDF local")
    p_doc_create.add_argument(
        "--descripcion", help="Descripción opcional del documento"
    )
    p_doc_create.set_defaults(func=handle_doc_create)

    # doc-sign
    p_doc_sign = subparsers.add_parser(
        "doc-sign", help="Firma localmente y emite un documento"
    )
    p_doc_sign.add_argument("id", type=int, help="ID del documento a firmar")
    p_doc_sign.add_argument(
        "mnemonic", nargs=12, help="Las 12 palabras de tu frase mnemónica"
    )
    p_doc_sign.set_defaults(func=handle_doc_sign)

    # doc-verify
    p_doc_verify = subparsers.add_parser(
        "doc-verify", help="Verificación pública de un folio de documento"
    )
    p_doc_verify.add_argument(
        "folio", help="Folio a verificar (ej. DOC-20260428-A1B2C3)"
    )
    p_doc_verify.set_defaults(func=handle_doc_verify)

    # user-list
    p_user_list = subparsers.add_parser(
        "user-list", help="Lista los usuarios del sistema (Admin/Supervisor)"
    )
    p_user_list.set_defaults(func=handle_user_list)

    args = parser.parse_args()
    args.func(args)


if __name__ == "__main__":
    main()
