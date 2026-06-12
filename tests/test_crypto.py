import pytest
import hashlib
from casa_monarca_sdk.crypto import (
    generar_mnemonic,
    validar_mnemonic,
    mnemonic_to_seed,
    derivar_llaves,
    firmar_hash,
    verificar_firma_local,
)
from casa_monarca_sdk.exceptions import ValidationError


def test_generar_mnemonic():
    """Valida la generación de un mnemónico de 12 palabras y su validación básica."""
    mnemonic = generar_mnemonic()
    assert isinstance(mnemonic, str)
    words = mnemonic.split()
    assert len(words) == 12
    # El mnemónico generado debe ser estructuralmente válido
    assert validar_mnemonic(mnemonic) is True


def test_validar_mnemonic_invalid():
    """Prueba casos de mnemónicos no válidos por longitud o contenido."""
    # Longitud incorrecta
    assert validar_mnemonic("hola") is False
    assert validar_mnemonic(" ".join(["abaco"] * 11)) is False
    assert validar_mnemonic(" ".join(["abaco"] * 13)) is False
    # Palabras inexistentes en el diccionario
    assert validar_mnemonic(" ".join(["palabranoexiste"] * 12)) is False


def test_mnemonic_to_seed():
    """Valida la conversión de un mnemónico válido a su correspondiente seed de 64 bytes."""
    valid_mnemonic = "ábaco abdomen abeja abierto abogado abono aborto abrazo abrir abuelo abuso acabar"
    seed = mnemonic_to_seed(valid_mnemonic)
    assert isinstance(seed, bytes)
    assert len(seed) == 64

    # Mnemónico inválido levanta ValidationError
    with pytest.raises(ValidationError):
        mnemonic_to_seed(" ".join(["invalida"] * 12))


def test_derivar_llaves():
    """Valida la derivación correcta del par de llaves ECDSA P-256 comprimidas."""
    valid_mnemonic = "ábaco abdomen abeja abierto abogado abono aborto abrazo abrir abuelo abuso acabar"
    keys = derivar_llaves(valid_mnemonic)

    assert "private_key_bytes" in keys
    assert "public_key_bytes" in keys
    assert "public_key_hex" in keys

    assert len(keys["private_key_bytes"]) == 32
    assert len(keys["public_key_bytes"]) == 33  # Public key comprimida ECDSA P-256
    assert len(keys["public_key_hex"]) == 66  # Representación hex (33 bytes * 2)

    with pytest.raises(ValidationError):
        derivar_llaves(" ".join(["invalida"] * 12))


def test_ciclo_firma_y_verificacion_compacta():
    """Prueba el ciclo de firma y verificación compacta de 64 bytes."""
    mnemonic = generar_mnemonic()
    keys = derivar_llaves(mnemonic)

    priv_bytes = keys["private_key_bytes"]
    pub_hex = keys["public_key_hex"]

    # Generar un hash SHA-256 ficticio (32 bytes = 64 caracteres hexadecimales)
    mensaje = b"Casa Monarca SDK Test Message"
    hash_hex = hashlib.sha256(mensaje).hexdigest()

    # Firmar hash
    signature_hex = firmar_hash(hash_hex, priv_bytes)
    assert isinstance(signature_hex, str)
    assert len(signature_hex) == 128  # Firma compacta de 64 bytes (r + s) en hex

    # Verificar firma válida
    assert verificar_firma_local(hash_hex, signature_hex, pub_hex) is True

    # Verificar falla con hash modificado
    otro_hash = hashlib.sha256(b"Otro mensaje").hexdigest()
    assert verificar_firma_local(otro_hash, signature_hex, pub_hex) is False

    # Verificar falla con firma modificada
    firma_invalida = signature_hex[:-2] + "00"
    assert verificar_firma_local(hash_hex, firma_invalida, pub_hex) is False

    # Verificar falla con llave pública de otro mnemónico
    otro_mnemonic = generar_mnemonic()
    otra_pub_hex = derivar_llaves(otro_mnemonic)["public_key_hex"]
    assert verificar_firma_local(hash_hex, signature_hex, otra_pub_hex) is False


def test_firmar_hash_entradas_invalidas():
    """Valida el comportamiento del método de firma ante entradas corruptas o incorrectas."""
    mnemonic = generar_mnemonic()
    keys = derivar_llaves(mnemonic)
    priv_bytes = keys["private_key_bytes"]

    # Hash no hexadecimal de tamaño incorrecto
    with pytest.raises(ValidationError):
        firmar_hash("hashinvalido", priv_bytes)

    # Hash de tamaño inválido (menor a 64 hex caracteres)
    with pytest.raises(ValidationError):
        firmar_hash("a" * 62, priv_bytes)

    # Hash de tamaño inválido (mayor a 64 hex caracteres)
    with pytest.raises(ValidationError):
        firmar_hash("a" * 66, priv_bytes)
