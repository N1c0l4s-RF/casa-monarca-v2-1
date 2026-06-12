"""
Casa Monarca SDK - Custom Exceptions.

This module defines the exception hierarchy for the SDK, allowing developers to catch
specific errors that may occur during communication with the server or local cryptographic operations.
"""


class CasaMonarcaError(Exception):
    """Base exception for all errors raised by the Casa Monarca SDK."""

    def __init__(
        self, message: str, code: int = None, response_data: dict = None
    ) -> None:
        super().__init__(message)
        self.message = message
        self.code = code
        self.response_data = response_data

    def __str__(self) -> str:
        code_str = f" [Code {self.code}]" if self.code is not None else ""
        return f"{self.message}{code_str}"


class AuthError(CasaMonarcaError):
    """Raised when authentication fails or the session is invalid/expired."""

    pass


class APIError(CasaMonarcaError):
    """Raised when the server returns an error response (e.g. 4xx, 5xx) or invalid JSON."""

    pass


class ValidationError(CasaMonarcaError):
    """Raised when request parameters, file format, or mnemonic validation fails."""

    pass


class CryptographyError(CasaMonarcaError):
    """Raised when cryptographic key generation, derivation, or signing fails."""

    pass
