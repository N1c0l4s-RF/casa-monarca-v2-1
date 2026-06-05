/**
 * KeyManager — Casa Monarca v2
 *
 * Gestión de llaves ECDSA P-256 completamente en el browser.
 * El servidor NUNCA recibe ni almacena la clave privada.
 *
 * Dependencia: @noble/curves (ECDSA P-256 puro en JS)
 * CDN: https://esm.sh/@noble/curves@1.4.0/p256
 *
 * Flujo:
 *   1. generarMnemonic()        → 12 palabras (guardar físicamente)
 *   2. derivarLlaves(mnemonic)  → { publicKeyHex, privateKeyBytes }
 *   3. registrarClavePublica()  → envía solo la clave pública al servidor
 *   4. firmarDocumento()        → solicita hash al servidor, firma localmente, envía firma
 */

import { mnemonicToSeed, validarMnemonic } from './bip39.js';
import { p256 } from 'https://esm.sh/@noble/curves@1.4.0/p256';

async function apiFetch(path, options = {}) {
  const res = await fetch(path, {
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    credentials: 'include',
    ...options,
  });
  const text = await res.text();
  let data = null;
  if (text) {
    try { data = JSON.parse(text); } catch {
      console.error(`apiFetch (keymanager): respuesta no-JSON de ${path}`, { status: res.status, body: text.slice(0, 300) });
      throw new Error(`El servidor respondió ${res.status} con un mensaje no esperado al ${options.method === 'POST' ? 'firmar' : 'consultar'}. Revisa la consola.`);
    }
  }
  if (!res.ok) throw new Error(data?.message || `Error en la solicitud (${res.status})`);
  return data;
}

/**
 * Deriva el par de llaves ECDSA P-256 desde un mnemonic BIP39.
 * Determinista: la misma frase siempre produce las mismas llaves.
 *
 * @returns {{ privateKeyBytes: Uint8Array, publicKeyHex: string, publicKeyBytes: Uint8Array }}
 */
export async function derivarLlaves(mnemonic) {
  if (!validarMnemonic(mnemonic)) {
    throw new Error('Mnemonic inválido: verifica las 12 palabras');
  }

  const seed = await mnemonicToSeed(mnemonic);

  // Los primeros 32 bytes del seed son la clave privada ECDSA P-256
  const privateKeyBytes = seed.slice(0, 32);

  // Derivar clave pública (punto en la curva, comprimido: 33 bytes)
  const publicKeyBytes = p256.getPublicKey(privateKeyBytes, true);
  const publicKeyHex = bytesToHex(publicKeyBytes);

  return { privateKeyBytes, publicKeyBytes, publicKeyHex };
}

/**
 * Registra la clave pública del usuario en el servidor.
 * Solo la clave pública viaja a la red. La privada nunca sale del browser.
 */
export async function registrarClavePublica(publicKeyHex) {
  return apiFetch('/api/claves-registrar-publica.php', {
    method: 'POST',
    body: JSON.stringify({ public_key: publicKeyHex }),
  });
}

/**
 * Flujo completo de emisión de documento:
 *   1. Servidor calcula SHA-256(folio|contenido) y lo devuelve
 *   2. Browser firma el hash con ECDSA P-256
 *   3. Firma (hex) va al servidor para guardar y verificar
 *
 * @param {number} documentoId
 * @param {string} mnemonic — las 12 palabras del usuario
 */
export async function firmarDocumento(documentoId, mnemonic) {
  if (!validarMnemonic(mnemonic)) {
    throw new Error('Mnemonic inválido');
  }

  // 1. Solicitar hash al servidor (guarda en firma_sessions DB)
  const paso1 = await apiFetch('/api/documentos-solicitar-firma.php', {
    method: 'POST',
    body: JSON.stringify({ id: documentoId }),
  });
  const hashHex  = paso1.data.hash;
  const sessionId = paso1.data.session_id;

  // 2. Derivar clave privada desde mnemonic
  const { privateKeyBytes } = await derivarLlaves(mnemonic);

  // 3. Firmar el hash con ECDSA P-256
  const hashBytes = hexToBytes(hashHex);
  const signature = p256.sign(hashBytes, privateKeyBytes);
  const firmaHex = bytesToHex(signature.toCompactRawBytes());

  // 4. Enviar firma + session_id al servidor
  return apiFetch('/api/documentos-completar-firma.php', {
    method: 'POST',
    body: JSON.stringify({ id: documentoId, firma: firmaHex, session_id: sessionId }),
  });
}

/**
 * Verifica localmente que una firma es válida (útil para tests en browser).
 */
export function verificarFirmaLocal(hashHex, firmaHex, publicKeyHex) {
  try {
    const hashBytes = hexToBytes(hashHex);
    const firmaBytes = hexToBytes(firmaHex);
    const pubKeyBytes = hexToBytes(publicKeyHex);
    return p256.verify(firmaBytes, hashBytes, pubKeyBytes);
  } catch {
    return false;
  }
}

// ─── Utilidades ────────────────────────────────────────────────────────────────

export function bytesToHex(bytes) {
  return Array.from(bytes).map(b => b.toString(16).padStart(2, '0')).join('');
}

export function hexToBytes(hex) {
  const bytes = new Uint8Array(hex.length / 2);
  for (let i = 0; i < bytes.length; i++) {
    bytes[i] = parseInt(hex.slice(i * 2, i * 2 + 2), 16);
  }
  return bytes;
}
