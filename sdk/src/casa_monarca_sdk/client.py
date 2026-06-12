"""
Casa Monarca SDK - API Client.

This module provides the primary interface for interacting with the Casa Monarca v2 API.
It handles authentication, session persistence, document management, key registration,
multi-signing flows, and administrative actions.
"""

import os
from typing import Any, Dict, List, Optional, Tuple

import requests
from requests.exceptions import RequestException

from casa_monarca_sdk.crypto import derivar_llaves, firmar_hash
from casa_monarca_sdk.exceptions import (
    APIError,
    AuthError,
    CryptographyError,
    ValidationError,
)
from casa_monarca_sdk.models import (
    AuditLogEntry,
    DashboardSummary,
    Document,
    DocumentSigner,
    DocumentVerificationResult,
    KeyInfo,
    User,
)


class CasaMonarcaClient:
    """The main client for the Casa Monarca digital signature platform.

    Handles cookie-based sessions, request formatting, error translation, and local cryptography.
    """

    def __init__(self, base_url: str, timeout: int = 10) -> None:
        """Initializes the Casa Monarca Client.

        Args:
            base_url (str): The base URL of the Casa Monarca server (e.g. 'http://localhost:8081').
            timeout (int, optional): Request timeout in seconds. Defaults to 10.
        """
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.session = requests.Session()

    def _request(
        self,
        method: str,
        path: str,
        json_data: Optional[Dict[str, Any]] = None,
        params: Optional[Dict[str, Any]] = None,
        files: Optional[Dict[str, Any]] = None,
        data: Optional[Dict[str, Any]] = None,
        stream: bool = False,
    ) -> requests.Response:
        """Helper to make HTTP requests and handle errors."""
        url = f"{self.base_url}/{path.lstrip('/')}"
        try:
            response = self.session.request(
                method=method,
                url=url,
                json=json_data,
                params=params,
                files=files,
                data=data,
                timeout=self.timeout,
                stream=stream,
            )
        except RequestException as e:
            raise APIError(f"Error de conexión con el servidor: {e}")

        # Check for non-JSON content if error occurred or if file stream
        if stream or "application/pdf" in response.headers.get("Content-Type", ""):
            if response.status_code >= 400:
                self._handle_error_response(response)
            return response

        # Parse JSON
        try:
            resp_data = response.json()
        except ValueError:
            raise APIError(
                f"El servidor retornó una respuesta que no es JSON válido: {response.text[:200]}...",
                code=response.status_code,
            )

        if response.status_code == 401:
            raise AuthError(
                resp_data.get("message", "No autenticado"),
                code=401,
                response_data=resp_data,
            )

        if response.status_code >= 400 or resp_data.get("status") == "error":
            raise APIError(
                resp_data.get("message", "Error del servidor"),
                code=response.status_code,
                response_data=resp_data,
            )

        return response

    def _handle_error_response(self, response: requests.Response) -> None:
        """Helper to raise errors from non-JSON or streaming error responses."""
        try:
            resp_data = response.json()
            msg = resp_data.get("message", "Error de servidor")
        except ValueError:
            msg = f"Error del servidor (Código {response.status_code})"

        if response.status_code == 401:
            raise AuthError(msg, code=401)
        raise APIError(msg, code=response.status_code)

    # ==========================================
    # AUTHENTICATION
    # ==========================================

    def login(self, email: str, password: str) -> User:
        """Authenticates the user session with the server.

        Args:
            email (str): The user email address.
            password (str): The user password.

        Returns:
            User: The logged-in user profile.

        Raises:
            AuthError: If authentication fails (incorrect credentials, inactive user).
            ValidationError: If email or password is empty.
        """
        if not email or not password:
            raise ValidationError("Email y contraseña son obligatorios.")

        response = self._request(
            "POST", "auth/login.php", json_data={"email": email, "password": password}
        )
        data = response.json().get("data", {})
        return User.from_dict(data.get("usuario", {}))

    def logout(self) -> None:
        """Closes the current session on the server and clears local cookies.

        Raises:
            APIError: If the logout call fails.
        """
        self._request("POST", "auth/logout.php")
        self.session.cookies.clear()

    def get_session(self) -> User:
        """Gets current authenticated session details.

        Returns:
            User: Current user profile.

        Raises:
            AuthError: If the session is invalid or not authenticated.
        """
        response = self._request("GET", "auth/session.php")
        data = response.json().get("data", {})
        return User.from_dict(data.get("usuario", {}))

    # ==========================================
    # DOCUMENT MANAGEMENT
    # ==========================================

    def list_documents(self) -> List[Document]:
        """Lists documents visible to the current authenticated user.

        Returns:
            List[Document]: A list of documents.
        """
        response = self._request("GET", "api/documentos-list.php")
        docs_data = response.json().get("data", {}).get("documentos", [])
        return [Document.from_dict(doc) for doc in docs_data]

    def create_document(
        self, titulo: str, archivo_path: str, descripcion: Optional[str] = None
    ) -> Document:
        """Creates a new document draft (borrador) by uploading a PDF file.

        Args:
            titulo (str): Document title.
            archivo_path (str): Path to the PDF file on disk.
            descripcion (str, optional): Brief description of the document.

        Returns:
            Document: The created Document draft details.

        Raises:
            ValidationError: If title is empty, file does not exist, or is not a PDF.
            APIError: If server upload or database insertion fails.
        """
        if not titulo.strip():
            raise ValidationError("El título es obligatorio.")
        if not os.path.exists(archivo_path):
            raise ValidationError(
                f"El archivo no existe en la ruta especificada: {archivo_path}"
            )
        if not archivo_path.lower().endswith(".pdf"):
            raise ValidationError("Solo se permiten archivos PDF.")

        payload = {"titulo": titulo, "descripcion": descripcion or ""}
        try:
            with open(archivo_path, "rb") as f:
                files = {
                    "archivo": (os.path.basename(archivo_path), f, "application/pdf")
                }
                # Use data=payload for multipart/form-data with requests
                response = self._request(
                    "POST", "api/documentos-create.php", data=payload, files=files
                )
        except IOError as e:
            raise ValidationError(f"Error al abrir el archivo PDF: {e}")

        data = response.json().get("data", {})

        # Build Document object with what we have
        return Document(
            id=int(data["documento_id"]),
            folio=data["folio"],
            titulo=titulo,
            descripcion=descripcion,
            estado="borrador",
            creado_por=0,  # Unknown without reloading
            hash_sha256=data["hash_sha256"],
        )

    def update_document(
        self, doc_id: int, titulo: str, descripcion: Optional[str] = None
    ) -> None:
        """Updates a document draft (borrador). Only title and description can be updated.

        Args:
            doc_id (int): ID of the document.
            titulo (str): New title.
            descripcion (str, optional): New description.

        Raises:
            ValidationError: If doc_id or title is missing.
            APIError: If document is not in 'borrador' or belongs to another user.
        """
        if not doc_id:
            raise ValidationError("El ID del documento es obligatorio.")
        if not titulo.strip():
            raise ValidationError("El título es obligatorio.")

        payload = {"id": doc_id, "titulo": titulo, "descripcion": descripcion or ""}
        self._request("POST", "api/documentos-update.php", json_data=payload)

    def delete_document(self, doc_id: int) -> None:
        """Deletes a document draft (borrador).

        Args:
            doc_id (int): ID of the document.

        Raises:
            APIError: If document is not in 'borrador' or belongs to another user.
        """
        if not doc_id:
            raise ValidationError("El ID del documento es obligatorio.")
        self._request("POST", "api/documentos-delete.php", json_data={"id": doc_id})

    def revoke_document(self, doc_id: int, motivo: str) -> None:
        """Revokes an already emitted/signed document.

        Only administrators or supervisors can perform this action.

        Args:
            doc_id (int): ID of the document.
            motivo (str): Rationale for revoking the document.

        Raises:
            ValidationError: If doc_id or motivo is empty.
            APIError: If user lacks permissions or document is already revoked.
        """
        if not doc_id:
            raise ValidationError("El ID del documento es obligatorio.")
        if not motivo.strip():
            raise ValidationError("El motivo de revocación es obligatorio.")

        self._request(
            "POST",
            "api/documentos-revocar.php",
            json_data={"id": doc_id, "motivo": motivo},
        )

    # ==========================================
    # MULTI-SIGNATURE MANAGEMENT
    # ==========================================

    def assign_signers(
        self, doc_id: int, firmantes_ids: List[int]
    ) -> List[DocumentSigner]:
        """Assigns an ordered list of users who must sign a document in sequence.

        Moves document from 'borrador' to 'en_firma'.

        Args:
            doc_id (int): ID of the document.
            firmantes_ids (list of int): Ordered list of user IDs.

        Returns:
            List[DocumentSigner]: The newly assigned signers in order.

        Raises:
            ValidationError: If firmantes_ids is empty, exceeds 10, or contains duplicates.
            APIError: If the document is not in borrador, or one or more users are inactive/have no keys.
        """
        if not doc_id:
            raise ValidationError("El ID del documento es obligatorio.")
        if not isinstance(firmantes_ids, list) or not firmantes_ids:
            raise ValidationError(
                "Debe proporcionar una lista no vacía de IDs de firmantes."
            )

        payload = {"id": doc_id, "firmantes": firmantes_ids}
        response = self._request(
            "POST", "api/documentos-firmantes-asignar.php", json_data=payload
        )
        signers_data = response.json().get("data", {}).get("firmantes", [])
        return [DocumentSigner.from_dict(s) for s in signers_data]

    def list_signers(
        self, doc_id: int
    ) -> Tuple[List[DocumentSigner], Optional[int], Optional[int]]:
        """Lists assigned signers for a document and indicates the current signer turn.

        Args:
            doc_id (int): ID of the document.

        Returns:
            tuple:
                - List[DocumentSigner]: List of all signers.
                - Optional[int]: Next user ID whose turn it is to sign.
                - Optional[int]: Order index of next signature (1-indexed).
        """
        if not doc_id:
            raise ValidationError("El ID del documento es obligatorio.")

        response = self._request(
            "GET", "api/documentos-firmantes-list.php", params={"id": doc_id}
        )
        data = response.json().get("data", {})
        signers = [DocumentSigner.from_dict(s) for s in data.get("firmantes", [])]
        next_user = data.get("siguiente_usuario_id")
        next_orden = data.get("siguiente_orden")
        return signers, next_user, next_orden

    # ==========================================
    # CRYPTOGRAPHIC KEY REGISTRATION
    # ==========================================

    def get_key_info(self) -> Optional[KeyInfo]:
        """Gets cryptographic key registration details for the authenticated user.

        Returns:
            KeyInfo: Information about the registered key, or None if no keys exist.

        Raises:
            AuthError: If not logged in.
        """
        try:
            response = self._request("GET", "api/claves-info.php")
            data = response.json().get("data", {}).get("clave")
            if not data:
                return None
            return KeyInfo.from_dict(data)
        except APIError as e:
            if e.code == 404:
                return None
            raise e

    def register_public_key(self, public_key_hex: str) -> str:
        """Registers a user's compressed ECDSA P-256 public key (hex) on the server.

        Args:
            public_key_hex (str): The public key in hexadecimal format (66 characters).

        Returns:
            str: Fingerprint of the registered key.

        Raises:
            ValidationError: If public_key_hex does not match expected length (66 or 130 characters).
        """
        clean_key = public_key_hex.strip().lower()
        if len(clean_key) not in (66, 130):
            raise ValidationError(
                "La clave pública debe tener 66 (comprimida) o 130 (sin comprimir) caracteres hex."
            )

        response = self._request(
            "POST",
            "api/claves-registrar-publica.php",
            json_data={"public_key": clean_key},
        )
        return response.json().get("data", {}).get("fingerprint", "")

    # ==========================================
    # DIGITAL SIGNING FLOWS
    # ==========================================

    def solicitar_firma(self, doc_id: int) -> Dict[str, Any]:
        """Step 1: Solicits the document digest/hash to sign from the server.

        Ensures the document is in the proper state and it is the current user's turn.

        Args:
            doc_id (int): ID of the document.

        Returns:
            dict: Contains:
                - 'hash' (str): SHA-256 digest hex to sign.
                - 'session_id' (int): Signature session ID.
                - 'folio' (str): Document folio.
                - 'orden' (int): Signature order index.
                - 'es_multifirma' (bool): True if multi-sig.
        """
        if not doc_id:
            raise ValidationError("El ID del documento es obligatorio.")

        response = self._request(
            "POST", "api/documentos-solicitar-firma.php", json_data={"id": doc_id}
        )
        return response.json().get("data", {})

    def completar_firma(
        self, doc_id: int, signature_hex: str, session_id: int
    ) -> Dict[str, Any]:
        """Step 2: Submits the ECDSA signature to the server to complete or advance signing.

        Args:
            doc_id (int): ID of the document.
            signature_hex (str): 128-character raw compact signature hex.
            session_id (int): Signature session ID returned in Step 1.

        Returns:
            dict: Server response data (e.g. if the document is fully emitted or pending other signatures).
        """
        if not doc_id:
            raise ValidationError("El ID del documento es obligatorio.")
        if not signature_hex or len(signature_hex) != 128:
            raise ValidationError(
                "Firma inválida. Se espera una firma compacta de 128 caracteres hex."
            )
        if not session_id:
            raise ValidationError("El ID de sesión de firma es obligatorio.")

        payload = {
            "id": doc_id,
            "firma": signature_hex.strip().lower(),
            "session_id": session_id,
        }
        response = self._request(
            "POST", "api/documentos-completar-firma.php", json_data=payload
        )
        return response.json().get("data", {})

    def sign_document(self, doc_id: int, mnemonic: str) -> Dict[str, Any]:
        """High-level method that executes the full signing flow automatically.

        1. Requests signature details (hash) from the server.
        2. Locally derives the private key from the Spanish BIP39 mnemonic.
        3. Signs the hash locally using ECDSA P-256.
        4. Submits the signature back to the server to complete the process.

        Args:
            doc_id (int): ID of the document.
            mnemonic (str): 12-word Spanish mnemonic phrase.

        Returns:
            dict: Server response indicating emission state or next signer.

        Raises:
            ValidationError: If inputs are invalid.
            CryptographyError: If local signature generation fails.
            APIError: If communication or server validation fails.
        """
        # Step 1: Solicitar hash a firmar
        firm_info = self.solicitar_firma(doc_id)
        hash_hex = firm_info["hash"]
        session_id = firm_info["session_id"]

        # Step 2: Derivar llaves y firmar localmente
        try:
            keys = derivar_llaves(mnemonic)
            private_key_bytes = keys["private_key_bytes"]
            signature_hex = firmar_hash(hash_hex, private_key_bytes)
        except Exception as e:
            raise CryptographyError(f"Error local en el proceso criptográfico: {e}")

        # Step 3: Enviar firma al servidor
        return self.completar_firma(doc_id, signature_hex, session_id)

    # ==========================================
    # FILE DOWNLOAD & PUBLIC VERIFICATION
    # ==========================================

    def download_document(
        self, folio: str, dest_path: str, force_download: bool = True
    ) -> None:
        """Downloads the PDF file associated with a document folio.

        Args:
            folio (str): Document folio (e.g. 'DOC-20260428-A1B2C3').
            dest_path (str): Local destination file path to save the PDF.
            force_download (bool, optional): If True, downloads as an attachment. Defaults to True.

        Raises:
            ValidationError: If folio format is invalid.
            APIError: If download fails or the user is unauthorized (for drafts).
        """
        if not folio or not folio.startswith("DOC-"):
            raise ValidationError("Formato de folio inválido.")

        params = {"folio": folio, "download": "1" if force_download else "0"}

        # Request with stream=True
        response = self._request(
            "GET", "api/documentos-archivo.php", params=params, stream=True
        )

        try:
            with open(dest_path, "wb") as f:
                for chunk in response.iter_content(chunk_size=8192):
                    if chunk:
                        f.write(chunk)
        except IOError as e:
            raise ValidationError(f"Error escribiendo el PDF en disco: {e}")

    def verify_document(self, folio: str) -> DocumentVerificationResult:
        """Public verification of a document folio. Does not require authentication.

        Performs local validation of all cryptographic signatures in the chain.

        Args:
            folio (str): The document folio.

        Returns:
            DocumentVerificationResult: Comprehensive validation details.
        """
        if not folio:
            raise ValidationError("El folio es obligatorio.")

        response = self._request("GET", "api/consulta_qr.php", params={"token": folio})
        return DocumentVerificationResult.from_dict(response.json().get("data", {}))

    # ==========================================
    # ADMINISTRATIVE / USER MANAGEMENT
    # ==========================================

    def list_users(self) -> List[User]:
        """Lists all users in the system.

        Only accessible by administrators and supervisors.

        Returns:
            List[User]: List of system users.
        """
        response = self._request("GET", "api/usuarios-list.php")
        users_data = response.json().get("data", {}).get("usuarios", [])
        return [User.from_dict(u) for u in users_data]

    def create_user(self, nombre: str, email: str, password: str, rol: str) -> int:
        """Creates a new user in the system.

        Only accessible by administrators.

        Args:
            nombre (str): User name.
            email (str): Unique email address.
            password (str): Password (at least 6 characters).
            rol (str): User role (administrador, supervisor, emisor, verificador, consultor).

        Returns:
            int: The created user ID.
        """
        if not nombre.strip() or not email.strip() or not password or not rol:
            raise ValidationError(
                "Todos los campos (nombre, email, password, rol) son obligatorios."
            )
        if len(password) < 6:
            raise ValidationError("La contraseña debe tener al menos 6 caracteres.")

        payload = {"nombre": nombre, "email": email, "password": password, "rol": rol}
        response = self._request("POST", "api/usuarios-create.php", json_data=payload)
        return int(response.json().get("data", {}).get("usuario_id", 0))

    def change_user_role(self, usuario_id: int, rol: str) -> None:
        """Changes the role of an existing user.

        Only accessible by administrators.

        Args:
            usuario_id (int): The target user ID.
            rol (str): New role.
        """
        if not usuario_id or not rol:
            raise ValidationError("usuario_id y rol son obligatorios.")
        self._request(
            "POST",
            "api/usuarios-cambiar-rol.php",
            json_data={"usuario_id": usuario_id, "rol": rol},
        )

    def deactivate_user(self, usuario_id: int) -> None:
        """Deactivates a user (sets active to 0).

        Only accessible by administrators. Users cannot deactivate themselves.

        Args:
            usuario_id (int): The target user ID.
        """
        if not usuario_id:
            raise ValidationError("El usuario_id es obligatorio.")
        self._request(
            "POST", "api/usuarios-desactivar.php", json_data={"usuario_id": usuario_id}
        )

    # ==========================================
    # AUDITING & REPORTING
    # ==========================================

    def list_audit_logs(
        self, limit: int = 100, offset: int = 0
    ) -> Tuple[List[AuditLogEntry], int]:
        """Gets audit trails records from the database.

        Only accessible by administrators and supervisors.

        Args:
            limit (int, optional): Max logs to fetch (max 500). Defaults to 100.
            offset (int, optional): Pagination offset. Defaults to 0.

        Returns:
            tuple:
                - List[AuditLogEntry]: List of audit log records.
                - int: Total log entries in the database.
        """
        params = {"limit": limit, "offset": offset}
        response = self._request("GET", "api/bitacora-list.php", params=params)
        data = response.json().get("data", {})
        logs = [AuditLogEntry.from_dict(r) for r in data.get("registros", [])]
        total = int(data.get("total", 0))
        return logs, total

    def get_admin_summary(self) -> DashboardSummary:
        """Gets executive statistics and recent activity for the administration dashboard.

        Only accessible by administrators and supervisors.

        Returns:
            DashboardSummary: Executive counters, logs, and top activity.
        """
        response = self._request("GET", "api/resumen-admin.php")
        return DashboardSummary.from_dict(response.json().get("data", {}))
