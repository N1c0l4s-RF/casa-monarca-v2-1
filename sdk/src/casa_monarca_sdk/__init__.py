"""
Casa Monarca Python SDK.

An hyper-complete, production-ready, modular Python SDK wrapping the Casa Monarca digital signature API,
with local Spanish BIP39 key generation and ECDSA P-256 signature capabilities.
"""

__version__ = "1.0.0"

from casa_monarca_sdk.client import CasaMonarcaClient
from casa_monarca_sdk.crypto import (
    derivar_llaves,
    firmar_hash,
    generar_mnemonic,
    validar_mnemonic,
    verificar_firma_local,
)
from casa_monarca_sdk.exceptions import (
    APIError,
    AuthError,
    CasaMonarcaError,
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
    VerificationDetail,
)

__all__ = [
    "CasaMonarcaClient",
    "CasaMonarcaError",
    "AuthError",
    "APIError",
    "ValidationError",
    "CryptographyError",
    "generar_mnemonic",
    "validar_mnemonic",
    "derivar_llaves",
    "firmar_hash",
    "verificar_firma_local",
    "User",
    "Document",
    "DocumentSigner",
    "AuditLogEntry",
    "KeyInfo",
    "DashboardSummary",
    "DocumentVerificationResult",
    "VerificationDetail",
]
