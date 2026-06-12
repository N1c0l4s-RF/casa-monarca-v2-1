"""
Casa Monarca SDK - Data Models.

Defines standard Python dataclasses representing the core domain entities
exchanged with the Casa Monarca API, providing type safety and IDE autocompletion.
"""

from dataclasses import dataclass
from datetime import datetime
from typing import Any, Dict, List, Optional


def _parse_date(val: Optional[str]) -> Optional[datetime]:
    """Helper to parse MySQL datetime strings to Python datetime objects."""
    if not val:
        return None
    try:
        # MySQL datetimes are usually "YYYY-MM-DD HH:MM:SS"
        return datetime.strptime(val, "%Y-%m-%d %H:%M:%S")
    except ValueError:
        try:
            # Fallback for ISO format
            return datetime.fromisoformat(val.replace("Z", "+00:00"))
        except ValueError:
            return None


@dataclass
class User:
    """Represents a Casa Monarca system user."""

    id: int
    nombre: str
    email: str
    rol: str
    activo: bool
    fecha_creacion: Optional[datetime] = None
    ultimo_login: Optional[datetime] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "User":
        """Creates a User instance from an API response dictionary."""
        return cls(
            id=int(data["id"]),
            nombre=data["nombre"],
            email=data["email"],
            rol=data["rol"],
            activo=bool(int(data["activo"]))
            if isinstance(data["activo"], (int, str))
            else bool(data["activo"]),
            fecha_creacion=_parse_date(data.get("fecha_creacion")),
            ultimo_login=_parse_date(data.get("ultimo_login")),
        )


@dataclass
class Document:
    """Represents a digital document inside Casa Monarca."""

    id: int
    folio: str
    titulo: str
    estado: str  # 'borrador', 'en_firma', 'emitido', 'revocado'
    creado_por: int
    ruta_archivo: Optional[str] = None
    descripcion: Optional[str] = None
    contenido: Optional[str] = None
    hash_sha256: Optional[str] = None
    firmado_por_usuario_id: Optional[int] = None
    fecha_creacion: Optional[datetime] = None
    fecha_actualizacion: Optional[datetime] = None
    fecha_emision: Optional[datetime] = None
    fecha_revocacion: Optional[datetime] = None
    motivo_revocacion: Optional[str] = None

    # Annotated extra properties from listing documents
    creador_nombre: Optional[str] = None
    firmado_por_nombre: Optional[str] = None
    total_firmantes: Optional[int] = None
    firmas_completadas: Optional[int] = None
    siguiente_usuario_id: Optional[int] = None
    siguiente_usuario_nombre: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "Document":
        """Creates a Document instance from an API response dictionary."""
        return cls(
            id=int(data["id"]),
            folio=data["folio"],
            titulo=data["titulo"],
            estado=data["estado"],
            creado_por=int(data["creado_por"]),
            ruta_archivo=data.get("ruta_archivo"),
            descripcion=data.get("descripcion"),
            contenido=data.get("contenido"),
            hash_sha256=data.get("hash_sha256"),
            firmado_por_usuario_id=int(data["firmado_por_usuario_id"])
            if data.get("firmado_por_usuario_id")
            else None,
            fecha_creacion=_parse_date(data.get("fecha_creacion")),
            fecha_actualizacion=_parse_date(data.get("fecha_actualizacion")),
            fecha_emision=_parse_date(data.get("fecha_emision")),
            fecha_revocacion=_parse_date(data.get("fecha_revocacion")),
            motivo_revocacion=data.get("motivo_revocacion"),
            creador_nombre=data.get("creador_nombre"),
            firmado_por_nombre=data.get("firmado_por_nombre"),
            total_firmantes=int(data["total_firmantes"])
            if data.get("total_firmantes") is not None
            else None,
            firmas_completadas=int(data["firmas_completadas"])
            if data.get("firmas_completadas") is not None
            else None,
            siguiente_usuario_id=int(data["siguiente_usuario_id"])
            if data.get("siguiente_usuario_id")
            else None,
            siguiente_usuario_nombre=data.get("siguiente_usuario_nombre"),
        )


@dataclass
class DocumentSigner:
    """Represents a signer mapped to a document in multi-signature flows."""

    id: int
    usuario_id: int
    orden: int
    estado: str  # 'pendiente', 'firmado'
    nombre: str
    email: str
    rol: str
    firma: Optional[str] = None
    fecha_firma: Optional[datetime] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "DocumentSigner":
        """Creates a DocumentSigner instance from an API response dictionary."""
        return cls(
            id=int(data["id"]),
            usuario_id=int(data["usuario_id"]),
            orden=int(data["orden"]),
            estado=data["estado"],
            nombre=data["nombre"],
            email=data["email"],
            rol=data["rol"],
            firma=data.get("firma"),
            fecha_firma=_parse_date(data.get("fecha_firma")),
        )


@dataclass
class AuditLogEntry:
    """Represents an audit trail event from the database."""

    id: int
    usuario_id: Optional[int]
    accion: str
    modulo: str
    fecha: datetime
    documento_id: Optional[int] = None
    documento_folio: Optional[str] = None
    descripcion: Optional[str] = None
    ip_address: Optional[str] = None
    user_agent: Optional[str] = None
    resultado: str = "success"  # 'success', 'failed'
    motivo_fallo: Optional[str] = None
    usuario_nombre: Optional[str] = None
    usuario_email: Optional[str] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "AuditLogEntry":
        """Creates an AuditLogEntry instance from an API response dictionary."""
        return cls(
            id=int(data["id"]),
            usuario_id=int(data["usuario_id"]) if data.get("usuario_id") else None,
            accion=data["accion"],
            modulo=data["modulo"],
            fecha=_parse_date(data["fecha"]) or datetime.now(),
            documento_id=int(data["documento_id"])
            if data.get("documento_id")
            else None,
            documento_folio=data.get("documento_folio"),
            descripcion=data.get("descripcion"),
            ip_address=data.get("ip_address"),
            user_agent=data.get("user_agent"),
            resultado=data.get("resultado", "success"),
            motivo_fallo=data.get("motivo_fallo"),
            usuario_nombre=data.get("usuario_nombre"),
            usuario_email=data.get("usuario_email"),
        )


@dataclass
class KeyInfo:
    """Represents information about registered user cryptographic keys."""

    version: int
    activo: bool
    fingerprint: str
    created_at: datetime
    download_count: int
    last_downloaded_at: Optional[datetime] = None

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "KeyInfo":
        """Creates a KeyInfo instance from an API response dictionary."""
        return cls(
            version=int(data["version"]),
            activo=bool(int(data["activo"]))
            if isinstance(data["activo"], (int, str))
            else bool(data["activo"]),
            fingerprint=data["fingerprint"],
            created_at=_parse_date(data["created_at"]) or datetime.now(),
            download_count=int(data["download_count"]),
            last_downloaded_at=_parse_date(data.get("last_downloaded_at")),
        )


@dataclass
class DashboardSummary:
    """Aggregates executive stats from the administrator summary endpoint."""

    total_documentos: int
    emitidos: int
    borradores: int
    revocados: int
    emitidos_hoy: int
    revocados_hoy: int
    top_firmantes: List[Dict[str, Any]]
    firmas_fallidas: int
    firmas_fallidas_hoy: int
    actividad_semana: List[Dict[str, Any]]
    eventos: List[AuditLogEntry]

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "DashboardSummary":
        """Creates a DashboardSummary instance from an API response dictionary."""
        docs_stats = data.get("documentos") or {}
        event_list = [AuditLogEntry.from_dict(e) for e in data.get("eventos", [])]

        return cls(
            total_documentos=int(docs_stats.get("total", 0)),
            emitidos=int(docs_stats.get("emitidos", 0) or 0),
            borradores=int(docs_stats.get("borradores", 0) or 0),
            revocados=int(docs_stats.get("revocados", 0) or 0),
            emitidos_hoy=int(docs_stats.get("emitidos_hoy", 0) or 0),
            revocados_hoy=int(docs_stats.get("revocados_hoy", 0) or 0),
            top_firmantes=data.get("top_firmantes", []),
            firmas_fallidas=int(data.get("firmas_fallidas", 0)),
            firmas_fallidas_hoy=int(data.get("firmas_fallidas_hoy", 0)),
            actividad_semana=data.get("actividad_semana", []),
            eventos=event_list,
        )


@dataclass
class VerificationDetail:
    """Represents a single signature verification in a chain."""

    orden: int
    firmante: str
    rol: str
    fecha_firma: Optional[datetime]
    firma_valida: bool

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "VerificationDetail":
        """Creates a VerificationDetail instance from a dictionary."""
        return cls(
            orden=int(data["orden"]),
            firmante=data["firmante"],
            rol=data["rol"],
            fecha_firma=_parse_date(data.get("fecha_firma")),
            firma_valida=bool(data["firma_valida"]),
        )


@dataclass
class DocumentVerificationResult:
    """Represents the public verification status of a document."""

    folio: str
    titulo: str
    estado: str
    creador: str
    firmante: Optional[str]
    fecha_creacion: Optional[datetime]
    fecha_emision: Optional[datetime]
    fecha_revocacion: Optional[datetime]
    motivo_revocacion: Optional[str]
    es_multifirma: bool
    firma_valida: bool
    mensaje: str
    firmas: List[VerificationDetail]

    @classmethod
    def from_dict(cls, data: Dict[str, Any]) -> "DocumentVerificationResult":
        """Creates a DocumentVerificationResult instance from an API response dictionary."""
        doc_data = data.get("documento") or {}
        verif = data.get("verificacion") or {}

        firmas_list = [VerificationDetail.from_dict(f) for f in verif.get("firmas", [])]

        return cls(
            folio=doc_data.get("folio", ""),
            titulo=doc_data.get("titulo", ""),
            estado=doc_data.get("estado", ""),
            creador=doc_data.get("creador", ""),
            firmante=doc_data.get("firmante"),
            fecha_creacion=_parse_date(doc_data.get("fecha_creacion")),
            fecha_emision=_parse_date(doc_data.get("fecha_emision")),
            fecha_revocacion=_parse_date(doc_data.get("fecha_revocacion")),
            motivo_revocacion=doc_data.get("motivo_revocacion"),
            es_multifirma=bool(doc_data.get("es_multifirma")),
            firma_valida=bool(verif.get("firma_valida")),
            mensaje=verif.get("mensaje", ""),
            firmas=firmas_list,
        )
